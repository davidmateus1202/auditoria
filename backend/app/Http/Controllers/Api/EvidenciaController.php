<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\FotoAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las fotos de evidencia de las auditorías cargadas, agrupadas por auditoría.
 */
class EvidenciaController extends Controller
{
    /** Cada cuánto cambia la firma de las URL: dentro del bloque, el navegador reutiliza su caché. */
    private const BLOQUE_FIRMA = 6 * 3600;

    public function index(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sede_id' => ['sometimes', 'integer', 'exists:sedes,id'],
            'auditoria_id' => ['sometimes', 'integer', 'exists:auditorias,id'],
        ]);

        $auditorias = Auditoria::query()
            ->whereHas('fotos')
            ->with(['sede:id,codigo,nombre', 'fotos'])
            ->when(isset($datos['sede_id']), fn ($q) => $q->where('sede_id', $datos['sede_id']))
            ->when(isset($datos['auditoria_id']), fn ($q) => $q->whereKey($datos['auditoria_id']))
            ->get()
            ->sortBy([
                fn ($a, $b) => strcmp((string) $a->sede?->nombre, (string) $b->sede?->nombre),
                fn ($a, $b) => $b->version <=> $a->version,
            ])
            ->values();

        $vence = $this->vencimiento();

        return response()->json([
            'total' => $auditorias->sum(fn ($a) => $a->fotos->count()),
            'datos' => $auditorias->map(fn (Auditoria $a) => [
                'auditoria' => [
                    'id' => $a->id,
                    'version' => $a->version,
                    'periodo' => $a->periodo,
                    'estado' => $a->estado,
                    'origen' => $a->origen,
                    'sede' => $a->sede?->only(['id', 'codigo', 'nombre']),
                ],
                'fotos' => $a->fotos->map(fn (FotoAuditoria $f) => [
                    'id' => $f->id,
                    'hoja' => $f->hoja,
                    'celda' => $f->celda,
                    'orden' => $f->orden,
                    'ancho' => $f->ancho,
                    'alto' => $f->alto,
                    'bytes' => $f->bytes,
                    'miniatura_url' => $this->url($f, 'miniatura', $vence),
                    'imagen_url' => $this->url($f, 'completa', $vence),
                ])->values(),
            ]),
        ]);
    }

    /**
     * La imagen en sí. Va con URL firmada y no con el token: una etiqueta
     * <img> no puede mandar el encabezado Authorization, y la firma solo la
     * entrega el listado, que sí exige sesión.
     */
    public function imagen(Request $peticion, FotoAuditoria $foto): Response
    {
        $miniatura = $peticion->query('tamano') === 'miniatura';
        $ruta = $miniatura ? $foto->ruta_miniatura : $foto->ruta;

        if (! Storage::exists($ruta)) {
            return response()->json(['mensaje' => 'La imagen ya no está disponible.'], 404);
        }

        return response()->file(Storage::path($ruta), [
            'Content-Type' => $miniatura && $ruta !== $foto->ruta ? 'image/jpeg' : $foto->mime,
            'Cache-Control' => 'private, max-age='.self::BLOQUE_FIRMA,
        ]);
    }

    /**
     * Vencimiento redondeado a bloques fijos: si cambiara en cada petición,
     * cada recarga del listado produciría URLs nuevas y el navegador volvería
     * a descargar todas las fotos.
     */
    private function vencimiento(): Carbon
    {
        $bloque = intdiv(time(), self::BLOQUE_FIRMA) + 2;

        return Carbon::createFromTimestamp($bloque * self::BLOQUE_FIRMA);
    }

    /** Relativa (sin dominio) para que funcione igual detrás del proxy de desarrollo. */
    private function url(FotoAuditoria $foto, string $tamano, Carbon $vence): string
    {
        return URL::temporarySignedRoute(
            'evidencias.imagen',
            $vence,
            ['foto' => $foto->id, 'tamano' => $tamano],
            absolute: false,
        );
    }
}
