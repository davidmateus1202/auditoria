<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

use App\Domain\Extraccion\TextoNormalizador;

/**
 * Estadística del corpus de hallazgos del municipio.
 *
 * Es lo que hace que las palabras raras manden en la comparación: «autoclave»
 * discrimina entre dos hallazgos, «centro» no. El IDF se calcula sobre los
 * hallazgos reales de la entidad, no sobre un corpus general del español.
 */
final class IndiceIdf
{
    /** @var array<string, float> */
    private array $idf = [];

    private float $idfPorDefecto;

    /** @param iterable<string> $corpus */
    public function __construct(iterable $corpus)
    {
        $documentos = 0;
        $frecuencia = [];

        foreach ($corpus as $texto) {
            $tokens = Tokenizador::tokens($texto);

            if ($tokens === []) {
                continue;
            }

            $documentos++;

            foreach (array_unique($tokens) as $token) {
                $frecuencia[$token] = ($frecuencia[$token] ?? 0) + 1;
            }
        }

        $documentos = max(1, $documentos);

        foreach ($frecuencia as $token => $enCuantos) {
            $this->idf[$token] = log(($documentos + 1) / ($enCuantos + 1)) + 1;
        }

        // Una palabra que no está en el corpus es lo más discriminante que hay.
        $this->idfPorDefecto = log($documentos + 1) + 1;
    }

    public function peso(string $token): float
    {
        return $this->idf[$token] ?? $this->idfPorDefecto;
    }

    /**
     * Vector TF-IDF con frecuencia logarítmica.
     *
     * @return array<string, float>
     */
    public function vector(string $texto): array
    {
        $conteo = array_count_values(Tokenizador::tokens($texto));
        $vector = [];

        foreach ($conteo as $token => $veces) {
            $vector[$token] = (1 + log((float) $veces)) * $this->peso((string) $token);
        }

        return $vector;
    }

    public function tamanoCorpus(): int
    {
        return count($this->idf);
    }

    /** Índice vacío: todo token pesa igual. Útil en pruebas y primeras cargas. */
    public static function vacio(): self
    {
        return new self([]);
    }
}
