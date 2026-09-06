<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoFormato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivoCargado extends Model
{
    protected $table = 'archivos_cargados';

    protected $fillable = [
        'auditoria_id', 'nombre_original', 'ruta', 'hash_sha256',
        'formato', 'filas_leidas', 'resumen', 'procesado_en',
    ];

    protected function casts(): array
    {
        return [
            'formato' => TipoFormato::class,
            'resumen' => 'array',
            'procesado_en' => 'datetime',
        ];
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }
}
