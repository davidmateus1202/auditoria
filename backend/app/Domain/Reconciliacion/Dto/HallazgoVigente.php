<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion\Dto;

/**
 * Un hallazgo que la sede ya tenía abierto, reducido a lo que hace falta para
 * compararlo. Se trabaja con esto y no con el modelo Eloquent para que el
 * reconciliador se pueda probar sin base de datos.
 */
final readonly class HallazgoVigente
{
    public function __construct(
        public int $id,
        public string $codigoEstandar,
        public string $descripcion,
    ) {}
}
