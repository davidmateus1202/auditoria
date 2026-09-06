<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

/**
 * Normalización canónica de texto.
 *
 * Es la primera puerta por la que pasa cualquier cadena antes de compararse.
 * Sin esto, "DOTACIÓN" y "DOTACION " son dos estándares distintos: son las
 * dos únicas variantes que aparecen de verdad en los archivos de la E.S.E.
 */
final class TextoNormalizador
{
    /**
     * MAYÚSCULAS, sin diacríticos, sin puntuación y con espacios colapsados.
     * Es la forma que se usa como clave en todos los catálogos cerrados.
     */
    public static function canonica(?string $texto): string
    {
        if ($texto === null) {
            return '';
        }

        // Los espacios duros llegan desde Excel y no los captura \s de forma fiable.
        $t = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $texto);

        $t = self::sinDiacriticos($t);
        $t = mb_strtoupper($t, 'UTF-8');
        $t = preg_replace('/[^A-Z0-9 ]+/u', ' ', $t) ?? '';

        return self::colapsarEspacios($t);
    }

    /**
     * Limpieza conservadora para el texto que se le muestra a una persona:
     * conserva tildes, mayúsculas y puntuación, pero normaliza los espacios.
     */
    public static function visible(?string $texto): string
    {
        if ($texto === null) {
            return '';
        }

        $t = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $texto);
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? '';
        $t = preg_replace('/ *\R+ */u', "\n", $t) ?? '';

        return trim($t);
    }

    public static function sinDiacriticos(string $texto): string
    {
        $normalizado = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto)
            : $texto;

        // Rango de marcas de combinación: cubre la descomposición NFD.
        $sinMarcas = preg_replace('/\p{Mn}+/u', '', $normalizado);

        if ($sinMarcas === null || $sinMarcas === $normalizado) {
            // Sin ext-intl la descomposición no ocurre; se traducen los caracteres
            // que de verdad aparecen en español.
            $sinMarcas = strtr($normalizado, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            ]);
        }

        return $sinMarcas;
    }

    public static function colapsarEspacios(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    }

    /**
     * Cuenta caracteres útiles: sirve para descartar celdas que solo traen
     * viñetas, guiones o espacios y que no son un hallazgo.
     */
    public static function longitudUtil(?string $texto): int
    {
        return mb_strlen(self::canonica($texto), 'UTF-8');
    }
}
