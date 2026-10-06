<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\TextoNormalizador;
use App\Http\Controllers\Controller;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use App\Models\Seguimiento;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HallazgoController extends Controller
{
    public function __construct(private readonly ServicioCorte $cortes) {}

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

    /**
     * Registra un hallazgo a mano, sin pasar por un Excel. Nace vigente en la
     * sede como cualquier otro y, si hay un corte abierto, entra directo como
     * reportado de ese mes — no «arrastrado» desde uno anterior, porque nunca
     * existió antes de ahora.
     */
    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'estandar_codigo' => ['required', 'string', 'exists:estandares,codigo'],
            'descripcion' => ['required', 'string', 'min:10'],
            'estado' => ['sometimes', 'string', 'in:abierto,abierto_evidencia,cerrado,sin_dato'],
            'accion_propuesta' => ['nullable', 'string'],
            'responsable' => ['nullable', 'string', 'max:200'],
            'evidencia' => ['nullable', 'string'],
        ]);

        $estado = EstadoHallazgo::from($datos['estado'] ?? 'abierto');

        $sede = Sede::findOrFail($datos['sede_id']);

        // Misma huella que calcula el extractor de Excel: si más adelante una
        // auditoría trae el mismo texto para esta sede, se reconcilia contra
        // este hallazgo en vez de duplicarlo.
        $huella = sha1(implode('|', [
            TextoNormalizador::canonica($sede->codigo),
            $datos['estandar_codigo'],
            TextoNormalizador::canonica($datos['descripcion']),
        ]));

        if (Hallazgo::query()->where('sede_id', $sede->id)->where('huella', $huella)->exists()) {
            return response()->json([
                'mensaje' => 'Ya existe un hallazgo con este mismo texto para esta sede.',
            ], 422);
        }

        $hallazgo = DB::transaction(function () use ($datos, $sede, $estado, $huella, $peticion): Hallazgo {
            $hallazgo = Hallazgo::create([
                'sede_id' => $sede->id,
                'estandar_codigo' => $datos['estandar_codigo'],
                'descripcion' => $datos['descripcion'],
                'huella' => $huella,
                'clasificacion' => 'hallazgo',
                'estado' => $estado,
                'accion_propuesta' => $datos['accion_propuesta'] ?? null,
                'responsable' => $datos['responsable'] ?? null,
                'evidencia' => $datos['evidencia'] ?? null,
                'cerrado_en' => $estado === EstadoHallazgo::Cerrado ? now()->toDateString() : null,
                'cerrado_por' => $estado === EstadoHallazgo::Cerrado ? $peticion->user()?->id : null,
            ]);

            $corteAbierto = Corte::abierto();

            if ($corteAbierto !== null) {
                HallazgoEstadoCorte::create([
                    'hallazgo_id' => $hallazgo->id,
                    'corte_id' => $corteAbierto->id,
                    'estado' => $estado,
                    'presente_en_corte' => true,
                    'meses_abierto' => $estado->esVigente() ? 1 : 0,
                    'accion_propuesta' => $datos['accion_propuesta'] ?? null,
                    'responsable' => $datos['responsable'] ?? null,
                    'evidencia' => $datos['evidencia'] ?? null,
                ]);
            }

            return $hallazgo;
        });

        return response()->json(['datos' => $hallazgo->load(['sede', 'estandar'])], 201);
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

        DB::transaction(function () use ($hallazgo, $nuevo, $anterior, $datos, $peticion): void {
            // También en la foto del corte abierto: de ahí sale el tablero.
            $this->cortes->registrarCambio($hallazgo, $nuevo, [
                'evidencia' => $datos['evidencia'] ?? $hallazgo->evidencia,
                'accion_propuesta' => $datos['accion_propuesta'] ?? $hallazgo->accion_propuesta,
                'responsable' => $datos['responsable'] ?? $hallazgo->responsable,
            ], $peticion->user());

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
        });

        return response()->json(['datos' => $hallazgo->fresh()]);
    }
}
