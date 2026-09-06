<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

/**
 * Empareja un texto entrante contra un conjunto de candidatos.
 *
 * UMBRALES — no se eligieron a ojo, se midieron sobre los archivos reales:
 *
 *   hallazgos DISTINTOS de la misma sede y estándar   ≤ 0,547  (1 056 pares,
 *                                                     ninguno supera 0,60)
 *   mismo hallazgo REESCRITO con sinónimos            ≥ 0,702
 *   mismo hallazgo TRANSCRITO                         ≥ 0,933  (14/14 en Morichal)
 *
 * El corte en 0,70 cae en el hueco entre el peor par verdadero y el par de
 * hallazgos distintos que más se parece. La banda de revisión absorbe lo que
 * quede en el medio.
 *
 * EL BLOQUEO POR SEDE ES OBLIGATORIO y lo garantiza quien llama: entre sedes
 * distintas hay 161 pares por encima de 0,70 y varios en 1,000 exacto, porque
 * los auditores copian el mismo texto de un centro de salud a otro. Sin el
 * bloqueo, el emparejador cerraría hallazgos de una sede con evidencia de otra.
 */
final class Emparejador
{
    /** Por encima: es el mismo hallazgo. */
    public const UMBRAL_ALTO = 0.70;

    /** Por debajo: no hay correspondencia. Entre ambos: lo decide una persona. */
    public const UMBRAL_BAJO = 0.45;

    /**
     * Distancia mínima al segundo candidato. Si dos candidatos empatan, la
     * elección es una moneda al aire y eso no puede cerrar un hallazgo solo.
     */
    public const MARGEN_MINIMO = 0.10;

    public function __construct(private readonly Similitud $similitud) {}

    /**
     * @param  array<int|string, string>  $candidatos  clave => texto, ya filtrados
     *                                                 por sede y estándar
     */
    public function mejor(string $texto, array $candidatos): ResultadoEmparejamiento
    {
        if ($candidatos === []) {
            return ResultadoEmparejamiento::sinCandidatos();
        }

        $puntajes = [];

        foreach ($candidatos as $clave => $candidato) {
            $puntajes[$clave] = $this->similitud->entre($texto, $candidato);
        }

        arsort($puntajes);

        $claves = array_keys($puntajes);
        $mejorClave = $claves[0];
        $mejor = $puntajes[$mejorClave];
        $segundo = isset($claves[1]) ? $puntajes[$claves[1]] : 0.0;

        return new ResultadoEmparejamiento(
            clave: $mejorClave,
            similitud: $mejor,
            segundaSimilitud: $segundo,
            candidatosEvaluados: count($candidatos),
        );
    }

    /**
     * Todos los candidatos por encima del umbral bajo, de mayor a menor.
     * Es lo que se le muestra al auditor cuando tiene que decidir.
     *
     * @param  array<int|string, string>  $candidatos
     * @return array<int|string, float>
     */
    public function ranking(string $texto, array $candidatos, int $limite = 5): array
    {
        $puntajes = [];

        foreach ($candidatos as $clave => $candidato) {
            $puntaje = $this->similitud->entre($texto, $candidato);

            if ($puntaje >= self::UMBRAL_BAJO) {
                $puntajes[$clave] = round($puntaje, 3);
            }
        }

        arsort($puntajes);

        return array_slice($puntajes, 0, $limite, preserve_keys: true);
    }
}
