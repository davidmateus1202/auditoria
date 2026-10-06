<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una foto de la hoja de registro fotográfico de una autoevaluación. */
class FotoAuditoria extends Model
{
    protected $table = 'fotos_auditoria';

    protected $fillable = [
        'auditoria_id', 'hoja', 'celda', 'orden', 'ruta', 'ruta_miniatura',
        'mime', 'bytes', 'ancho', 'alto', 'hash_sha256',
    ];

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }
}
