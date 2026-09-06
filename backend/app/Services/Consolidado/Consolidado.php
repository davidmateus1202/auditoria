<?php

declare(strict_types=1);

namespace App\Services\Consolidado;

/**
 * El consolidado de un mes, con las cuatro vistas del archivo actual.
 *
 * Lleva el periodo consigo a propósito: el archivo que usan hoy no está
 * fechado, y por eso nadie puede comparar dos meses ni auditar hacia atrás.
 */
final readonly class Consolidado
{
    public function __construct(
        public string $periodo,
        public bool $corteCerrado,
        public array $generales,
        public array $porSede,
        public array $porEstandar,
        public array $sedePorEstandar,
    ) {}

    /** El agrupamiento que pida la pantalla: es el mismo dato con otra forma. */
    public function agrupadoPor(string $agrupacion): array
    {
        return match ($agrupacion) {
            'sede' => $this->porSede,
            'estandar' => $this->porEstandar,
            'sede_estandar' => $this->sedePorEstandar,
            default => throw new \InvalidArgumentException(
                "Agrupación «{$agrupacion}» no reconocida. Use: sede, estandar o sede_estandar."
            ),
        };
    }

    public function aArray(): array
    {
        return [
            'periodo' => $this->periodo,
            'corte_cerrado' => $this->corteCerrado,
            'generales' => $this->generales,
            'por_sede' => $this->porSede,
            'por_estandar' => $this->porEstandar,
            'sede_por_estandar' => $this->sedePorEstandar,
        ];
    }
}
