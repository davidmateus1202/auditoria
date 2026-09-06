<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La API responde siempre en JSON, venga la petición de donde venga.
 *
 * Por omisión Laravel intenta redirigir al invitado a una ruta «login» que en
 * una aplicación solo-API no existe, y en vez del 401 devuelve un 500. Se vio
 * al abrir una URL de la API desde el navegador del teléfono, y habría roto el
 * manejo de sesión expirada de la app, que espera un 401 para pedir el ingreso.
 */
class RespuestasDeApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function una_peticion_sin_autenticar_recibe_401_en_json(): void
    {
        $this->getJson('/api/sedes')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function tambien_desde_un_navegador_que_pide_html(): void
    {
        // Este es el caso que fallaba: sin cabecera JSON, Laravel intentaba la
        // redirección y reventaba con RouteNotFoundException.
        $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get('/api/sedes')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function ninguna_ruta_de_la_api_devuelve_html(): void
    {
        foreach (['/api/hallazgos', '/api/cortes', '/api/catalogos/estandares'] as $ruta) {
            $respuesta = $this->withHeaders(['Accept' => 'text/html'])->get($ruta);

            $respuesta->assertUnauthorized();
            $this->assertStringContainsString(
                'application/json',
                (string) $respuesta->headers->get('Content-Type'),
                "La ruta {$ruta} debería responder en JSON"
            );
        }
    }

    #[Test]
    public function una_ruta_que_no_existe_tambien_responde_en_json(): void
    {
        $this->getJson('/api/esto-no-existe')->assertNotFound();
    }
}
