<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiSedesTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = \Database\Seeders\CatalogoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
    }

    #[Test]
    public function el_listado_exige_autenticacion(): void
    {
        $this->getJson('/api/sedes')->assertUnauthorized();
    }

    #[Test]
    public function el_login_devuelve_un_token_utilizable(): void
    {
        User::factory()->create(['email' => 'auditor@ese.gov.co', 'password' => bcrypt('clave-larga')]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'auditor@ese.gov.co',
            'password' => 'clave-larga',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/yo')
            ->assertOk()
            ->assertJsonPath('email', 'auditor@ese.gov.co');
    }

    #[Test]
    public function el_login_rechaza_credenciales_que_no_coinciden(): void
    {
        User::factory()->create(['email' => 'auditor@ese.gov.co', 'password' => bcrypt('clave-larga')]);

        $this->postJson('/api/auth/login', [
            'email' => 'auditor@ese.gov.co',
            'password' => 'otra-cosa',
        ])->assertStatus(422);
    }

    #[Test]
    public function lista_las_diez_sedes_sembradas_con_su_conteo_de_vigentes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();

        Hallazgo::create([
            'sede_id' => $sede->id,
            'estandar_codigo' => CatalogoEstandares::E2_INFRAESTRUCTURA,
            'descripcion' => 'Las puertas de los consultorios están deterioradas.',
            'huella' => sha1('uno'),
            'estado' => EstadoHallazgo::Abierto,
        ]);

        Hallazgo::create([
            'sede_id' => $sede->id,
            'estandar_codigo' => CatalogoEstandares::E3_DOTACION,
            'descripcion' => 'Las escalerillas están en regular estado.',
            'huella' => sha1('dos'),
            'estado' => EstadoHallazgo::Cerrado,
        ]);

        $respuesta = $this->getJson('/api/sedes')->assertOk();

        $this->assertCount(10, $respuesta->json('datos'));

        $morichal = collect($respuesta->json('datos'))->firstWhere('codigo', 'MORICHAL');
        $this->assertSame(1, $morichal['hallazgos_vigentes']);
        $this->assertSame(1, $morichal['hallazgos_cerrados']);
    }

    #[Test]
    public function crea_una_sede_y_rechaza_el_codigo_repetido(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/sedes', ['codigo' => 'NUEVA', 'nombre' => 'Centro de Salud Nueva'])
            ->assertCreated()
            ->assertJsonPath('datos.codigo', 'NUEVA');

        $this->postJson('/api/sedes', ['codigo' => 'NUEVA', 'nombre' => 'Otra'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    #[Test]
    public function una_sede_con_auditorias_se_desactiva_en_vez_de_borrarse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $sede = Sede::where('codigo', 'KIRPAS')->firstOrFail();
        Auditoria::create(['sede_id' => $sede->id, 'version' => 1]);

        // Borrarla arrastraría hallazgos y cierres, y con ellos la trazabilidad
        // de por qué se cerró algo.
        $this->deleteJson("/api/sedes/{$sede->id}")->assertOk();

        $this->assertDatabaseHas('sedes', ['id' => $sede->id, 'activa' => false]);
    }

    #[Test]
    public function una_sede_sin_auditorias_si_se_borra(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $sede = Sede::create(['codigo' => 'TEMPORAL', 'nombre' => 'Provisional']);

        $this->deleteJson("/api/sedes/{$sede->id}")->assertOk();
        $this->assertDatabaseMissing('sedes', ['id' => $sede->id]);
    }

    #[Test]
    public function el_catalogo_expone_los_ocho_estandares_y_la_normativa_vigente(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $respuesta = $this->getJson('/api/catalogos/estandares')->assertOk();

        $this->assertSame(CatalogoEstandares::NORMATIVA, $respuesta->json('normativa'));
        $this->assertCount(8, $respuesta->json('datos'));

        $e0 = collect($respuesta->json('datos'))->firstWhere('codigo', 'E0');
        $this->assertFalse((bool) $e0['es_normativo'], 'E0 no es un estándar de la resolución');
    }

    #[Test]
    public function la_sede_se_resuelve_desde_el_texto_del_archivo(): void
    {
        // La autoevaluación dice «Centro de Salud Morichal» y el consolidado
        // dice «MORICHAL»: las dos deben llegar a la misma sede.
        $this->assertSame('MORICHAL', Sede::resolverPorTexto('Centro de Salud Morichal')?->codigo);
        $this->assertSame('MORICHAL', Sede::resolverPorTexto('MORICHAL')?->codigo);
        $this->assertSame('BARZAL', Sede::resolverPorTexto('  barzal ')?->codigo);
        $this->assertNull(Sede::resolverPorTexto('Hospital Departamental'));
    }
}
