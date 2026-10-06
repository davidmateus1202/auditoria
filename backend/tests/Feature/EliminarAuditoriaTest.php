<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\HallazgoAparicion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Una auditoría publicada con hallazgos que dependen de ella no se borra a
 * ciegas: el servidor pide confirmación y solo con `forzar` la elimina.
 */
class EliminarAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private Auditoria $auditoria;

    private Hallazgo $hallazgo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $sede = Sede::query()->firstOrFail();
        $this->auditoria = Auditoria::create([
            'sede_id' => $sede->id,
            'version' => 1,
            'periodo' => '2026-02',
            'estado' => 'publicada',
        ]);
        $this->hallazgo = Hallazgo::create([
            'sede_id' => $sede->id,
            'estandar_codigo' => CatalogoEstandares::E2_INFRAESTRUCTURA,
            'auditoria_origen_id' => $this->auditoria->id,
            'descripcion' => 'Rampa sin pasamanos',
            'huella' => sha1('rampa'),
            'estado' => EstadoHallazgo::Abierto,
        ]);
        HallazgoAparicion::create([
            'hallazgo_id' => $this->hallazgo->id,
            'auditoria_id' => $this->auditoria->id,
            'texto_reportado' => 'Rampa sin pasamanos',
        ]);
    }

    #[Test]
    public function con_hallazgos_dependientes_pide_confirmacion_en_vez_de_fallar(): void
    {
        $this->deleteJson("/api/auditorias/{$this->auditoria->id}")
            ->assertStatus(409)
            ->assertJsonPath('requiere_confirmacion', true)
            ->assertJsonPath('hallazgos_a_eliminar', 1)
            ->assertJsonPath('hallazgos_conservados', 0);

        $this->assertModelExists($this->auditoria);
        $this->assertModelExists($this->hallazgo);
    }

    #[Test]
    public function confirmada_elimina_sus_hallazgos_y_el_tablero_deja_de_contarlos(): void
    {
        (new ServicioCorte())->abrir('2026-02');
        $this->postJson('/api/consolidado')->assertJsonPath('generales.hallazgos', 1);

        $this->deleteJson("/api/auditorias/{$this->auditoria->id}?forzar=1")
            ->assertOk()
            ->assertJsonPath('hallazgos_eliminados', 1);

        $this->assertModelMissing($this->auditoria);
        $this->assertModelMissing($this->hallazgo);
        $this->postJson('/api/consolidado')->assertJsonPath('generales.hallazgos', 0);
    }

    #[Test]
    public function un_hallazgo_que_aparece_en_otra_auditoria_se_conserva(): void
    {
        $otra = Auditoria::create([
            'sede_id' => $this->auditoria->sede_id,
            'version' => 2,
            'periodo' => '2026-03',
            'estado' => 'publicada',
        ]);
        HallazgoAparicion::create([
            'hallazgo_id' => $this->hallazgo->id,
            'auditoria_id' => $otra->id,
            'texto_reportado' => 'Rampa sin pasamanos',
        ]);

        $this->deleteJson("/api/auditorias/{$this->auditoria->id}")
            ->assertStatus(409)
            ->assertJsonPath('hallazgos_a_eliminar', 0)
            ->assertJsonPath('hallazgos_conservados', 1);

        $this->deleteJson("/api/auditorias/{$this->auditoria->id}?forzar=1")->assertOk();

        $this->assertSame($otra->id, $this->hallazgo->fresh()->auditoria_origen_id);
    }

    #[Test]
    public function sin_hallazgos_ligados_se_elimina_sin_preguntar(): void
    {
        $vacia = Auditoria::create([
            'sede_id' => $this->auditoria->sede_id,
            'version' => 3,
            'estado' => 'por_confirmar',
        ]);

        $this->deleteJson("/api/auditorias/{$vacia->id}")->assertOk();
        $this->assertModelMissing($vacia);
    }
}
