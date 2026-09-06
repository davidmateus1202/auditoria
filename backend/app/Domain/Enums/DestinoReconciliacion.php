<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Resultado de enfrentar un hallazgo nuevo contra los vigentes de la sede.
 */
enum DestinoReconciliacion: string
{
    case Persiste = 'persiste';
    case Nuevo = 'nuevo';
    case CandidatoCierre = 'candidato_cierre';
    case NoVerificado = 'no_verificado';
    case AusenteDelCorte = 'ausente_corte';
    case Revision = 'revision';
    case Conflicto = 'conflicto';

    /** Los destinos que no se aplican solos: exigen que alguien decida. */
    public function exigeConfirmacion(): bool
    {
        return match ($this) {
            self::CandidatoCierre, self::Revision, self::Conflicto => true,
            default => false,
        };
    }
}
