<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Enums\EstadoHallazgo;
use App\Http\Controllers\Controller;
use App\Models\Hallazgo;
use App\Models\Seguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HallazgoController extends Controller
{
    /**
     * Listado filtrable. Los filtros por sede, estándar y sede+estándar son los
     * tres cortes que pidió el usuario, aquí a nivel de detalle.
     */
    public function index(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sede_id' => ['sometimes', 'integer', 'exists:sedes,id'],
            'estandar' => ['sometimes', 'string', 'exists:estandares,codigo'],
            'estado' => ['sometimes', 'string', 'in:abierto,abierto_evidencia,cerrado,sin_dato'],
            'servicio_id' => ['sometimes', 'integer', 'exists:servicios,id'],
            'buscar' => ['sometimes', 'string', 'max:200'],
            'solo_vigentes' => ['sometimes', 'boolean'],
            'orden' => ['sometimes', 'in:antiguedad,sede,estandar'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $consulta = Hallazgo::query()
            ->with(['sede:id,codigo,nombre', 'estandar:codigo,nombre', 'servicio:id,nombre'])
            ->when(isset($datos['sede_id']), fn ($q) => $q->deSede($datos['sede_id']))
            ->when(isset($datos['estandar']), fn ($q) => $q->deEstandar($datos['estandar']))
            ->when(isset($datos['estado']), fn ($q) => $q->where('estado', $datos['estado']))
            ->when(isset($datos['servicio_id']), fn ($q) => $q->where('servicio_id', $datos['servicio_id']))
            ->when(
                isset($datos['buscar']),
                fn ($q) => $q->where('descripcion', 'like', '%'.$datos['buscar'].'%')
            )
            ->when($peticion->boolean('solo_vigentes'), fn ($q) => $q->vigentes());

        $consulta = match ($datos['orden'] ?? 'sede') {
            // Un hallazgo abierto hace catorce meses pide atención antes que
            // uno del mes pasado.
            'antiguedad' => $consulta->orderByDesc('veces_reportado')->orderBy('created_at'),
            'estandar' => $consulta->orderBy('estandar_codigo'),
            default => $consulta->orderBy('sede_id')->orderBy('estandar_codigo'),
        };

        return response()->json(
            $consulta->paginate($datos['por_pagina'] ?? 50)
        );
    }

    public function show(Hallazgo $hallazgo): JsonResponse
    {
        $hallazgo->load(['sede', 'estandar', 'servicio', 'evidencias']);

        return response()->json(['datos' => $hallazgo]);
    }

    /** Cuándo nació, en qué auditorías se repitió y por qué estados pasó. */
    public function lineaTiempo(Hallazgo $hallazgo): JsonResponse
    {
        return response()->json([
            'hallazgo' => $hallazgo->only(['id', 'descripcion', 'estandar_codigo', 'estado']),
            'apariciones' => $hallazgo->apariciones()->with('auditoria:id,periodo,fecha_auditoria')->get(),
            'por_corte' => $hallazgo->estadosPorCorte()->with('corte:id,periodo')->get()
                ->sortBy(fn ($f) => $f->corte->periodo)->values(),
            'seguimientos' => $hallazgo->seguimientos()->orderBy('created_at')->get(),
        ]);
    }

    public function cambiarEstado(Request $peticion, Hallazgo $hallazgo): JsonResponse
    {
        $datos = $peticion->validate([
            'estado' => ['required', 'string', 'in:abierto,abierto_evidencia,cerrado,sin_dato'],
            'evidencia' => ['nullable', 'string'],
            'accion_propuesta' => ['nullable', 'string'],
            'responsable' => ['nullable', 'string', 'max:200'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $nuevo = EstadoHallazgo::from($datos['estado']);
        $anterior = $hallazgo->estado;

        // Cerrar sin dejar constancia de con qué se cerró es lo que hace que un
        // consolidado no se pueda defender.
        if ($nuevo === EstadoHallazgo::Cerrado && blank($datos['evidencia'] ?? null)) {
            return response()->json([
                'mensaje' => 'Cerrar un hallazgo exige registrar la evidencia del cumplimiento.',
            ], 422);
        }

        $hallazgo->update([
            'estado' => $nuevo,
            'evidencia' => $datos['evidencia'] ?? $hallazgo->evidencia,
            'accion_propuesta' => $datos['accion_propuesta'] ?? $hallazgo->accion_propuesta,
            'responsable' => $datos['responsable'] ?? $hallazgo->responsable,
            'cerrado_en' => $nuevo === EstadoHallazgo::Cerrado ? now()->toDateString() : null,
            'cerrado_por' => $nuevo === EstadoHallazgo::Cerrado ? $peticion->user()?->id : null,
        ]);

        Seguimiento::create([
            'hallazgo_id' => $hallazgo->id,
            'usuario_id' => $peticion->user()?->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'accion_propuesta' => $datos['accion_propuesta'] ?? null,
            'responsable' => $datos['responsable'] ?? null,
            'evidencia' => $datos['evidencia'] ?? null,
            'motivo' => $datos['motivo'] ?? null,
        ]);

        return response()->json(['datos' => $hallazgo->fresh()]);
    }
}
