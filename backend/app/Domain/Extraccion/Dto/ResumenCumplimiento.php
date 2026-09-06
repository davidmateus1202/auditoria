<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Dto;

/**
 * Conteo de marcas de un estándar dentro de un servicio, con los dos
 * indicadores de cumplimiento.
 *
 * El oficial reproduce la hoja RESULTADOS de la E.S.E. y trata "No aplica"
 * como cumplimiento; el estricto lo excluye. En Morichal la diferencia va de
 * 80,97 % a 71,2 %, así que se guardan los dos, etiquetados.
 */
final class ResumenCumplimiento
{
    public int $cumple = 0;
    public int $noCumple = 0;
    public int $noAplica = 0;
    public int $sinMarcar = 0;

    public function __construct(
        public readonly string $servicio,
        public readonly string $codigoEstandar,
    ) {}

    /** Criterios que entran al denominador: los sin marcar quedan fuera. */
    public function evaluados(): int
    {
        return $this->cumple + $this->noCumple + $this->noAplica;
    }

    public function total(): int
    {
        return $this->evaluados() + $this->sinMarcar;
    }

    /** (C + NA) / (C + NC + NA) — la fórmula de la hoja RESULTADOS. */
    public function porcentajeOficial(): ?float
    {
        $den = $this->evaluados();

        return $den === 0 ? null : ($this->cumple + $this->noAplica) / $den;
    }

    /** C / (C + NC) — excluye "No aplica" del cálculo. */
    public function porcentajeEstricto(): ?float
    {
        $den = $this->cumple + $this->noCumple;

        return $den === 0 ? null : $this->cumple / $den;
    }

    public function aArray(): array
    {
        return [
            'servicio' => $this->servicio,
            'estandar' => $this->codigoEstandar,
            'c' => $this->cumple,
            'nc' => $this->noCumple,
            'na' => $this->noAplica,
            'sin_marcar' => $this->sinMarcar,
            'evaluados' => $this->evaluados(),
            'pct_oficial' => $this->porcentajeOficial(),
            'pct_estricto' => $this->porcentajeEstricto(),
        ];
    }
}
