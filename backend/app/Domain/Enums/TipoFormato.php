<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Los dos formatos de Excel que entran al sistema, con cadencias distintas.
 */
enum TipoFormato: string
{
    /** SUH <sede>.xlsx — autoevaluación de una sede. Entrada esporádica. */
    case Autoevaluacion = 'autoevaluacion';

    /** Seguimiento hallazgos SUH.xls — matriz de estado. Entrada mensual. */
    case Seguimiento = 'seguimiento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Autoevaluacion => 'Autoevaluación por sede',
            self::Seguimiento => 'Matriz de seguimiento mensual',
        };
    }
}
