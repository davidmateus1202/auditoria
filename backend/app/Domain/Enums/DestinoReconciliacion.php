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

    /**
     * El problema estaba cerrado y volvió a reportarse.
     *
     * Registrarlo como «nuevo» sería perder la señal más valiosa que tiene el
     * sistema: un hallazgo que se cierra y reaparece indica que el cierre fue
     * cosmético. Es lo que gerencia necesita ver.
     */
    case Reincidencia = 'reincidencia';

    case CandidatoCierre = 'candidato_cierre';
    case NoVerificado = 'no_verificado';
    case AusenteDelCorte = 'ausente_corte';
    case Revision = 'revision';
    case Conflicto = 'conflicto';

    /** Los destinos que no se aplican solos: exigen que alguien decida. */
    public function exigeConfirmacion(): bool
    {
        return match ($this) {
            self::CandidatoCierre, self::Revision, self::Conflicto, self::Reincidencia => true,
            default => false,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Persiste => 'Persiste',
            self::Nuevo => 'Nuevo',
            self::Reincidencia => 'Reincidencia',
            self::CandidatoCierre => 'Candidato a cierre',
            self::NoVerificado => 'No verificado',
            self::AusenteDelCorte => 'Ausente del corte',
            self::Revision => 'Requiere revisión',
            self::Conflicto => 'Conflicto',
        };
    }
}
