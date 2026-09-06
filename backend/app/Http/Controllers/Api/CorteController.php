<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Enums\DestinoReconciliacion;
use App\Http\Controllers\Controller;
use App\Models\Corte;
use App\Models\HallazgoEstadoCorte;
use App\Models\Reconciliacion;
use App\Services\Cortes\ExportadorMatriz;
use App\Services\Cortes\ImportadorMatriz;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json($this->importador->importar(storage_path('app/'.$ruta), $periodo));
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

        $foto->update([...$datos, 'presente_en_corte' => true]);

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
