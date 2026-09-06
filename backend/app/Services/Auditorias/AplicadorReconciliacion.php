<?php

declare(strict_types=1);

namespace App\Services\Auditorias;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\TextoNormalizador;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\HallazgoAparicion;
use App\Models\Reconciliacion;
use App\Models\Seguimiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte la propuesta del reconciliador en cambios firmes.
 *
 * Los destinos que no exigen confirmación se aplican solos; los que sí, no se
 * aplican hasta que llegue una decisión explícita. Confirmar una auditoría con
 * decisiones pendientes falla a propósito: cerrar un hallazgo porque un archivo
 * dejó de mencionarlo es la clase de cosa que tiene que firmar una persona.
 */
final class AplicadorReconciliacion
{
    /** Acción por destino que exige confirmación. */
    private const ACCIONES = [
        'candidato_cierre' => ['cerrar', 'mantener'],
        'reincidencia' => ['reabrir', 'nuevo'],
        'revision' => ['vincular', 'nuevo', 'descartar'],
        'conflicto' => ['conservar_app', 'tomar_excel'],
    ];

    /**
     * @param  array<int, array{accion:string, hallazgo_id?:int, evidencia?:string}>  $decisiones
     *         indexado por id de reconciliación
     * @return array<string,int>
     */
    public function confirmar(Auditoria $auditoria, array $decisiones, ?User $usuario = null): array
    {
        $pendientes = Reconciliacion::query()
            ->where('auditoria_id', $auditoria->id)
            ->where('resuelto', false)
            ->get();

        $this->exigirDecisionesCompletas($pendientes, $decisiones);

        return DB::transaction(function () use ($auditoria, $pendientes, $decisiones, $usuario): array {
            $conteo = ['persiste' => 0, 'nuevos' => 0, 'cerrados' => 0, 'reabiertos' => 0, 'sin_verificar' => 0];

            foreach ($pendientes as $movimiento) {
                $decision = $decisiones[$movimiento->id] ?? null;

                match ($movimiento->destino) {
                    DestinoReconciliacion::Persiste => $this->persistir($auditoria, $movimiento, $conteo),
                    DestinoReconciliacion::Nuevo => $this->crear($auditoria, $movimiento, $conteo),
                    DestinoReconciliacion::NoVerificado => $this->marcarSinVerificar($movimiento, $conteo),
                    DestinoReconciliacion::CandidatoCierre => $this->resolverCierre($movimiento, $decision, $usuario, $conteo),
                    DestinoReconciliacion::Reincidencia => $this->resolverReincidencia($auditoria, $movimiento, $decision, $usuario, $conteo),
                    DestinoReconciliacion::Revision => $this->resolverRevision($auditoria, $movimiento, $decision, $conteo),
                    default => null,
                };

                $movimiento->update([
                    'resuelto' => true,
                    'resuelto_por' => $usuario?->id,
                    'resuelto_en' => now(),
                ]);
            }

            $auditoria->update(['estado' => 'publicada']);

            return $conteo;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Reconciliacion>  $pendientes
     * @param  array<int, array{accion:string}>  $decisiones
     */
    private function exigirDecisionesCompletas($pendientes, array $decisiones): void
    {
        $faltantes = [];

        foreach ($pendientes as $movimiento) {
            if (! $movimiento->destino->exigeConfirmacion()) {
                continue;
            }

            $accion = $decisiones[$movimiento->id]['accion'] ?? null;
            $validas = self::ACCIONES[$movimiento->destino->value] ?? [];

            if ($accion === null) {
                $faltantes[] = sprintf('#%d (%s)', $movimiento->id, $movimiento->destino->etiqueta());

                continue;
            }

            if (! in_array($accion, $validas, true)) {
                throw new RuntimeException(sprintf(
                    'La acción «%s» no vale para un %s. Use: %s.',
                    $accion,
                    mb_strtolower($movimiento->destino->etiqueta()),
                    implode(', ', $validas),
                ));
            }
        }

        if ($faltantes !== []) {
            throw new RuntimeException(sprintf(
                'Quedan %d decisiones sin tomar: %s. Nada se aplica hasta resolverlas.',
                count($faltantes),
                implode(', ', array_slice($faltantes, 0, 6)).(count($faltantes) > 6 ? '…' : ''),
            ));
        }
    }

    private function persistir(Auditoria $auditoria, Reconciliacion $movimiento, array &$conteo): void
    {
        $hallazgo = $movimiento->hallazgo;

        if ($hallazgo === null) {
            return;
        }

        $this->registrarAparicion($auditoria, $hallazgo, $movimiento);
        $hallazgo->increment('veces_reportado');
        $conteo['persiste']++;
    }

    private function crear(Auditoria $auditoria, Reconciliacion $movimiento, array &$conteo): void
    {
        $texto = (string) $movimiento->texto_entrante;

        if (trim($texto) === '') {
            return;
        }

        $hallazgo = Hallazgo::create([
            'sede_id' => $auditoria->sede_id,
            'estandar_codigo' => $this->estandarDe($movimiento),
            'auditoria_origen_id' => $auditoria->id,
            'descripcion' => $texto,
            'huella' => sha1(implode('|', [
                TextoNormalizador::canonica($auditoria->sede->codigo),
                $this->estandarDe($movimiento),
                TextoNormalizador::canonica($texto),
            ])),
            'clasificacion' => ClasificacionHallazgo::Hallazgo,
            'estado' => EstadoHallazgo::Abierto,
        ]);

        $this->registrarAparicion($auditoria, $hallazgo, $movimiento);
        $conteo['nuevos']++;
    }

    /**
     * El estándar no viaja en la fila de reconciliación, así que se toma del
     * hallazgo vinculado o, para los nuevos, de la evaluación de la auditoría.
     */
    private function estandarDe(Reconciliacion $movimiento): string
    {
        if ($movimiento->hallazgo !== null) {
            return $movimiento->hallazgo->estandar_codigo;
        }

        return $movimiento->estandar_codigo ?? 'E2';
    }

    private function marcarSinVerificar(Reconciliacion $movimiento, array &$conteo): void
    {
        // Sigue abierto: que el estándar no se auditara no es un logro, es una
        // alerta de cobertura.
        $movimiento->hallazgo?->update(['requiere_revision' => true]);
        $conteo['sin_verificar']++;
    }

    private function resolverCierre(
        Reconciliacion $movimiento,
        ?array $decision,
        ?User $usuario,
        array &$conteo,
    ): void {
        if (($decision['accion'] ?? '') !== 'cerrar') {
            return;
        }

        $hallazgo = $movimiento->hallazgo;
        $evidencia = trim((string) ($decision['evidencia'] ?? ''));

        if ($hallazgo === null) {
            return;
        }

        if ($evidencia === '') {
            throw new RuntimeException(
                'Cerrar un hallazgo exige registrar con qué evidencia se cerró. '.
                'Sin constancia, el consolidado no se puede defender.'
            );
        }

        $anterior = $hallazgo->estado;

        $hallazgo->update([
            'estado' => EstadoHallazgo::Cerrado,
            'evidencia' => $evidencia,
            'cerrado_en' => now()->toDateString(),
            'cerrado_por' => $usuario?->id,
        ]);

        Seguimiento::create([
            'hallazgo_id' => $hallazgo->id,
            'usuario_id' => $usuario?->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => EstadoHallazgo::Cerrado,
            'evidencia' => $evidencia,
            'motivo' => 'El hallazgo ya no aparece en la auditoría nueva.',
        ]);

        $conteo['cerrados']++;
    }

    private function resolverReincidencia(
        Auditoria $auditoria,
        Reconciliacion $movimiento,
        ?array $decision,
        ?User $usuario,
        array &$conteo,
    ): void {
        if (($decision['accion'] ?? '') === 'nuevo') {
            $this->crear($auditoria, $movimiento, $conteo);

            return;
        }

        $hallazgo = $movimiento->hallazgo;

        if ($hallazgo === null) {
            return;
        }

        $anterior = $hallazgo->estado;

        $hallazgo->update([
            'estado' => EstadoHallazgo::Abierto,
            'cerrado_en' => null,
            'cerrado_por' => null,
        ]);
        $hallazgo->increment('veces_reportado');

        Seguimiento::create([
            'hallazgo_id' => $hallazgo->id,
            'usuario_id' => $usuario?->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => EstadoHallazgo::Abierto,
            'motivo' => 'Reincidencia: el problema figuraba cerrado y volvió a reportarse.',
        ]);

        $this->registrarAparicion($auditoria, $hallazgo, $movimiento);
        $conteo['reabiertos']++;
    }

    private function resolverRevision(
        Auditoria $auditoria,
        Reconciliacion $movimiento,
        ?array $decision,
        array &$conteo,
    ): void {
        $accion = $decision['accion'] ?? 'descartar';

        if ($accion === 'nuevo') {
            $this->crear($auditoria, $movimiento, $conteo);

            return;
        }

        if ($accion !== 'vincular') {
            return;
        }

        $hallazgo = Hallazgo::find($decision['hallazgo_id'] ?? 0);

        if ($hallazgo === null || $hallazgo->sede_id !== $auditoria->sede_id) {
            throw new RuntimeException(
                'El hallazgo elegido para vincular no existe o es de otra sede.'
            );
        }

        $this->registrarAparicion($auditoria, $hallazgo, $movimiento, manual: true);
        $hallazgo->increment('veces_reportado');
        $conteo['persiste']++;
    }

    private function registrarAparicion(
        Auditoria $auditoria,
        Hallazgo $hallazgo,
        Reconciliacion $movimiento,
        bool $manual = false,
    ): void {
        HallazgoAparicion::updateOrCreate(
            ['hallazgo_id' => $hallazgo->id, 'auditoria_id' => $auditoria->id],
            [
                'texto_reportado' => (string) ($movimiento->texto_entrante ?? $hallazgo->descripcion),
                'similitud' => $movimiento->similitud,
                'vinculo' => $manual ? 'manual' : 'auto',
            ],
        );
    }
}
