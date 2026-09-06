<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

use App\Domain\Enums\ClasificacionHallazgo;

/**
 * Decide si el texto de una celda es un hallazgo real.
 *
 * Es uno de los dos únicos puntos donde importa el texto libre (el otro es el
 * emparejamiento entre periodos). El auditor escribe "Cumple", "No aplica" o
 * "No auditado" en la misma columna que los hallazgos, y el consolidado
 * histórico los cuenta como hallazgos cerrados: eso infla el avance del
 * municipio de 14,8 % a 19,7 % sin que se haya cerrado nada.
 */
final class ClasificadorHallazgo
{
    /** Longitud mínima para considerar que una celda describe un problema. */
    public const LONGITUD_MINIMA = 15;

    /** El estándar se evaluó y no arrojó hallazgo. */
    private const CENTINELAS_CUMPLE = [
        'CUMPLE',
        'CUMPLEN',
        'SI CUMPLE',
        'CUMPLE TOTALMENTE',
        'CUMPLE CON EL ESTANDAR',
        'SIN HALLAZGOS',
        'SIN HALLAZGO',
        'NINGUNO',
        'NINGUNA',
        'NO APLICA',
        'NA',
        'N A',
        'NO SE EVIDENCIAN HALLAZGOS',
    ];

    /** El estándar no se evaluó: no es un logro, es una alerta de cobertura. */
    private const CENTINELAS_SIN_DATO = [
        'NO VERIFICADO',
        'NO AUDITADO',
        'NO EVALUADO',
        'NO SE VERIFICO',
        'NO SE AUDITO',
        'SIN VERIFICAR',
        'SIN AUDITAR',
        'PENDIENTE',
        'PENDIENTE POR VERIFICAR',
        'POR VERIFICAR',
    ];

    public static function clasificar(?string $texto): ClasificacionHallazgo
    {
        $clave = TextoNormalizador::canonica($texto);

        if ($clave === '') {
            return ClasificacionHallazgo::SinDato;
        }

        if (in_array($clave, self::CENTINELAS_CUMPLE, true)) {
            return ClasificacionHallazgo::Cumple;
        }

        if (in_array($clave, self::CENTINELAS_SIN_DATO, true)) {
            return ClasificacionHallazgo::SinDato;
        }

        // Un texto corto no describe un problema: puede ser una abreviatura, un
        // resto de edición o un centinela escrito de una forma que no está en
        // las listas. Va a revisión en vez de contarse o descartarse.
        if (mb_strlen($clave, 'UTF-8') < self::LONGITUD_MINIMA) {
            return ClasificacionHallazgo::Dudoso;
        }

        return ClasificacionHallazgo::Hallazgo;
    }

    /** Expuesto para la pantalla de administración del catálogo. */
    public static function centinelas(): array
    {
        return [
            'cumple' => self::CENTINELAS_CUMPLE,
            'sin_dato' => self::CENTINELAS_SIN_DATO,
        ];
    }
}
