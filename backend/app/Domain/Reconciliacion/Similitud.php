<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

/**
 * Cuánto se parecen dos redacciones del mismo hallazgo.
 *
 * Tres señales combinadas, todas calculables en el servidor y todas
 * explicables ante una auditoría externa — nada de modelos estadísticos que
 * después no se puedan justificar:
 *
 *   0,50  coseno TF-IDF     las palabras raras mandan
 *   0,30  coseno de trigramas   aguanta erratas y plurales
 *   0,20  Jaccard de tokens     ancla el resultado al solapamiento literal
 *
 * Calibrado contra los archivos de la E.S.E.: Morichal aparece en los dos, con
 * los mismos catorce hallazgos redactados por separado.
 */
final class Similitud
{
    public const PESO_IDF = 0.50;
    public const PESO_TRIGRAMAS = 0.30;
    public const PESO_JACCARD = 0.20;

    public function __construct(private readonly IndiceIdf $idf) {}

    /** @return array{total: float, idf: float, trigramas: float, jaccard: float} */
    public function detalle(string $a, string $b): array
    {
        $porIdf = $this->coseno($this->idf->vector($a), $this->idf->vector($b));
        $porTrigramas = $this->coseno(
            array_map('floatval', Tokenizador::trigramas($a)),
            array_map('floatval', Tokenizador::trigramas($b))
        );
        $porJaccard = $this->jaccard($a, $b);

        return [
            'total' => self::PESO_IDF * $porIdf
                + self::PESO_TRIGRAMAS * $porTrigramas
                + self::PESO_JACCARD * $porJaccard,
            'idf' => $porIdf,
            'trigramas' => $porTrigramas,
            'jaccard' => $porJaccard,
        ];
    }

    public function entre(string $a, string $b): float
    {
        return $this->detalle($a, $b)['total'];
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    private function coseno(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $producto = 0.0;

        // Se recorre el vector más corto: las claves que no están en el otro
        // aportan cero al producto punto.
        if (count($b) < count($a)) {
            [$a, $b] = [$b, $a];
        }

        foreach ($a as $clave => $valor) {
            if (isset($b[$clave])) {
                $producto += $valor * $b[$clave];
            }
        }

        $normaA = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $a)));
        $normaB = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $b)));
        $denominador = $normaA * $normaB;

        return $denominador > 0.0 ? $producto / $denominador : 0.0;
    }

    private function jaccard(string $a, string $b): float
    {
        $tokensA = array_unique(Tokenizador::tokens($a));
        $tokensB = array_unique(Tokenizador::tokens($b));

        if ($tokensA === [] && $tokensB === []) {
            return 0.0;
        }

        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union === 0 ? 0.0 : count(array_intersect($tokensA, $tokensB)) / $union;
    }
}
