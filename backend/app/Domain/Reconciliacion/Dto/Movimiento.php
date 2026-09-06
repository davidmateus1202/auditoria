<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion\Dto;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Extraccion\Dto\HallazgoExtraido;

/**
 * Una decisión propuesta sobre un hallazgo, con el porqué a la vista.
 *
 * La similitud y el margen se guardan aunque el destino no dependa de ellos:
 * el auditor tiene que poder ver por qué el sistema propuso lo que propuso.
 */
final readonly class Movimiento
{
    public function __construct(
        public DestinoReconciliacion $destino,
        public ?int $hallazgoId = null,
        public ?HallazgoExtraido $entrante = null,
        public ?string $textoVigente = null,
        public float $similitud = 0.0,
        public float $margen = 0.0,
        public ?string $motivo = null,
        /** @var array<int, float> otros candidatos, para que el auditor elija */
        public array $alternativas = [],
    ) {}

    public function codigoEstandar(): ?string
    {
        return $this->entrante?->codigoEstandar;
    }

    public function texto(): string
    {
        return $this->entrante?->descripcion ?? $this->textoVigente ?? '';
    }

    public function aArray(): array
    {
        return array_filter([
            'destino' => $this->destino->value,
            'hallazgo_id' => $this->hallazgoId,
            'estandar' => $this->codigoEstandar(),
            'texto_entrante' => $this->entrante?->descripcion,
            'texto_vigente' => $this->textoVigente,
            'similitud' => $this->similitud > 0 ? round($this->similitud, 3) : null,
            'margen' => $this->margen > 0 ? round($this->margen, 3) : null,
            'motivo' => $this->motivo,
            'alternativas' => $this->alternativas ?: null,
            'exige_confirmacion' => $this->destino->exigeConfirmacion(),
        ], static fn ($v) => $v !== null);
    }
}
