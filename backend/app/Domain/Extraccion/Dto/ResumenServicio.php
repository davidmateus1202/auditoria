<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Dto;

use App\Domain\Extraccion\CatalogoEstandares;

/**
 * Cumplimiento de un servicio, agregando sus estándares.
 *
 * El total de la hoja RESULTADOS no es la razón agregada sino
 * `=(C7+D7+E7+F7+G7+H7)/6`: el promedio simple de los seis primeros estándares.
 * De ahí salen dos consecuencias que conviene tener presentes:
 *
 *   - Interdependencia (E7) queda FUERA del total, aunque se calcule y se
 *     muestre en su propia columna.
 *   - Cada estándar pesa igual sin importar cuántos criterios tenga, así que
 *     Historia clínica (9 criterios) pesa lo mismo que Infraestructura (27).
 *
 * Se reproduce tal cual para que las cifras cuadren con lo que la E.S.E. viene
 * reportando, y al lado se calcula el total ponderado, que es el defendible.
 */
final class ResumenServicio
{
    /** Los seis estándares que entran al total oficial, en su orden. */
    private const ESTANDARES_DEL_TOTAL = [
        CatalogoEstandares::E1_TALENTO_HUMANO,
        CatalogoEstandares::E2_INFRAESTRUCTURA,
        CatalogoEstandares::E3_DOTACION,
        CatalogoEstandares::E4_MEDICAMENTOS,
        CatalogoEstandares::E5_PROCESOS_PRIORITARIOS,
        CatalogoEstandares::E6_HISTORIA_CLINICA,
    ];

    /** @param array<string, ResumenCumplimiento> $porEstandar */
    public function __construct(
        public readonly string $servicio,
        public readonly array $porEstandar,
    ) {}

    /** Promedio simple de los seis primeros estándares — la fórmula de la E.S.E. */
    public function totalOficial(): ?float
    {
        $suma = 0.0;

        foreach (self::ESTANDARES_DEL_TOTAL as $codigo) {
            $pct = $this->porEstandar[$codigo]?->porcentajeOficial();

            // La hoja divide siempre entre 6; si a un estándar le faltan
            // criterios el resultado allá es #DIV/0!. Aquí se devuelve null,
            // que es lo mismo dicho sin romper la pantalla.
            if ($pct === null) {
                return null;
            }

            $suma += $pct;
        }

        return $suma / count(self::ESTANDARES_DEL_TOTAL);
    }

    /**
     * Razón agregada sobre todos los criterios evaluados del servicio,
     * Interdependencia incluida. Es la que no depende de cuántos estándares
     * tenga el servicio ni de cuál se haya dejado fuera.
     */
    public function totalPonderado(): ?float
    {
        $numerador = 0;
        $denominador = 0;

        foreach ($this->porEstandar as $resumen) {
            $numerador += $resumen->cumple + $resumen->noAplica;
            $denominador += $resumen->evaluados();
        }

        return $denominador === 0 ? null : $numerador / $denominador;
    }

    /** Sin tratar «No aplica» como cumplimiento. */
    public function totalEstricto(): ?float
    {
        $cumple = 0;
        $evaluablesReales = 0;

        foreach ($this->porEstandar as $resumen) {
            $cumple += $resumen->cumple;
            $evaluablesReales += $resumen->cumple + $resumen->noCumple;
        }

        return $evaluablesReales === 0 ? null : $cumple / $evaluablesReales;
    }

    public function aArray(): array
    {
        return [
            'servicio' => $this->servicio,
            'total_oficial' => $this->totalOficial(),
            'total_ponderado' => $this->totalPonderado(),
            'total_estricto' => $this->totalEstricto(),
            'estandares' => array_map(
                static fn (ResumenCumplimiento $r): array => $r->aArray(),
                $this->porEstandar
            ),
        ];
    }
}
