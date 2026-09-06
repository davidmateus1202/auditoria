<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué es realmente el texto que hay en la celda de hallazgo.
 *
 * El auditor escribe "Cumple" o "No auditado" en la misma columna donde
 * escribe los hallazgos reales. El consolidado histórico cuenta esas filas
 * como hallazgos cerrados: son las 15 que separan el 238 reportado del 223 real.
 */
enum ClasificacionHallazgo: string
{
    case Hallazgo = 'hallazgo';
    case Cumple = 'cumple';
    case SinDato = 'sin_dato';
    case Dudoso = 'dudoso';

    public function cuentaComoHallazgo(): bool
    {
        return $this === self::Hallazgo;
    }

    public function requiereRevision(): bool
    {
        return $this === self::Dudoso;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Hallazgo => 'Hallazgo',
            self::Cumple => 'Cumple',
            self::SinDato => 'Sin dato',
            self::Dudoso => 'Dudoso',
        };
    }
}
