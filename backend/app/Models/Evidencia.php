<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidencia extends Model
{
    protected $table = 'evidencias';

    protected $fillable = ['hallazgo_id', 'ruta', 'tipo', 'descripcion', 'tomada_en'];

    protected function casts(): array
    {
        return ['tomada_en' => 'datetime'];
    }

    public function hallazgo(): BelongsTo
    {
        return $this->belongsTo(Hallazgo::class);
    }
}
