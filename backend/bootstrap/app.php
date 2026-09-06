<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Una petición a la API sin autenticar debe recibir un 401 en JSON.
        //
        // Por omisión Laravel intenta redirigir al invitado a la ruta «login»,
        // que en una aplicación solo-API no existe: en vez del 401 sale un
        // RouteNotFoundException, que es un 500. Eso rompe el manejo de sesión
        // expirada de la app —esperaría un 401 para pedir el ingreso de nuevo—
        // y confunde a cualquiera que abra una URL de la API en el navegador.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') || $request->expectsJson()
                ? null
                : '/'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
