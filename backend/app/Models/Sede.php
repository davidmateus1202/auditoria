<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Extraccion\SugeridorNombres;
use App\Domain\Extraccion\TextoNormalizador;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sede extends Model
{
    use HasFactory;

    protected $table = 'sedes';

    protected $fillable = ['codigo', 'nombre', 'municipio', 'direccion', 'responsable', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(Auditoria::class);
    }

    public function hallazgos(): HasMany
    {
        return $this->hasMany(Hallazgo::class);
    }

    /**
     * Los archivos nombran la misma sede de formas distintas: la autoevaluación
     * dice «Centro de Salud Morichal» y el consolidado dice «MORICHAL». Se
     * resuelve por coincidencia del código dentro del texto normalizado.
     */
    /**
     * La sede registrada que más se parece a un texto que no se reconoció,
     * para proponerla. No resuelve: elegirla sigue siendo decisión de quien
     * sube el archivo.
     *
     * @return array{id: int, nombre: string}|null
     */
    public static function sugerirPorTexto(?string $texto): ?array
    {
        $candidatos = [];

        foreach (self::query()->where('activa', true)->get(['id', 'codigo', 'nombre']) as $sede) {
            $candidatos[$sede->nombre] = $sede->id;
            $candidatos[$sede->codigo] = $sede->id;
        }

        $mejor = SugeridorNombres::sugerir($texto, $candidatos);

        if ($mejor === null) {
            return null;
        }

        $sede = self::find($mejor['valor']);

        return $sede === null ? null : ['id' => $sede->id, 'nombre' => $sede->nombre];
    }

    public static function resolverPorTexto(?string $texto): ?self
    {
        $clave = TextoNormalizador::canonica($texto);

        if ($clave === '') {
            return null;
        }

        foreach (self::query()->get() as $sede) {
            $codigo = TextoNormalizador::canonica($sede->codigo);
            $nombre = TextoNormalizador::canonica($sede->nombre);

            if ($clave === $codigo || $clave === $nombre) {
                return $sede;
            }

            if ($codigo !== '' && preg_match('/\b'.preg_quote($codigo, '/').'\b/u', $clave) === 1) {
                return $sede;
            }
        }

        return null;
    }
}
