<?php

declare(strict_types=1);

namespace Tests;

/**
 * Las pruebas del extractor corren contra los archivos reales de la E.S.E., no
 * contra maquetas: son el único juego de datos que tiene la variedad de verdad.
 *
 * Viven fuera del repositorio, así que si no están se omite la prueba en vez de
 * fallar — un clon limpio no debería reportar rojo por eso.
 */
trait UsaArchivosReales
{
    private const RAIZ = __DIR__.'/../..';

    protected function archivoAutoevaluacion(): string
    {
        return $this->exigirArchivo(self::RAIZ.'/SUH Morichal.xlsx');
    }

    protected function archivoSeguimiento(): string
    {
        return $this->exigirArchivo(self::RAIZ.'/Seguiguimiento hallazgos SUH - Auditoria interna.xls');
    }

    protected function archivoConsolidado(): string
    {
        return $this->exigirArchivo(self::RAIZ.'/consolidado.xlsx');
    }

    private function exigirArchivo(string $ruta): string
    {
        if (! is_readable($ruta)) {
            $this->markTestSkipped(sprintf(
                'No está disponible el archivo de referencia «%s».',
                basename($ruta)
            ));
        }

        return $ruta;
    }
}
