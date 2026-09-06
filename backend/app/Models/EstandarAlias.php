<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstandarAlias extends Model
{
    protected $table = 'estandar_alias';

    protected $fillable = ['estandar_codigo', 'alias_normalizado', 'origen'];

    public function estandar(): BelongsTo
    {
        return $this->belongsTo(Estandar::class, 'estandar_codigo', 'codigo');
    }
}
