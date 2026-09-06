<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\MarcaCriterio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionCriterio extends Model
{
    protected $table = 'evaluaciones_criterio';

    protected $fillable = [
        'auditoria_id', 'servicio_id', 'estandar_codigo',
        'criterio', 'marca', 'observacion', 'hoja', 'fila',
    ];

    protected function casts(): array
    {
        return ['marca' => MarcaCriterio::class];
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }
}
