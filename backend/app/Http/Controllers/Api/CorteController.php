<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Http\Controllers\Controller;
use App\Models\Corte;
use App\Models\HallazgoEstadoCorte;
use App\Models\Reconciliacion;
use App\Services\Cortes\ExportadorMatriz;
use App\Services\Cortes\ImportadorMatriz;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CorteController extends Controller
{
    public function __construct(
        private readonly ServicioCorte $servicio,
        private readonly ExportadorMatriz $exportador,
        private readonly ImportadorMatriz $importador,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'datos' => Corte::query()->orderByDesc('periodo')->get(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'periodo' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return response()->json(['datos' => $this->servicio->abrir($datos['periodo'])], 201);
    }

    public function show(string $periodo): JsonResponse
    {
        return response()->json($this->servicio->estado($periodo));
    }

    /**
     * El detalle hallazgo por hallazgo del corte, para editarlo a mano desde
     * la pantalla sin pasar por el Excel. Es la misma foto que alimenta el
     * consolidado, así que un cambio aquí sí se refleja en él —a diferencia
     * de PUT /hallazgos/{id}/estado, que solo toca el estado «en vivo».
     */
    public function hallazgos(Request $peticion, string $periodo): JsonResponse
    {
        $datos = $peticion->validate([
            'sede_id' => ['sometimes', 'integer', 'exists:sedes,id'],
            'estandar' => ['sometimes', 'string', 'exists:estandares,codigo'],
            'estado' => ['sometimes', 'string', 'in:abierto,abierto_evidencia,cerrado,sin_dato'],
            'buscar' => ['sometimes', 'string', 'max:200'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $corte = Corte::query()->where('periodo', $periodo)->firstOrFail();

        $pagina = HallazgoEstadoCorte::query()
            ->where('hallazgo_estado_corte.corte_id', $corte->id)
            ->whereHas('hallazgo', function ($consulta) use ($datos) {
                $consulta
                    ->when(isset($datos['sede_id']), fn ($q) => $q->where('sede_id', $datos['sede_id']))
                    ->when(isset($datos['estandar']), fn ($q) => $q->where('estandar_codigo', $datos['estandar']))
                    ->when(
                        isset($datos['buscar']),
                        fn ($q) => $q->where('descripcion', 'like', '%'.$datos['buscar'].'%')
                    );
            })
            ->when(isset($datos['estado']), fn ($q) => $q->where('hallazgo_estado_corte.estado', $datos['estado']))
            ->with(['hallazgo.sede:id,codigo,nombre', 'hallazgo.estandar:codigo,nombre'])
            ->join('hallazgos', 'hallazgos.id', '=', 'hallazgo_estado_corte.hallazgo_id')
            ->orderBy('hallazgos.sede_id')
            ->orderBy('hallazgos.estandar_codigo')
            ->select('hallazgo_estado_corte.*')
            ->paginate($datos['por_pagina'] ?? 50);

        $pagina->through(fn (HallazgoEstadoCorte $foto) => [
            'hallazgo_id' => $foto->hallazgo_id,
            'descripcion' => $foto->hallazgo->descripcion,
            'sede' => $foto->hallazgo->sede,
            'estandar' => $foto->hallazgo->estandar,
            'estado' => $foto->estado->value,
            'presente_en_corte' => $foto->presente_en_corte,
            'meses_abierto' => $foto->meses_abierto,
            'accion_propuesta' => $foto->accion_propuesta,
            'responsable' => $foto->responsable,
            'evidencia' => $foto->evidencia,
        ]);

        return response()->json($pagina);
    }

    /** Descarga la matriz del mes en el formato de siempre. */
    public function matriz(string $periodo): BinaryFileResponse
    {
        $corte = Corte::query()->where('periodo', $periodo)->firstOrFail();
        $nombre = "seguimiento-hallazgos-{$periodo}.xlsx";
        $ruta = storage_path('app/exportaciones/'.$nombre);

        $this->exportador->exportar($corte, $ruta);

        return response()->download($ruta, $nombre)->deleteFileAfterSend();
    }

    /** Sube la matriz editada. Empareja por REF y detecta conflictos. */
    public function seguimiento(Request $peticion, string $periodo): JsonResponse
    {
        $peticion->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:25600'],
        ]);

        $ruta = $peticion->file('archivo')->store('cargas');

        // storage_path('app/'.$ruta) asumía la raíz vieja del disco «local»;
        // desde que el root es storage/app/private, hay que resolver la ruta
        // a través del disco en vez de reconstruirla a mano.
        return response()->json($this->importador->importar(Storage::path($ruta), $periodo));
    }

    /** Lo que quedó pendiente de decidir en el mes. */
    public function reconciliacion(string $periodo): JsonResponse
    {
        $corte = Corte::query()->where('periodo', $periodo)->firstOrFail();

        $pendientes = Reconciliacion::query()
            ->with('hallazgo.sede')
            ->where('corte_id', $corte->id)
            ->where('resuelto', false)
            ->get()
            ->groupBy(fn (Reconciliacion $r) => $r->destino->value);

        return response()->json([
            'periodo' => $corte->periodo,
            'pendientes' => $pendientes->map->count(),
            'datos' => $pendientes,
        ]);
    }

    public function actualizarHallazgo(Request $peticion, string $periodo, int $hallazgoId): JsonResponse
    {
        $datos = $peticion->validate([
            'estado' => ['required', 'string', 'in:abierto,abierto_evidencia,cerrado,sin_dato'],
            'accion_propuesta' => ['nullable', 'string'],
            'responsable' => ['nullable', 'string', 'max:200'],
            'evidencia' => ['nullable', 'string'],
        ]);

        $corte = Corte::query()->where('periodo', $periodo)->firstOrFail();

        if (! $corte->admiteEscritura()) {
            return response()->json(['mensaje' => "El corte {$periodo} está cerrado."], 422);
        }

        $foto = HallazgoEstadoCorte::query()
            ->where('corte_id', $corte->id)
            ->where('hallazgo_id', $hallazgoId)
            ->firstOrFail();

        if (Corte::abierto()?->is($corte)) {
            // El mes en curso: el hallazgo (lista, detalle) y la foto
            // (tablero, consolidado) cambian juntos para que no se contradigan.
            $this->servicio->registrarCambio(
                $foto->hallazgo,
                EstadoHallazgo::from($datos['estado']),
                Arr::only($datos, ['accion_propuesta', 'responsable', 'evidencia']),
                $peticion->user(),
            );
        } else {
            $foto->update([...$datos, 'presente_en_corte' => true]);
        }

        return response()->json(['datos' => $foto->fresh()]);
    }

    public function cerrar(Request $peticion, string $periodo): JsonResponse
    {
        return response()->json([
            'datos' => $this->servicio->cerrar($periodo, $peticion->user()),
        ]);
    }

    /**
     * Reabrir rompe la reproducibilidad de los consolidados ya entregados, así
     * que exige motivo y queda registrado.
     */
    public function reabrir(Request $peticion, string $periodo): JsonResponse
    {
        $datos = $peticion->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        return response()->json([
            'datos' => $this->servicio->reabrir($periodo, $datos['motivo'], $peticion->user()),
        ]);
    }
}
