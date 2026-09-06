<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion;

/**
 * Qué encontró el emparejador, con lo necesario para explicar la decisión.
 */
final readonly class ResultadoEmparejamiento
{
    public function __construct(
        public int|string|null $clave,
        public float $similitud,
        public float $segundaSimilitud = 0.0,
        public int $candidatosEvaluados = 0,
    ) {}

    public static function sinCandidatos(): self
    {
        return new self(clave: null, similitud: 0.0);
    }

    public function margen(): float
    {
        return $this->similitud - $this->segundaSimilitud;
    }

    /**
     * Coincidencia firme: pasa el umbral alto y además se despega del segundo.
     * Un empate técnico no debería aplicarse solo.
     */
    public function esFirme(): bool
    {
        return $this->clave !== null
            && $this->similitud >= Emparejador::UMBRAL_ALTO
            && $this->margen() >= Emparejador::MARGEN_MINIMO;
    }

    /** Zona gris: hay parecido, pero no el suficiente para decidir sin mirar. */
    public function esDudosa(): bool
    {
        if ($this->clave === null) {
            return false;
        }

        if ($this->similitud >= Emparejador::UMBRAL_BAJO && $this->similitud < Emparejador::UMBRAL_ALTO) {
            return true;
        }

        // Pasa el umbral pero empatado con otro candidato: también es dudosa.
        return $this->similitud >= Emparejador::UMBRAL_ALTO && $this->margen() < Emparejador::MARGEN_MINIMO;
    }

    public function sinCorrespondencia(): bool
    {
        return $this->clave === null || $this->similitud < Emparejador::UMBRAL_BAJO;
    }

    public function aArray(): array
    {
        return [
            'clave' => $this->clave,
            'similitud' => round($this->similitud, 3),
            'margen' => round($this->margen(), 3),
            'candidatos' => $this->candidatosEvaluados,
        ];
    }
}
