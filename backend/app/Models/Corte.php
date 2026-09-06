<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoCorte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un mes de seguimiento. Al cerrarlo se congela la foto, y el consolidado de
 * ese periodo queda reproducible para siempre.
 */
class Corte extends Model
{
    protected $table = 'cortes';

    protected $fillable = ['periodo', 'fecha_corte', 'estado', 'cerrado_por', 'cerrado_en', 'motivo_reapertura'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCorte::class,
            'fecha_corte' => 'date',
            'cerrado_en' => 'datetime',
        ];
    }

    public function estados(): HasMany
    {
        return $this->hasMany(HallazgoEstadoCorte::class);
    }

    public function admiteEscritura(): bool
    {
        return $this->estado->admiteEscritura();
    }

    public static function ultimoCerrado(): ?self
    {
        return self::query()
            ->where('estado', EstadoCorte::Cerrado->value)
            ->orderByDesc('periodo')
            ->first();
    }

    public static function abierto(): ?self
    {
        return self::query()
            ->where('estado', EstadoCorte::Abierto->value)
            ->orderByDesc('periodo')
            ->first();
    }
}
