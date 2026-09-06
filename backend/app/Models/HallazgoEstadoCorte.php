<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\EstadoHallazgo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La foto de un hallazgo en un mes concreto. */
class HallazgoEstadoCorte extends Model
{
    protected $table = 'hallazgo_estado_corte';

    protected $fillable = [
        'hallazgo_id', 'corte_id', 'estado', 'presente_en_corte',
        'meses_abierto', 'evidencia', 'accion_propuesta', 'responsable', 'huella_exportada',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoHallazgo::class,
            'presente_en_corte' => 'boolean',
            'meses_abierto' => 'integer',
        ];
    }

    public function hallazgo(): BelongsTo
    {
        return $this->belongsTo(Hallazgo::class);
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class);
    }

    /**
     * Huella de los campos que el Excel puede modificar.
     *
     * Comparar la huella guardada al exportar contra la actual dice si alguien
     * tocó el hallazgo dentro de la app mientras el archivo estaba afuera. Si
     * cambiaron los dos lados es conflicto, y lo resuelve una persona.
     */
    public function huellaEditables(): string
    {
        return self::calcularHuella(
            $this->estado,
            $this->accion_propuesta,
            $this->responsable,
            $this->evidencia
        );
    }

    public static function calcularHuella(
        EstadoHallazgo $estado,
        ?string $accion,
        ?string $responsable,
        ?string $evidencia
    ): string {
        return sha1(implode('|', [$estado->value, (string) $accion, (string) $responsable, (string) $evidencia]));
    }
}
