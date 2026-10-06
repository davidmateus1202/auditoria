<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\Reconciliacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lo que se cambia desde la app tiene que verse en el tablero sin esperar a
 * cerrar el mes, y la lista de hallazgos y el tablero no pueden contradecirse.
 */
class CambiosReflejadosTest extends TestCase
{
    use RefreshDatabase;

    private Hallazgo $hallazgo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $sede = Sede::query()->firstOrFail();

        foreach (['uno', 'dos', 'tres', 'cuatro'] as $nombre) {
            $this->hallazgo = Hallazgo::create([
                'sede_id' => $sede->id,
                'estandar_codigo' => CatalogoEstandares::E2_INFRAESTRUCTURA,
                'descripcion' => $nombre,
                'huella' => sha1($nombre),
                'estado' => EstadoHallazgo::Abierto,
            ]);
        }

        // Un mes ya cerrado y el siguiente en curso, como en producción.
        $cortes = new ServicioCorte();
        $cortes->abrir('2026-01');
        $cortes->cerrar('2026-01');
        $cortes->abrir('2026-02');
    }

    #[Test]
    public function el_tablero_muestra_por_omision_el_mes_en_curso(): void
    {
        $this->postJson('/api/consolidado')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-02')
            ->assertJsonPath('corte_cerrado', false);
    }

    #[Test]
    public function cerrar_un_hallazgo_mueve_el_avance_del_tablero(): void
    {
        $this->putJson("/api/hallazgos/{$this->hallazgo->id}/estado", [
            'estado' => 'cerrado',
            'evidencia' => 'Acta de entrega',
        ])->assertOk();

        $this->postJson('/api/consolidado')
            ->assertOk()
            ->assertJsonPath('generales.hallazgos', 4)
            ->assertJsonPath('generales.cerrados', 1)
            ->assertJsonPath('generales.pct_avance', 0.25)
            // Quien lo cerró lo reportó en el mes: ya no está pendiente.
            ->assertJsonPath('generales.sin_reportar', 3);
    }

    #[Test]
    public function cerrar_desde_el_cruce_sin_evidencia_se_permite_y_llega_al_tablero(): void
    {
        $this->hallazgo->update(['evidencia' => 'Cotización previa']);

        $auditoria = Auditoria::create([
            'sede_id' => $this->hallazgo->sede_id,
            'version' => 1,
            'periodo' => '2026-02',
            'estado' => 'por_confirmar',
        ]);
        $cierre = Reconciliacion::create([
            'flujo' => 'vigencia',
            'auditoria_id' => $auditoria->id,
            'hallazgo_id' => $this->hallazgo->id,
            'destino' => DestinoReconciliacion::CandidatoCierre,
        ]);

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar", [
            'decisiones' => [$cierre->id => ['accion' => 'cerrar']],
        ])->assertOk()->assertJsonPath('aplicado.cerrados', 1);

        $this->hallazgo->refresh();
        $this->assertSame(EstadoHallazgo::Cerrado, $this->hallazgo->estado);
        // Sin evidencia nueva se conserva la que ya tenía.
        $this->assertSame('Cotización previa', $this->hallazgo->evidencia);

        $this->postJson('/api/consolidado')->assertJsonPath('generales.cerrados', 1);
    }

    #[Test]
    public function cerrar_o_crear_cerrado_sin_evidencia_se_permite(): void
    {
        $this->putJson("/api/hallazgos/{$this->hallazgo->id}/estado", ['estado' => 'cerrado'])
            ->assertOk();
        $this->assertSame(EstadoHallazgo::Cerrado, $this->hallazgo->fresh()->estado);

        $this->postJson('/api/hallazgos', [
            'sede_id' => $this->hallazgo->sede_id,
            'estandar_codigo' => CatalogoEstandares::E2_INFRAESTRUCTURA,
            'descripcion' => 'Extintor vencido en el pasillo de urgencias.',
            'estado' => 'cerrado',
        ])->assertCreated();

        $this->putJson("/api/cortes/2026-02/hallazgos/{$this->hallazgo->id}", ['estado' => 'cerrado'])
            ->assertOk();

        $this->postJson('/api/consolidado')->assertJsonPath('generales.cerrados', 2);
    }

    #[Test]
    public function editar_en_el_corte_abierto_actualiza_tambien_el_hallazgo(): void
    {
        $this->putJson("/api/cortes/2026-02/hallazgos/{$this->hallazgo->id}", [
            'estado' => 'abierto_evidencia',
            'evidencia' => 'Cotización aprobada',
        ])->assertOk();

        $this->hallazgo->refresh();
        $this->assertSame(EstadoHallazgo::AbiertoConEvidencia, $this->hallazgo->estado);
        $this->assertSame('Cotización aprobada', $this->hallazgo->evidencia);

        $this->postJson('/api/consolidado')
            ->assertJsonPath('generales.abiertos_con_evidencia', 1);
        $this->getJson('/api/hallazgos?estado=abierto_evidencia')
            ->assertJsonPath('total', 1);
    }
}
