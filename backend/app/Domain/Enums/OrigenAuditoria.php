<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum OrigenAuditoria: string
{
    case Autoevaluacion = 'autoevaluacion';
    case LineaBase = 'linea_base';
}
