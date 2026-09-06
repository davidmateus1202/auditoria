<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estandar extends Model
{
    protected $table = 'estandares';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['codigo', 'nombre', 'orden', 'es_normativo'];

    protected function casts(): array
    {
        return ['es_normativo' => 'boolean', 'orden' => 'integer'];
    }

    public function alias(): HasMany
    {
        return $this->hasMany(EstandarAlias::class, 'estandar_codigo', 'codigo');
    }

    public function hallazgos(): HasMany
    {
        return $this->hasMany(Hallazgo::class, 'estandar_codigo', 'codigo');
    }
}
