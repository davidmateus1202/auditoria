<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\EstadoHallazgo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un hallazgo pertenece a la SEDE, no a la auditoría: atraviesa varias.
 * Nace en una, se reporta de nuevo en la siguiente, y en algún momento deja de
 * aparecer y se cierra. Ese linaje es lo que da sentido al cierre.
 */
class Hallazgo extends Model
{
    use HasFactory;

    protected $table = 'hallazgos';

    protected $fillable = [
        'sede_id', 'estandar_codigo', 'servicio_id', 'auditoria_origen_id',
        'descripcion', 'huella', 'clasificacion', 'estado',
        'accion_propuesta', 'responsable', 'fecha_compromiso', 'evidencia',
        'veces_reportado', 'requiere_revision', 'cerrado_en', 'cerrado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoHallazgo::class,
            'clasificacion' => ClasificacionHallazgo::class,
            'requiere_revision' => 'boolean',
            'fecha_compromiso' => 'date',
            'cerrado_en' => 'date',
            'veces_reportado' => 'integer',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function estandar(): BelongsTo
    {
        return $this->belongsTo(Estandar::class, 'estandar_codigo', 'codigo');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }

    public function apariciones(): HasMany
    {
        return $this->hasMany(HallazgoAparicion::class);
    }

    public function seguimientos(): HasMany
    {
        return $this->hasMany(Seguimiento::class);
    }

    public function estadosPorCorte(): HasMany
    {
        return $this->hasMany(HallazgoEstadoCorte::class);
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class);
    }

    /** Los que todavía exigen gestión: el conjunto contra el que se reconcilia. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('estado', '!=', EstadoHallazgo::Cerrado->value);
    }

    public function scopeDeSede(Builder $q, int $sedeId): Builder
    {
        return $q->where('sede_id', $sedeId);
    }

    public function scopeDeEstandar(Builder $q, string $codigo): Builder
    {
        return $q->where('estandar_codigo', $codigo);
    }

    /**
     * Token estable que viaja en la columna REF de la matriz exportada.
     *
     * El verificador evita que una fila corrida o un identificador tecleado a
     * mano se acepten como válidos: ante la duda se cae al emparejamiento por
     * texto, que ya está calibrado.
     */
    public function referencia(): string
    {
        return sprintf('H-%d-%s', $this->id, $this->verificador());
    }

    private function verificador(): string
    {
        return substr(sha1($this->id.'|'.$this->huella), 0, 4);
    }

    /** Devuelve el id del hallazgo si el token es válido; null si no. */
    public static function interpretarReferencia(?string $token): ?int
    {
        $token = trim((string) $token);

        if (preg_match('/^H-(\d+)-[0-9a-f]{4}$/i', $token) !== 1) {
            return null;
        }

        $id = (int) explode('-', $token)[1];
        $hallazgo = self::find($id);

        if ($hallazgo === null || ! hash_equals($hallazgo->referencia(), $token)) {
            return null;
        }

        return $id;
    }
}
