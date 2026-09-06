<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ConsolidadoController;
use App\Http\Controllers\Api\CorteController;
use App\Http\Controllers\Api\HallazgoController;
use App\Http\Controllers\Api\SedeController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/yo', [AuthController::class, 'yo']);

    Route::get('catalogos/estandares', [CatalogoController::class, 'estandares']);
    Route::get('catalogos/servicios', [CatalogoController::class, 'servicios']);

    Route::apiResource('sedes', SedeController::class);

    // Autoevaluaciones: la entrada principal del sistema.
    Route::get('auditorias', [AuditoriaController::class, 'index']);
    Route::post('auditorias/cargar', [AuditoriaController::class, 'cargar']);
    Route::get('auditorias/{auditoria}/reconciliacion', [AuditoriaController::class, 'reconciliacion']);
    Route::post('auditorias/{auditoria}/confirmar', [AuditoriaController::class, 'confirmar']);
    Route::delete('auditorias/{auditoria}', [AuditoriaController::class, 'destroy']);

    // Hallazgos: los filtros de la pantalla de listado.
    Route::get('hallazgos', [HallazgoController::class, 'index']);
    Route::get('hallazgos/{hallazgo}', [HallazgoController::class, 'show']);
    Route::get('hallazgos/{hallazgo}/linea-tiempo', [HallazgoController::class, 'lineaTiempo']);
    Route::put('hallazgos/{hallazgo}/estado', [HallazgoController::class, 'cambiarEstado']);

    // Cortes mensuales y el ida y vuelta con Excel.
    Route::get('cortes', [CorteController::class, 'index']);
    Route::post('cortes', [CorteController::class, 'store']);
    Route::get('cortes/{periodo}', [CorteController::class, 'show']);
    Route::get('cortes/{periodo}/matriz', [CorteController::class, 'matriz']);
    Route::post('cortes/{periodo}/seguimiento', [CorteController::class, 'seguimiento']);
    Route::get('cortes/{periodo}/reconciliacion', [CorteController::class, 'reconciliacion']);
    Route::put('cortes/{periodo}/hallazgos/{hallazgo}', [CorteController::class, 'actualizarHallazgo']);
    Route::post('cortes/{periodo}/cerrar', [CorteController::class, 'cerrar']);
    Route::post('cortes/{periodo}/reabrir', [CorteController::class, 'reabrir']);

    // Consolidado: los tres cortes son el mismo endpoint con otra agrupación.
    Route::post('consolidado', [ConsolidadoController::class, 'generar']);
    Route::get('consolidado/serie', [ConsolidadoController::class, 'serie']);
    Route::post('consolidado/exportar', [ConsolidadoController::class, 'exportar']);
});
