<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Estado de gestión de un hallazgo dentro de un corte mensual.
 *
 * La columna "SEGUIMIENTO A CUMPLIMIENTO" de la matriz trae exactamente tres
 * valores en los tres archivos revisados — 116 / 83 / 70 apariciones — así que
 * el mapeo es una búsqueda exacta, no un emparejamiento aproximado.
 */
enum EstadoHallazgo: string
{
    case Abierto = 'abierto';
    case AbiertoConEvidencia = 'abierto_evidencia';
    case Cerrado = 'cerrado';
    case SinDato = 'sin_dato';

    /** Etiqueta tal como debe escribirse al exportar la matriz. */
    public function etiquetaExcel(): string
    {
        return match ($this) {
            self::Abierto => 'Hallazgo Abierto',
            self::AbiertoConEvidencia => 'Hallazgos Abiertos con Evidencia de Gestión',
            self::Cerrado => 'Hallazgos Cerrados',
            self::SinDato => '',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::AbiertoConEvidencia => 'Abierto con evidencia',
            self::Cerrado => 'Cerrado',
            self::SinDato => 'Sin dato',
        };
    }

    /** Un hallazgo vigente es el que todavía exige gestión. */
    public function esVigente(): bool
    {
        return $this !== self::Cerrado;
    }

    /** Valores admitidos en la lista desplegable de la matriz exportada. */
    public static function opcionesExcel(): array
    {
        return [
            self::Abierto->etiquetaExcel(),
            self::AbiertoConEvidencia->etiquetaExcel(),
            self::Cerrado->etiquetaExcel(),
        ];
    }
}
