<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Marca de una fila de criterio en la autoevaluación.
 *
 * En los archivos la marca es una "X" en la columna correspondiente (209 veces),
 * aunque tres celdas traen la letra del propio estándar. Cualquier contenido no
 * vacío en la columna vale como marca.
 */
enum MarcaCriterio: string
{
    case Cumple = 'C';
    case NoCumple = 'NC';
    case NoAplica = 'NA';
    case SinMarcar = '';

    /** Los criterios sin marcar quedan fuera del denominador del cumplimiento. */
    public function entraEnCalculo(): bool
    {
        return $this !== self::SinMarcar;
    }

    /**
     * La fórmula oficial de la hoja RESULTADOS trata "No aplica" como
     * cumplimiento: (C + NA) / (C + NC + NA).
     */
    public function cuentaComoCumplimientoOficial(): bool
    {
        return $this === self::Cumple || $this === self::NoAplica;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cumple => 'Cumple',
            self::NoCumple => 'No cumple',
            self::NoAplica => 'No aplica',
            self::SinMarcar => 'Sin marcar',
        };
    }
}
