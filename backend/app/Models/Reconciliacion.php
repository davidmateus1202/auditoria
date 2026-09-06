<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\DestinoReconciliacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una propuesta del reconciliador esperando que alguien la confirme. */
class Reconciliacion extends Model
{
    protected $table = 'reconciliaciones';

    protected $fillable = [
        'flujo', 'auditoria_id', 'corte_id', 'hallazgo_id', 'destino',
        'texto_entrante', 'similitud', 'margen', 'resuelto', 'resuelto_por', 'resuelto_en',
    ];

    protected function casts(): array
    {
        return [
            'destino' => DestinoReconciliacion::class,
            'similitud' => 'float',
            'margen' => 'float',
            'resuelto' => 'boolean',
            'resuelto_en' => 'datetime',
        ];
    }

    public function hallazgo(): BelongsTo
    {
        return $this->belongsTo(Hallazgo::class);
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class);
    }
}
