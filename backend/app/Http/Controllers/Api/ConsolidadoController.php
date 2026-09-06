<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Corte;
use App\Services\Consolidado\ExportadorConsolidado;
use App\Services\Consolidado\ServicioConsolidado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConsolidadoController extends Controller
{
    public function __construct(
        private readonly ServicioConsolidado $servicio,
        private readonly ExportadorConsolidado $exportador,
    ) {}

    /**
     * Los tres cortes que pidió el usuario son el mismo endpoint con distinta
     * agrupación: así el Excel exportado y lo que se ve en pantalla salen
     * siempre del mismo cálculo.
     */
    public function generar(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'periodo' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'sedes' => ['sometimes', 'array'],
            'sedes.*' => ['integer', 'exists:sedes,id'],
            'agrupacion' => ['sometimes', 'in:sede,estandar,sede_estandar'],
        ]);

        $periodo = $datos['periodo'] ?? $this->periodoPorOmision();

        if ($periodo === null) {
            return response()->json(['mensaje' => 'Todavía no hay ningún corte registrado.'], 404);
        }

        $consolidado = $this->servicio->generar($periodo, $datos['sedes'] ?? []);

        if (isset($datos['agrupacion'])) {
            return response()->json([
                'periodo' => $consolidado->periodo,
                'corte_cerrado' => $consolidado->corteCerrado,
                'agrupacion' => $datos['agrupacion'],
                'generales' => $consolidado->generales,
                'datos' => $consolidado->agrupadoPor($datos['agrupacion']),
            ]);
        }

        return response()->json($consolidado->aArray());
    }

    public function serie(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'sedes' => ['sometimes', 'array'],
            'sedes.*' => ['integer', 'exists:sedes,id'],
        ]);

        return response()->json(['datos' => $this->servicio->serie($datos['sedes'] ?? [])]);
    }

    public function exportar(Request $peticion): BinaryFileResponse
    {
        $datos = $peticion->validate([
            'periodo' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'sedes' => ['sometimes', 'array'],
            'sedes.*' => ['integer', 'exists:sedes,id'],
        ]);

        $periodo = $datos['periodo'] ?? $this->periodoPorOmision();
        $nombre = "consolidado-suh-{$periodo}.xlsx";
        $ruta = storage_path('app/exportaciones/'.$nombre);

        $this->exportador->exportar($periodo, $ruta, $datos['sedes'] ?? []);

        return response()->download($ruta, $nombre)->deleteFileAfterSend();
    }

    /** Por omisión, el último corte cerrado: las cifras firmes. */
    private function periodoPorOmision(): ?string
    {
        return Corte::ultimoCerrado()?->periodo
            ?? Corte::query()->orderByDesc('periodo')->value('periodo');
    }
}
