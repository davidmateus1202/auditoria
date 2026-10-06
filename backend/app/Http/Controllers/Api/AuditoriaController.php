<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Domain\Extraccion\TextoNormalizador;
use App\Http\Controllers\Controller;
use App\Domain\Enums\EstadoCorte;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\HallazgoAparicion;
use App\Models\HallazgoEstadoCorte;
use App\Models\Reconciliacion;
use App\Services\Auditorias\AplicadorReconciliacion;
use App\Services\Auditorias\CargadorAuditoria;
use App\Services\Auditorias\Excepciones\SedeNoIdentificada;
use App\Services\Evidencias\ServicioFotos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
        private readonly ServicioFotos $fotos,
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
                ->withCount(['criterios', 'apariciones', 'archivos'])
                ->when(isset($datos['sede_id']), fn ($q) => $q->where('sede_id', $datos['sede_id']))
                ->when(isset($datos['estado']), fn ($q) => $q->where('estado', $datos['estado']))
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * Devuelve el archivo original tal como se subió, para previsualizarlo o
     * descargarlo. Una auditoría de línea base no tiene archivo propio.
     */
    public function archivo(Auditoria $auditoria): HttpResponse
    {
        $archivo = $auditoria->archivos()->latest('id')->first();

        if ($archivo === null || ! Storage::exists($archivo->ruta)) {
            return response()->json([
                'mensaje' => 'No hay un archivo original para esta auditoría.',
            ], 404);
        }

        return Storage::download($archivo->ruta, $archivo->nombre_original);
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
            // Celdas corregidas en la vista previa: [{hoja, celda, valor}],
            // como JSON porque viajan junto al archivo en un multipart.
            'correcciones' => ['nullable', 'json'],
        ]);

        $correcciones = $this->correcciones($peticion->input('correcciones'));
        $cargadas = [];
        $fallidas = [];

        foreach ($peticion->file('archivos') as $archivo) {
            try {
                $carga = $this->cargador->cargar(
                    $archivo,
                    $peticion->integer('sede_id') ?: null,
                    $peticion->input('periodo'),
                    $correcciones,
                );

                $cargadas[] = [
                    'auditoria_id' => $carga['auditoria']->id,
                    'archivo' => $archivo->getClientOriginalName(),
                    'sede' => $carga['auditoria']->sede->nombre,
                    'version' => $carga['auditoria']->version,
                    'extraccion' => $carga['resultado']->resumen(),
                    'reconciliacion' => $carga['propuesta'],
                    'fotos' => $carga['fotos'],
                ];
            } catch (EstructuraInvalida $e) {
                // El extractor rechaza en vez de adivinar, y dice hoja y fila:
                // esa precisión tiene que llegar a la pantalla.
                $fallidas[] = [
                    'archivo' => $archivo->getClientOriginalName(),
                    ...$e->contexto(),
                ];
            } catch (SedeNoIdentificada $e) {
                // A diferencia de EstructuraInvalida, esto sí lo puede resolver
                // quien sube el archivo: eligiendo o creando la sede desde la
                // pantalla, sin tocar el Excel.
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
     * [{hoja, celda, valor}] → [«HOJA!A12» => valor], la forma en que el lector
     * las busca. Una corrección mal formada se rechaza entera: aplicar la
     * mitad leería un archivo que nadie vio.
     *
     * @return array<string, string>
     */
    private function correcciones(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $lista = json_decode($json, true);
        $invalido = ValidationException::withMessages(['correcciones' => 'Las correcciones no tienen el formato esperado.']);

        if (! is_array($lista) || ! array_is_list($lista) || count($lista) > 500) {
            throw $invalido;
        }

        $mapa = [];

        foreach ($lista as $correccion) {
            $hoja = $correccion['hoja'] ?? null;
            $celda = strtoupper((string) ($correccion['celda'] ?? ''));
            $valor = $correccion['valor'] ?? null;

            if (! is_string($hoja) || trim($hoja) === '' || preg_match('/^[A-Z]{1,3}[1-9]\d{0,6}$/', $celda) !== 1
                || ! is_string($valor) || mb_strlen($valor) > 2000) {
                throw $invalido;
            }

            $mapa[TextoNormalizador::canonica($hoja).'!'.$celda] = $valor;
        }

        return $mapa;
    }

    /**
     * Elimina una versión. Cada carga conserva las anteriores, y solo se borra
     * si el usuario lo pide de forma explícita.
     */
    public function destroy(Request $peticion, Auditoria $auditoria): JsonResponse
    {
        // Hallazgos que nacieron de esta auditoría. Los que también aparecen en
        // otra se conservan (esa otra los respalda) y pasan a tener esa como
        // origen; los demás se eliminan con ella, para que las cifras de toda
        // la app dejen de contarlos.
        $nacidos = Hallazgo::query()->where('auditoria_origen_id', $auditoria->id)->get(['id']);
        $respaldo = HallazgoAparicion::query()
            ->whereIn('hallazgo_id', $nacidos->pluck('id'))
            ->where('auditoria_id', '!=', $auditoria->id)
            ->groupBy('hallazgo_id')
            ->selectRaw('hallazgo_id, MIN(auditoria_id) as auditoria_id')
            ->pluck('auditoria_id', 'hallazgo_id');
        $aEliminar = $nacidos->pluck('id')->reject(fn ($id) => $respaldo->has($id))->values();

        // Hallazgos de otras auditorías que esta solo volvió a reportar: se
        // conservan, pierden la aparición.
        $vinculados = $auditoria->apariciones()
            ->whereNotIn('hallazgo_id', $nacidos->pluck('id'))
            ->distinct()
            ->count('hallazgo_id');
        $conservados = $vinculados + $respaldo->count();

        if (($aEliminar->isNotEmpty() || $conservados > 0) && ! $peticion->boolean('forzar')) {
            $enMesesCerrados = HallazgoEstadoCorte::query()
                ->whereIn('hallazgo_id', $aEliminar)
                ->whereHas('corte', fn ($q) => $q->where('estado', EstadoCorte::Cerrado->value))
                ->distinct()
                ->count('hallazgo_id');

            $partes = [];
            if ($aEliminar->isNotEmpty()) {
                $partes[] = "Se eliminarán {$aEliminar->count()} hallazgos que nacieron de esta auditoría, ".
                    'con su seguimiento, y dejarán de contar en el tablero y el consolidado.';
            }
            if ($enMesesCerrados > 0) {
                $partes[] = "{$enMesesCerrados} de ellos figuran en meses ya cerrados: las cifras de esos meses también cambiarán.";
            }
            if ($conservados > 0) {
                $partes[] = "{$conservados} hallazgos se conservan porque también aparecen en otras auditorías.";
            }

            return response()->json([
                'mensaje' => implode(' ', $partes),
                'requiere_confirmacion' => true,
                'hallazgos_a_eliminar' => $aEliminar->count(),
                'hallazgos_conservados' => $conservados,
            ], 409);
        }

        // El archivo original no cuelga de una foreign key en cascada —se
        // conserva aposta cuando una auditoría se reemplaza— así que hay que
        // borrarlo a mano: primero el binario del disco, luego su registro.
        // Si no se hiciera, «eliminar» dejaría el archivo y su fila huérfanos.
        DB::transaction(function () use ($auditoria, $aEliminar, $respaldo): void {
            foreach ($respaldo as $hallazgoId => $otraAuditoria) {
                Hallazgo::query()->whereKey($hallazgoId)->update(['auditoria_origen_id' => $otraAuditoria]);
            }

            // En cascada se van sus fotos por corte, seguimientos, apariciones
            // y evidencias.
            Hallazgo::query()->whereIn('id', $aEliminar)->delete();

            foreach ($auditoria->archivos as $archivo) {
                Storage::delete($archivo->ruta);
                $archivo->delete();
            }

            // Las filas se van en cascada, pero los archivos de imagen no.
            $this->fotos->eliminar($auditoria);

            $auditoria->delete();
        });

        return response()->json([
            'mensaje' => 'Auditoría eliminada.',
            'hallazgos_eliminados' => $aEliminar->count(),
        ]);
    }
}
