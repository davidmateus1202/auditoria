<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\SedeController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/yo', [AuthController::class, 'yo']);

    Route::get('catalogos/estandares', [CatalogoController::class, 'estandares']);
    Route::get('catalogos/servicios', [CatalogoController::class, 'servicios']);

    Route::apiResource('sedes', SedeController::class);
});
