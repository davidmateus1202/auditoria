<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Enums\EstadoHallazgo;
use App\Http\Controllers\Controller;
use App\Models\Sede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SedeController extends Controller
{
    /** Listado con el conteo de hallazgos vigentes, que es lo que se ve primero. */
    public function index(Request $peticion): JsonResponse
    {
        $sedes = Sede::query()
            ->when($peticion->boolean('solo_activas', true), fn ($q) => $q->where('activa', true))
            ->when($peticion->filled('buscar'), function ($q) use ($peticion) {
                $texto = '%'.$peticion->string('buscar')->value().'%';
                $q->where(fn ($s) => $s->where('nombre', 'like', $texto)->orWhere('codigo', 'like', $texto));
            })
            ->withCount([
                'hallazgos as hallazgos_vigentes' => fn ($q) => $q->where('estado', '!=', EstadoHallazgo::Cerrado->value),
                'hallazgos as hallazgos_cerrados' => fn ($q) => $q->where('estado', EstadoHallazgo::Cerrado->value),
                'auditorias',
            ])
            ->orderBy('nombre')
            ->get();

        return response()->json(['datos' => $sedes]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $this->validar($peticion);

        return response()->json(['datos' => Sede::create($datos)], 201);
    }

    public function show(Sede $sede): JsonResponse
    {
        $sede->loadCount([
            'hallazgos as hallazgos_vigentes' => fn ($q) => $q->where('estado', '!=', EstadoHallazgo::Cerrado->value),
        ]);

        return response()->json(['datos' => $sede]);
    }

    public function update(Request $peticion, Sede $sede): JsonResponse
    {
        $sede->update($this->validar($peticion, $sede));

        return response()->json(['datos' => $sede->fresh()]);
    }

    /**
     * Una sede con auditorías no se borra: se desactiva. Borrarla arrastraría
     * hallazgos y cierres, y con ellos la trazabilidad de por qué se cerró algo.
     */
    public function destroy(Sede $sede): JsonResponse
    {
        if ($sede->auditorias()->exists()) {
            $sede->update(['activa' => false]);

            return response()->json([
                'mensaje' => 'La sede tiene auditorías registradas, así que se desactivó en lugar de eliminarse.',
                'datos' => $sede->fresh(),
            ]);
        }

        $sede->delete();

        return response()->json(['mensaje' => 'Sede eliminada.']);
    }

    private function validar(Request $peticion, ?Sede $sede = null): array
    {
        return $peticion->validate([
            'codigo' => ['required', 'string', 'max:20', Rule::unique('sedes', 'codigo')->ignore($sede)],
            'nombre' => ['required', 'string', 'max:150'],
            'municipio' => ['sometimes', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:150'],
            'activa' => ['sometimes', 'boolean'],
        ]);
    }
}
