<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoHallazgo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cada cambio de estado, con quién lo hizo y con qué soporte. */
class Seguimiento extends Model
{
    protected $table = 'seguimientos';

    protected $fillable = [
        'hallazgo_id', 'usuario_id', 'estado_anterior', 'estado_nuevo',
        'accion_propuesta', 'responsable', 'evidencia', 'motivo',
    ];

    protected function casts(): array
    {
        return [
            'estado_anterior' => EstadoHallazgo::class,
            'estado_nuevo' => EstadoHallazgo::class,
        ];
    }

    public function hallazgo(): BelongsTo
    {
        return $this->belongsTo(Hallazgo::class);
    }
}
