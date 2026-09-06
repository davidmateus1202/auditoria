<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Extraccion\CatalogoEstandares;
use App\Http\Controllers\Controller;
use App\Models\Estandar;
use App\Models\Servicio;
use Illuminate\Http\JsonResponse;

class CatalogoController extends Controller
{
    public function estandares(): JsonResponse
    {
        return response()->json([
            'normativa' => CatalogoEstandares::NORMATIVA,
            'datos' => Estandar::query()->withCount('alias')->orderBy('orden')->get(),
        ]);
    }

    public function servicios(): JsonResponse
    {
        return response()->json(['datos' => Servicio::query()->orderBy('nombre')->get()]);
    }
}
