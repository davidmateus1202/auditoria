<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\OrigenAuditoria;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auditoria extends Model
{
    use HasFactory;

    protected $table = 'auditorias';

    protected $fillable = [
        'sede_id', 'version', 'periodo', 'fecha_auditoria', 'fecha_informe',
        'auditor', 'responsable', 'origen', 'estado', 'normativa_declarada',
    ];

    protected function casts(): array
    {
        return [
            'fecha_auditoria' => 'date',
            'fecha_informe' => 'date',
            'origen' => OrigenAuditoria::class,
            'version' => 'integer',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoCargado::class);
    }

    public function criterios(): HasMany
    {
        return $this->hasMany(EvaluacionCriterio::class);
    }

    public function apariciones(): HasMany
    {
        return $this->hasMany(HallazgoAparicion::class);
    }

    /** Cada carga crea una versión nueva y conserva las anteriores. */
    public static function siguienteVersion(int $sedeId): int
    {
        return (int) self::query()->where('sede_id', $sedeId)->max('version') + 1;
    }
}
