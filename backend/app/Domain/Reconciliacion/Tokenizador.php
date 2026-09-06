<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

use App\Domain\Extraccion\TextoNormalizador;

/**
 * Convierte el texto de un hallazgo en tokens comparables.
 *
 * Quita las palabras vacías del español y aplica una lematización ligera, de
 * modo que «puertas deterioradas» y «puerta deteriorada» caigan en el mismo
 * token. No es un lematizador de verdad y no pretende serlo: recorta sufijos
 * frecuentes, que es suficiente para comparar dos redacciones del mismo
 * problema y no depende de ningún diccionario externo.
 */
final class Tokenizador
{
    private const LONGITUD_MINIMA = 3;

    /** Palabras vacías del español, más los verbos de relleno del formato. */
    private const VACIAS = [
        'DE', 'LA', 'EL', 'LOS', 'LAS', 'UN', 'UNA', 'UNOS', 'UNAS', 'AL', 'DEL', 'LO',
        'Y', 'O', 'A', 'EN', 'CON', 'POR', 'PARA', 'QUE', 'SE', 'SU', 'SUS', 'SIN',
        'ES', 'SON', 'ESTA', 'ESTAN', 'ESTE', 'ESTOS', 'ESTAS', 'ESE', 'ESA',
        'NO', 'SI', 'COMO', 'MAS', 'PERO', 'SOBRE', 'ENTRE', 'CUANDO', 'DONDE',
        'MUY', 'HAY', 'HA', 'HAN', 'YA', 'CUAL', 'CUALES', 'TODO', 'TODA',
        'TODOS', 'TODAS', 'OTRO', 'OTRA', 'CADA', 'DESDE', 'HASTA', 'ANTE',
        'TAMBIEN', 'ASI', 'SOLO', 'MISMO', 'SER', 'HACER', 'TENER', 'PUEDE',
        'DEBE', 'ENCUENTRA', 'ENCUENTRAN', 'CUENTA', 'CUENTAN', 'DENTRO', 'FUERA',
    ];

    /** Sufijos que se recortan, del más largo al más corto. */
    private const SUFIJOS = [
        'CIONES', 'ADORES', 'MIENTO', 'ANDO', 'IENDO', 'ADAS', 'ADOS',
        'IDAS', 'IDOS', 'ES', 'AS', 'OS', 'A', 'O',
    ];

    /** @return list<string> */
    public static function tokens(?string $texto): array
    {
        $canonico = TextoNormalizador::canonica($texto);

        if ($canonico === '') {
            return [];
        }

        $tokens = [];

        foreach (explode(' ', $canonico) as $palabra) {
            if (mb_strlen($palabra, 'UTF-8') < self::LONGITUD_MINIMA) {
                continue;
            }

            if (in_array($palabra, self::VACIAS, true)) {
                continue;
            }

            $tokens[] = self::raiz($palabra);
        }

        return $tokens;
    }

    /** @return array<string, int> trigramas de caracteres y su frecuencia */
    public static function trigramas(?string $texto): array
    {
        $canonico = ' '.TextoNormalizador::canonica($texto).' ';
        $longitud = mb_strlen($canonico, 'UTF-8');
        $conteo = [];

        for ($i = 0; $i + 3 <= $longitud; $i++) {
            $trigrama = mb_substr($canonico, $i, 3, 'UTF-8');
            $conteo[$trigrama] = ($conteo[$trigrama] ?? 0) + 1;
        }

        return $conteo;
    }

    public static function raiz(string $palabra): string
    {
        foreach (self::SUFIJOS as $sufijo) {
            $largoSufijo = strlen($sufijo);

            // Se exige que quede una raíz de al menos 4 letras: recortar más
            // junta palabras que no tienen nada que ver.
            if (strlen($palabra) > $largoSufijo + 3 && str_ends_with($palabra, $sufijo)) {
                return substr($palabra, 0, -$largoSufijo);
            }
        }

        return $palabra;
    }
}
