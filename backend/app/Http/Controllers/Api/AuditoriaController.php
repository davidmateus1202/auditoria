<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Reconciliacion;
use App\Services\Auditorias\AplicadorReconciliacion;
use App\Services\Auditorias\CargadorAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Carga de autoevaluaciones: la entrada principal del sistema.
 *
 * Una carga no publica nada. Deja la auditoría «por confirmar» con la
 * propuesta del reconciliador, y solo cuando alguien resuelve las decisiones
 * pendientes se aplican los cambios.
 */
class AuditoriaController extends Controller
{
    public function __construct(
        private readonly CargadorAuditoria $cargador,
        private readonly AplicadorReconciliacion $aplicador,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sede_id' => ['sometimes', 'integer', 'exists:sedes,id'],
            'estado' => ['sometimes', 'in:procesando,por_confirmar,publicada,fallida'],
        ]);

        return response()->json([
            'datos' => Auditoria::query()
                ->with('sede:id,codigo,nombre')
                ->withCount(['criterios', 'apariciones'])
                ->when(isset($datos['sede_id']), fn ($q) => $q->where('sede_id', $datos['sede_id']))
                ->when(isset($datos['estado']), fn ($q) => $q->where('estado', $datos['estado']))
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * Sube una o varias autoevaluaciones. Cada archivo se procesa por separado
     * para que el fallo de uno no tumbe la carga de los demás.
     */
    public function cargar(Request $peticion): JsonResponse
    {
        $peticion->validate([
            'archivos' => ['required', 'array', 'min:1', 'max:12'],
            'archivos.*' => ['file', 'mimes:xlsx,xls', 'max:25600'],
            'sede_id' => ['nullable', 'integer', 'exists:sedes,id'],
            'periodo' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $cargadas = [];
        $fallidas = [];

        foreach ($peticion->file('archivos') as $archivo) {
            try {
                $carga = $this->cargador->cargar(
                    $archivo,
                    $peticion->integer('sede_id') ?: null,
                    $peticion->input('periodo'),
                );

                $cargadas[] = [
                    'auditoria_id' => $carga['auditoria']->id,
                    'archivo' => $archivo->getClientOriginalName(),
                    'sede' => $carga['auditoria']->sede->nombre,
                    'version' => $carga['auditoria']->version,
                    'extraccion' => $carga['resultado']->resumen(),
                    'reconciliacion' => $carga['propuesta'],
                ];
            } catch (EstructuraInvalida $e) {
                // El extractor rechaza en vez de adivinar, y dice hoja y fila:
                // esa precisión tiene que llegar a la pantalla.
                $fallidas[] = [
                    'archivo' => $archivo->getClientOriginalName(),
                    ...$e->contexto(),
                ];
            } catch (RuntimeException $e) {
                $fallidas[] = [
                    'archivo' => $archivo->getClientOriginalName(),
                    'mensaje' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'cargadas' => $cargadas,
            'fallidas' => $fallidas,
        ], $cargadas === [] ? 422 : 201);
    }

    /** La propuesta agrupada por destino, con lo pendiente arriba. */
    public function reconciliacion(Auditoria $auditoria): JsonResponse
    {
        $movimientos = Reconciliacion::query()
            ->with('hallazgo:id,descripcion,estandar_codigo,estado')
            ->where('auditoria_id', $auditoria->id)
            ->where('resuelto', false)
            ->get()
            ->map(fn (Reconciliacion $r) => [
                'id' => $r->id,
                'destino' => $r->destino->value,
                'etiqueta' => $r->destino->etiqueta(),
                'exige_confirmacion' => $r->destino->exigeConfirmacion(),
                'estandar' => $r->estandar_codigo ?? $r->hallazgo?->estandar_codigo,
                'texto_entrante' => $r->texto_entrante,
                'texto_vigente' => $r->hallazgo?->descripcion,
                'hallazgo_id' => $r->hallazgo_id,
                'similitud' => $r->similitud,
            ]);

        return response()->json([
            'auditoria' => [
                'id' => $auditoria->id,
                'sede' => $auditoria->sede->nombre,
                'version' => $auditoria->version,
                'periodo' => $auditoria->periodo,
                'estado' => $auditoria->estado,
            ],
            'pendientes_de_decision' => $movimientos->where('exige_confirmacion', true)->count(),
            'por_destino' => $movimientos->groupBy('destino')->map->values(),
        ]);
    }

    /**
     * Aplica la propuesta. Falla si queda alguna decisión sin tomar: cerrar un
     * hallazgo porque un archivo dejó de mencionarlo tiene que firmarlo alguien.
     */
    public function confirmar(Request $peticion, Auditoria $auditoria): JsonResponse
    {
        $datos = $peticion->validate([
            'decisiones' => ['sometimes', 'array'],
            'decisiones.*.accion' => ['required', 'string'],
            'decisiones.*.hallazgo_id' => ['nullable', 'integer', 'exists:hallazgos,id'],
            'decisiones.*.evidencia' => ['nullable', 'string'],
        ]);

        try {
            $conteo = $this->aplicador->confirmar(
                $auditoria,
                $datos['decisiones'] ?? [],
                $peticion->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => 'Auditoría publicada.',
            'auditoria_id' => $auditoria->id,
            'aplicado' => $conteo,
        ]);
    }

    /**
     * Elimina una versión. Cada carga conserva las anteriores, y solo se borra
     * si el usuario lo pide de forma explícita.
     */
    public function destroy(Auditoria $auditoria): JsonResponse
    {
        if ($auditoria->estado === 'publicada' && $auditoria->apariciones()->exists()) {
            return response()->json([
                'mensaje' => 'Esta auditoría ya está publicada y hay hallazgos que dependen de ella. '.
                    'Eliminarla dejaría sin respaldo los cierres que salieron de este cruce.',
            ], 422);
        }

        $auditoria->delete();

        return response()->json(['mensaje' => 'Auditoría eliminada.']);
    }
}
