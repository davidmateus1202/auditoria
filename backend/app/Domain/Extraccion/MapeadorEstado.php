<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

use App\Domain\Enums\EstadoHallazgo;

/**
 * Traduce la columna "SEGUIMIENTO A CUMPLIMIENTO" a un estado del dominio.
 *
 * Sobre las diez hojas de la matriz solo aparecen tres valores distintos, así
 * que esto es una búsqueda exacta. Se admiten las variantes de singular/plural
 * porque el campo hoy se escribe libre; a partir de la primera exportación
 * sale con lista desplegable y el problema desaparece de raíz.
 */
final class MapeadorEstado
{
    private const MAPA = [
        'HALLAZGO ABIERTO' => EstadoHallazgo::Abierto,
        'HALLAZGOS ABIERTOS' => EstadoHallazgo::Abierto,
        'ABIERTO' => EstadoHallazgo::Abierto,

        'HALLAZGOS ABIERTOS CON EVIDENCIA DE GESTION' => EstadoHallazgo::AbiertoConEvidencia,
        'HALLAZGO ABIERTO CON EVIDENCIA DE GESTION' => EstadoHallazgo::AbiertoConEvidencia,
        'ABIERTO CON EVIDENCIA DE GESTION' => EstadoHallazgo::AbiertoConEvidencia,
        'ABIERTO CON EVIDENCIA' => EstadoHallazgo::AbiertoConEvidencia,

        'HALLAZGOS CERRADOS' => EstadoHallazgo::Cerrado,
        'HALLAZGO CERRADO' => EstadoHallazgo::Cerrado,
        'CERRADO' => EstadoHallazgo::Cerrado,
        'CERRADOS' => EstadoHallazgo::Cerrado,
    ];

    /** Una celda vacía es ausencia de seguimiento, no un estado elegido. */
    public static function mapear(?string $texto): EstadoHallazgo
    {
        $clave = TextoNormalizador::canonica($texto);

        if ($clave === '') {
            return EstadoHallazgo::SinDato;
        }

        return self::MAPA[$clave] ?? EstadoHallazgo::SinDato;
    }

    /** Distingue "no se escribió nada" de "se escribió algo que no entendemos". */
    public static function esReconocible(?string $texto): bool
    {
        $clave = TextoNormalizador::canonica($texto);

        return $clave === '' || isset(self::MAPA[$clave]);
    }
}
