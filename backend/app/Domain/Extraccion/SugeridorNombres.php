<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

/**
 * Propone el nombre del catálogo que más se parece a un texto que no se
 * reconoció.
 *
 * Solo sugiere: nunca resuelve por su cuenta. Los catálogos siguen siendo de
 * coincidencia exacta, porque un estándar adivinado en silencio produce
 * cifras equivocadas que nadie detecta; la sugerencia le ahorra a la persona
 * escribir, pero la decisión la toma ella.
 */
final class SugeridorNombres
{
    /** Por debajo de esto la «sugerencia» confunde más de lo que ayuda. */
    private const UMBRAL = 0.55;

    /**
     * @param  array<string, string>  $candidatos  texto con el que se compara => valor a sugerir
     * @return array{valor: string, puntaje: float}|null
     */
    public static function sugerir(?string $texto, array $candidatos): ?array
    {
        $clave = TextoNormalizador::canonica($texto);

        if ($clave === '') {
            return null;
        }

        $mejor = null;

        foreach ($candidatos as $comparable => $valor) {
            // PHP convierte en entero una clave como «123»: se vuelve a texto.
            $puntaje = self::parecido($clave, TextoNormalizador::canonica((string) $comparable));

            if ($mejor === null || $puntaje > $mejor['puntaje']) {
                $mejor = ['valor' => $valor, 'puntaje' => $puntaje];
            }
        }

        return $mejor !== null && $mejor['puntaje'] >= self::UMBRAL ? $mejor : null;
    }

    /** 0 = nada en común, 1 = iguales tras normalizar. */
    public static function parecido(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        // Errores de tecleo: «INFRAESTRUCURA», «DOTACON».
        $largo = max(mb_strlen($a), mb_strlen($b));
        $porCaracteres = 1 - levenshtein(substr($a, 0, 255), substr($b, 0, 255)) / min(255, $largo);

        // Palabras de más o en otro orden: «DOTACION Y EQUIPOS», «INSUMOS Y MEDICAMENTOS».
        $palabrasA = array_unique(explode(' ', $a));
        $palabrasB = array_unique(explode(' ', $b));
        $comunes = count(array_intersect($palabrasA, $palabrasB));
        $porPalabras = $comunes / count(array_unique([...$palabrasA, ...$palabrasB]));

        // Un nombre dentro del otro: «ESTANDAR DE INFRAESTRUCTURA».
        $corto = mb_strlen($a) < mb_strlen($b) ? $a : $b;
        $contenido = mb_strlen($corto) >= 5 && (str_contains($a, $b) || str_contains($b, $a)) ? 0.85 : 0.0;

        return max($porCaracteres, $porPalabras, $contenido);
    }
}
