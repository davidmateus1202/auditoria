<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

/**
 * Parte una celda que contiene varios hallazgos.
 *
 * Una sola celda puede traer tres problemas distintos separados por saltos de
 * línea o viñetas; si no se segmentan, la sede subreporta. El riesgo opuesto es
 * partir un párrafo que venía envuelto, así que los fragmentos que arrancan en
 * minúscula tras una línea sin punto final se vuelven a unir.
 */
final class SegmentadorHallazgos
{
    private const LONGITUD_MINIMA_FRAGMENTO = 15;

    /** @return list<string> fragmentos listos para tratarse como hallazgos */
    public static function segmentar(?string $texto): array
    {
        // Aquí no sirve la limpieza habitual: colapsa los saltos de línea y con
        // ellos la línea en blanco que cierra una enumeración. Se normaliza
        // solo el espacio horizontal y se conserva la estructura de líneas.
        $limpio = trim(preg_replace(
            '/[ \t\x{00A0}]+/u',
            ' ',
            str_replace("\u{200B}", '', (string) $texto)
        ) ?? '');

        if ($limpio === '') {
            return [];
        }

        $lineas = preg_split('/\R/u', $limpio) ?: [];
        $fragmentos = [];
        $listaAbierta = false;

        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                // Una línea en blanco cierra la enumeración en curso.
                $listaAbierta = false;

                continue;
            }

            foreach (self::partirPorVinetas($linea) as $trozo) {
                $trozo = trim($trozo);

                if ($trozo === '') {
                    continue;
                }

                if ($fragmentos !== [] && ($listaAbierta || self::continuaElAnterior($trozo, $fragmentos))) {
                    $fragmentos[count($fragmentos) - 1] .= ' '.$trozo;

                    continue;
                }

                $fragmentos[] = $trozo;
                $listaAbierta = self::abreEnumeracion($trozo);
            }
        }

        $utiles = array_values(array_filter(
            $fragmentos,
            static fn (string $f): bool => TextoNormalizador::longitudUtil($f) >= self::LONGITUD_MINIMA_FRAGMENTO
        ));

        // Si la segmentación no dejó nada aprovechable, es preferible devolver
        // el texto entero y que lo clasifique el clasificador, antes que perder
        // el contenido de la celda.
        return $utiles !== []
            ? $utiles
            : [TextoNormalizador::visible($limpio)];
    }

    /**
     * Un texto que termina en dos puntos está anunciando una lista, y lo que
     * viene después son sus ítems, no hallazgos nuevos.
     *
     * Es el caso de «…no cuenta con:» seguido de las áreas que faltan: son un
     * solo hallazgo escrito en cuatro líneas. Partirlo inflaría el conteo, que
     * es justamente el defecto que este sistema viene a corregir. Ante la duda
     * conviene sub-segmentar: el texto se conserva entero y el auditor lo ve
     * completo en la pantalla de revisión.
     */
    private static function abreEnumeracion(string $fragmento): bool
    {
        return str_ends_with(rtrim($fragmento), ':');
    }

    /**
     * Corta por viñetas y numeraciones al inicio de un segmento:
     * "- ", "• ", "* ", "1. ", "1) ", "a) ".
     */
    private static function partirPorVinetas(string $linea): array
    {
        $marcado = preg_replace(
            '/(?<=\S)\s+(?=(?:[-•*·—]\s|\d{1,2}[.)]\s|[a-z][.)]\s))/u',
            "\x00",
            $linea
        ) ?? $linea;

        $trozos = explode("\x00", $marcado);

        return array_map(
            static fn (string $t): string => preg_replace('/^\s*(?:[-•*·—]|\d{1,2}[.)]|[a-z][.)])\s*/u', '', $t) ?? $t,
            $trozos
        );
    }

    /**
     * Un fragmento continúa al anterior si empieza en minúscula y el anterior
     * no cerró con puntuación terminal: es una línea partida, no un hallazgo.
     */
    private static function continuaElAnterior(string $trozo, array $fragmentos): bool
    {
        if ($fragmentos === []) {
            return false;
        }

        $anterior = rtrim(end($fragmentos));

        if ($anterior === '' || preg_match('/[.:;!?]$/u', $anterior) === 1) {
            return false;
        }

        $primera = mb_substr($trozo, 0, 1, 'UTF-8');

        return mb_strtolower($primera, 'UTF-8') === $primera
            && preg_match('/\p{L}/u', $primera) === 1;
    }
}
