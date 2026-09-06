<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Domain\Reconciliacion\Dto\HallazgoVigente;
use App\Domain\Reconciliacion\Dto\Movimiento;
use App\Domain\Reconciliacion\Dto\Propuesta;

/**
 * Flujo A — ¿qué sigue vigente y qué se cierra?
 *
 * Al cargar la autoevaluación de una sede, sus hallazgos se enfrentan contra
 * los que esa misma sede tenía abiertos. Responde «¿el problema todavía
 * existe?», que es una pregunta que solo puede contestar una visita en terreno.
 *
 * Dos reglas gobiernan todo lo demás:
 *
 *  1. Se compara SOLO dentro de la misma sede y el mismo estándar. El bloqueo
 *     por sede lo impone quien construye la lista de vigentes; el bloqueo por
 *     estándar se aplica aquí.
 *
 *  2. Que un hallazgo no aparezca NO prueba que se resolvió: puede que ese
 *     servicio no se haya auditado. El cierre solo se propone si el estándar
 *     fue efectivamente evaluado en la auditoría nueva. Y ni así se aplica
 *     solo: lo confirma una persona.
 */
final class ReconciliadorVigencia
{
    public function __construct(private readonly Emparejador $emparejador) {}

    /**
     * @param  list<HallazgoVigente>  $vigentes    hallazgos abiertos de LA MISMA sede
     * @param  list<HallazgoExtraido>  $entrantes  hallazgos reales de la nueva auditoría
     * @param  list<string>  $estandaresEvaluados  estándares que la auditoría sí revisó
     * @param  list<HallazgoVigente>  $cerrados    hallazgos ya cerrados de la misma sede
     */
    public function reconciliar(
        array $vigentes,
        array $entrantes,
        array $estandaresEvaluados,
        array $cerrados = [],
    ): Propuesta {
        $propuesta = new Propuesta();

        $vigentesPorEstandar = $this->agruparPorEstandar($vigentes);
        $cerradosPorEstandar = $this->agruparPorEstandar($cerrados);
        $emparejados = [];

        // Un vigente que quedó en la banda de revisión NO se propone además
        // para cierre: serían dos opciones contradictorias sobre el mismo
        // hallazgo en la misma pantalla. Su destino lo decide la revisión.
        $enRevision = [];

        foreach ($entrantes as $entrante) {
            $candidatos = $this->candidatos($vigentesPorEstandar, $entrante->codigoEstandar, $emparejados);
            $resultado = $this->emparejador->mejor($entrante->descripcion, $candidatos);

            if ($resultado->esFirme()) {
                $id = (int) $resultado->clave;
                $emparejados[$id] = true;

                $propuesta->agregar(new Movimiento(
                    destino: DestinoReconciliacion::Persiste,
                    hallazgoId: $id,
                    entrante: $entrante,
                    textoVigente: $candidatos[$id],
                    similitud: $resultado->similitud,
                    margen: $resultado->margen(),
                    motivo: 'El mismo problema sigue reportado en la auditoría nueva.',
                ));

                continue;
            }

            if ($resultado->esDudosa()) {
                $id = (int) $resultado->clave;
                $enRevision[$id] = true;

                $propuesta->agregar(new Movimiento(
                    destino: DestinoReconciliacion::Revision,
                    hallazgoId: $id,
                    entrante: $entrante,
                    textoVigente: $candidatos[$id],
                    similitud: $resultado->similitud,
                    margen: $resultado->margen(),
                    motivo: $resultado->margen() < Emparejador::MARGEN_MINIMO
                        ? 'Hay dos hallazgos vigentes casi igual de parecidos: la elección no puede ser automática.'
                        : 'Se parece a un hallazgo vigente, pero no lo suficiente para vincularlos sin mirar.',
                    alternativas: $this->emparejador->ranking($entrante->descripcion, $candidatos),
                ));

                continue;
            }

            // Antes de darlo por nuevo: ¿es un problema que ya se había
            // cerrado y volvió? Esa distinción vale más que el conteo, porque
            // un hallazgo que reaparece dice que el cierre no resolvió nada.
            $reincidencia = $this->emparejador->mejor(
                $entrante->descripcion,
                $this->candidatos($cerradosPorEstandar, $entrante->codigoEstandar, [])
            );

            if ($reincidencia->esFirme()) {
                $propuesta->agregar(new Movimiento(
                    destino: DestinoReconciliacion::Reincidencia,
                    hallazgoId: (int) $reincidencia->clave,
                    entrante: $entrante,
                    textoVigente: $cerradosPorEstandar[$entrante->codigoEstandar][0]->descripcion ?? null,
                    similitud: $reincidencia->similitud,
                    margen: $reincidencia->margen(),
                    motivo: 'Este problema figuraba como cerrado y vuelve a reportarse. Reabrirlo requiere confirmación.',
                ));

                continue;
            }

            $propuesta->agregar(new Movimiento(
                destino: DestinoReconciliacion::Nuevo,
                entrante: $entrante,
                similitud: $resultado->similitud,
                motivo: 'No corresponde a ningún hallazgo vigente ni cerrado de la sede.',
            ));
        }

        $this->resolverLosQueNoAparecieron(
            $propuesta,
            $vigentes,
            $emparejados + $enRevision,
            $estandaresEvaluados,
        );

        return $propuesta;
    }

    /**
     * Los vigentes que nadie reclamó. Aquí es donde importa la regla de
     * seguridad: sin estándar evaluado no hay cierre que proponer.
     *
     * @param  list<HallazgoVigente>  $vigentes
     * @param  array<int, bool>  $comprometidos  ya emparejados o en revisión
     * @param  list<string>  $estandaresEvaluados
     */
    private function resolverLosQueNoAparecieron(
        Propuesta $propuesta,
        array $vigentes,
        array $comprometidos,
        array $estandaresEvaluados,
    ): void {
        foreach ($vigentes as $vigente) {
            if (isset($comprometidos[$vigente->id])) {
                continue;
            }

            $seEvaluo = in_array($vigente->codigoEstandar, $estandaresEvaluados, true);

            $propuesta->agregar(new Movimiento(
                destino: $seEvaluo
                    ? DestinoReconciliacion::CandidatoCierre
                    : DestinoReconciliacion::NoVerificado,
                hallazgoId: $vigente->id,
                textoVigente: $vigente->descripcion,
                motivo: $seEvaluo
                    ? 'El estándar se auditó y el problema ya no aparece. Requiere confirmación y evidencia.'
                    : 'El estándar no se evaluó en esta auditoría, así que el hallazgo sigue abierto sin verificar.',
            ));
        }
    }

    /**
     * @param  array<string, list<HallazgoVigente>>  $porEstandar
     * @param  array<int, bool>  $yaEmparejados
     * @return array<int, string>
     */
    private function candidatos(array $porEstandar, string $codigoEstandar, array $yaEmparejados): array
    {
        $candidatos = [];

        foreach ($porEstandar[$codigoEstandar] ?? [] as $vigente) {
            // Un hallazgo vigente no puede emparejarse con dos entrantes: si ya
            // se tomó, deja de ser candidato.
            if (! isset($yaEmparejados[$vigente->id])) {
                $candidatos[$vigente->id] = $vigente->descripcion;
            }
        }

        return $candidatos;
    }

    /**
     * @param  list<HallazgoVigente>  $vigentes
     * @return array<string, list<HallazgoVigente>>
     */
    private function agruparPorEstandar(array $vigentes): array
    {
        $agrupado = [];

        foreach ($vigentes as $vigente) {
            $agrupado[$vigente->codigoEstandar][] = $vigente;
        }

        return $agrupado;
    }
}
