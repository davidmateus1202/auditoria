<?php

declare(strict_types=1);

namespace App\Services\Cortes;

use App\Domain\Enums\EstadoCorte;
use App\Domain\Enums\EstadoHallazgo;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Abre, alimenta y cierra un mes de seguimiento.
 *
 * Al abrir, todos los hallazgos vigentes entran al mes arrastrando el estado
 * del corte anterior y marcados como AÚN NO REPORTADOS. Al cerrar, los que
 * nadie tocó conservan su estado y quedan señalados: un hallazgo que
 * desaparece de la matriz de un mes casi nunca es un problema resuelto, es una
 * fila que se quedó sin digitar.
 */
final class ServicioCorte
{
    public function abrir(string $periodo): Corte
    {
        $this->exigirPeriodoValido($periodo);

        if (Corte::query()->where('periodo', $periodo)->exists()) {
            throw new RuntimeException("El corte {$periodo} ya existe.");
        }

        return DB::transaction(function () use ($periodo): Corte {
            $anterior = Corte::ultimoCerrado();

            $corte = Corte::create([
                'periodo' => $periodo,
                'fecha_corte' => $periodo.'-01',
                'estado' => EstadoCorte::Abierto,
            ]);

            $this->arrastrarVigentes($corte, $anterior);

            return $corte;
        });
    }

    /**
     * Congela la foto del mes.
     *
     * A partir de aquí el consolidado de ese periodo es reproducible: el de
     * marzo sigue dando lo mismo aunque en abril se cierren veinte hallazgos.
     */
    public function cerrar(string $periodo, ?User $usuario = null): Corte
    {
        $corte = $this->exigirCorte($periodo);

        if (! $corte->admiteEscritura()) {
            throw new RuntimeException("El corte {$periodo} ya está cerrado.");
        }

        return DB::transaction(function () use ($corte, $usuario): Corte {
            foreach ($corte->estados()->with('hallazgo')->get() as $foto) {
                // La caché del hallazgo pasa a ser la del último corte cerrado.
                $foto->hallazgo?->update(['estado' => $foto->estado]);
            }

            $corte->update([
                'estado' => EstadoCorte::Cerrado,
                'cerrado_por' => $usuario?->id,
                'cerrado_en' => now(),
            ]);

            return $corte->fresh();
        });
    }

    /**
     * Reabrir un mes rompe la reproducibilidad de los consolidados ya
     * entregados, así que exige motivo y queda registrado.
     */
    public function reabrir(string $periodo, string $motivo, ?User $usuario = null): Corte
    {
        $corte = $this->exigirCorte($periodo);

        if (trim($motivo) === '') {
            throw new RuntimeException('Reabrir un corte cerrado exige un motivo: los consolidados de ese mes dejan de ser reproducibles.');
        }

        $corte->update([
            'estado' => EstadoCorte::Abierto,
            'motivo_reapertura' => $motivo,
            'cerrado_por' => $usuario?->id ?? $corte->cerrado_por,
        ]);

        return $corte->fresh();
    }

    /** Lo que se ve en la pantalla del mes: qué falta por reportar. */
    public function estado(string $periodo): array
    {
        $corte = $this->exigirCorte($periodo);

        $fotos = $corte->estados()->with('hallazgo.sede')->get();
        $porSede = [];

        foreach ($fotos as $foto) {
            $codigo = $foto->hallazgo?->sede?->codigo ?? '—';
            $porSede[$codigo] ??= ['total' => 0, 'reportados' => 0, 'pendientes' => 0];
            $porSede[$codigo]['total']++;

            if ($foto->presente_en_corte) {
                $porSede[$codigo]['reportados']++;
            } else {
                $porSede[$codigo]['pendientes']++;
            }
        }

        ksort($porSede);

        return [
            'periodo' => $corte->periodo,
            'estado' => $corte->estado->value,
            'hallazgos' => $fotos->count(),
            'reportados' => $fotos->where('presente_en_corte', true)->count(),
            'pendientes' => $fotos->where('presente_en_corte', false)->count(),
            'por_sede' => $porSede,
        ];
    }

    /**
     * Suma al corte abierto los hallazgos vigentes de una sede que todavía no
     * tenían seguimiento ahí.
     *
     * Abrir un corte es una foto de un momento; una autoevaluación que se
     * confirma después no debería quedar invisible en el consolidado del mes
     * solo por haber llegado tarde. Sin esto, cada sede que se sube después
     * de abrir el mes desaparece del consolidado aunque esté publicada.
     */
    public function alcanzarSede(int $sedeId): void
    {
        $corte = Corte::abierto();

        if ($corte === null) {
            return;
        }

        $this->arrastrarVigentes($corte, Corte::ultimoCerrado(), soloSedeId: $sedeId);
    }

    /**
     * Trae al mes nuevo todos los hallazgos que siguen exigiendo gestión, con
     * el estado que traían y sin marcar: nadie ha reportado nada todavía.
     *
     * Excluye lo que el corte ya tenía trackeado: al abrir un mes eso siempre
     * está vacío, pero cuando una sede se suma tarde (alcanzarSede) puede que
     * parte de sus hallazgos ya estuvieran, de una carga anterior.
     */
    private function arrastrarVigentes(Corte $corte, ?Corte $anterior, ?int $soloSedeId = null): void
    {
        $previos = $anterior === null
            ? collect()
            : $anterior->estados()->get()->keyBy('hallazgo_id');

        $yaTrackeados = $corte->estados()->pluck('hallazgo_id');

        Hallazgo::query()->vigentes()
            ->when($soloSedeId !== null, fn ($q) => $q->where('sede_id', $soloSedeId))
            ->whereNotIn('id', $yaTrackeados)
            ->chunkById(200, function ($hallazgos) use ($corte, $previos): void {
            $filas = [];

            foreach ($hallazgos as $hallazgo) {
                $previo = $previos->get($hallazgo->id);
                $estado = $previo?->estado ?? $hallazgo->estado;

                $filas[] = [
                    'hallazgo_id' => $hallazgo->id,
                    'corte_id' => $corte->id,
                    'estado' => $estado->value,
                    'presente_en_corte' => false,
                    'meses_abierto' => ($previo?->meses_abierto ?? 0) + 1,
                    'accion_propuesta' => $previo->accion_propuesta ?? $hallazgo->accion_propuesta,
                    'responsable' => $previo->responsable ?? $hallazgo->responsable,
                    'evidencia' => $previo->evidencia ?? $hallazgo->evidencia,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($filas !== []) {
                HallazgoEstadoCorte::insert($filas);
            }
        });
    }

    private function exigirCorte(string $periodo): Corte
    {
        return Corte::query()->where('periodo', $periodo)->first()
            ?? throw new RuntimeException("No existe el corte {$periodo}.");
    }

    private function exigirPeriodoValido(string $periodo): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException("El periodo «{$periodo}» no tiene el formato AAAA-MM.");
        }
    }

    /** Estado vigente de un hallazgo, sin importar si hay corte abierto. */
    public static function estadoVigente(Hallazgo $hallazgo): EstadoHallazgo
    {
        $abierto = Corte::abierto();

        if ($abierto === null) {
            return $hallazgo->estado;
        }

        return HallazgoEstadoCorte::query()
            ->where('corte_id', $abierto->id)
            ->where('hallazgo_id', $hallazgo->id)
            ->value('estado')
            ?? $hallazgo->estado;
    }
}
