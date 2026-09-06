<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum EstadoCorte: string
{
    case Abierto = 'abierto';
    case Cerrado = 'cerrado';

    /** Un corte cerrado congela la foto del mes y no debe reescribirse. */
    public function admiteEscritura(): bool
    {
        return $this === self::Abierto;
    }
}
