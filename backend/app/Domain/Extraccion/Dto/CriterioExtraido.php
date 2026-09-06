<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Dto;

use App\Domain\Enums\MarcaCriterio;

/**
 * Una fila de criterio de habilitación de la autoevaluación.
 * De estas filas salen los porcentajes de cumplimiento.
 */
final readonly class CriterioExtraido
{
    public function __construct(
        public string $servicio,
        public string $codigoEstandar,
        public string $criterio,
        public MarcaCriterio $marca,
        public string $observacion,
        public string $hoja,
        public int $fila,
    ) {}

    public function tieneObservacion(): bool
    {
        return trim($this->observacion) !== '';
    }
}
