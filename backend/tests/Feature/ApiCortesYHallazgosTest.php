<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Hallazgo;
use App\Models\Sede;
use App\Models\User;
use App\Services\Cortes\ServicioCorte;
use App\Services\Reconciliacion\ImportadorLineaBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

class ApiCortesYHallazgosTest extends TestCase
{
    use RefreshDatabase;
    use UsaArchivosReales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        (new ImportadorLineaBase())->importar($this->archivoSeguimiento(), '2026-03');
        (new ServicioCorte())->cerrar('2026-03');
        Sanctum::actingAs(User::factory()->create());
    }

    #[Test]
    public function abre_un_mes_y_muestra_lo_que_falta_por_reportar(): void
    {
        $this->postJson('/api/cortes', ['periodo' => '2026-04'])->assertCreated();

        $this->getJson('/api/cortes/2026-04')
            ->assertOk()
            ->assertJsonPath('estado', 'abierto')
            ->assertJsonPath('hallazgos', 190)
            ->assertJsonPath('pendientes', 190)
            ->assertJsonPath('reportados', 0);
    }

    #[Test]
    public function rechaza_un_periodo_mal_escrito(): void
    {
        $this->postJson('/api/cortes', ['periodo' => '2026-13'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('periodo');
    }

    #[Test]
    public function descarga_la_matriz_del_mes(): void
    {
        $this->getJson('/api/cortes/2026-03/matriz')
            ->assertOk()
            ->assertDownload('seguimiento-hallazgos-2026-03.xlsx');
    }

    #[Test]
    public function editar_un_hallazgo_en_la_app_lo_marca_como_reportado(): void
    {
        $this->postJson('/api/cortes', ['periodo' => '2026-04'])->assertCreated();

        $hallazgo = Hallazgo::query()->vigentes()->firstOrFail();

        $this->putJson("/api/cortes/2026-04/hallazgos/{$hallazgo->id}", [
            'estado' => 'abierto_evidencia',
            'evidencia' => 'Contrato de mantenimiento en ejecución.',
        ])->assertOk();

        $this->getJson('/api/cortes/2026-04')
            ->assertOk()
            ->assertJsonPath('reportados', 1)
            ->assertJsonPath('pendientes', 189);
    }

    #[Test]
    public function un_corte_cerrado_no_admite_edicion(): void
    {
        $hallazgo = Hallazgo::query()->vigentes()->firstOrFail();

        $this->putJson("/api/cortes/2026-03/hallazgos/{$hallazgo->id}", ['estado' => 'cerrado'])
            ->assertStatus(422)
            ->assertJsonPath('mensaje', 'El corte 2026-03 está cerrado.');
    }

    #[Test]
    public function reabrir_exige_un_motivo_con_sustancia(): void
    {
        $this->postJson('/api/cortes/2026-03/reabrir', ['motivo' => 'error'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->postJson('/api/cortes/2026-03/reabrir', [
            'motivo' => 'La sede Kirpas reportó fuera de plazo y sus cierres no entraron.',
        ])->assertOk();
    }

    #[Test]
    public function los_hallazgos_se_filtran_por_sede_y_estandar(): void
    {
        $morichal = Sede::where('codigo', 'MORICHAL')->firstOrFail();

        $this->getJson("/api/hallazgos?sede_id={$morichal->id}")
            ->assertOk()
            ->assertJsonPath('total', 14);

        $this->getJson('/api/hallazgos?estandar='.CatalogoEstandares::E2_INFRAESTRUCTURA)
            ->assertOk()
            ->assertJsonPath('total', 120);

        // El cruce sede + estándar, que es el tercer corte que pidió el usuario.
        $this->getJson("/api/hallazgos?sede_id={$morichal->id}&estandar=".CatalogoEstandares::E2_INFRAESTRUCTURA)
            ->assertOk()
            ->assertJsonPath('total', 8);
    }

    #[Test]
    public function el_buscador_encuentra_por_texto_del_hallazgo(): void
    {
        $this->getJson('/api/hallazgos?buscar=martillos')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function cerrar_un_hallazgo_sin_evidencia_se_rechaza(): void
    {
        $hallazgo = Hallazgo::query()->vigentes()->firstOrFail();

        // Cerrar sin dejar constancia de con qué se cerró es lo que hace que un
        // consolidado no se pueda defender.
        $this->putJson("/api/hallazgos/{$hallazgo->id}/estado", ['estado' => 'cerrado'])
            ->assertStatus(422);

        $this->putJson("/api/hallazgos/{$hallazgo->id}/estado", [
            'estado' => 'cerrado',
            'evidencia' => 'Acta de entrega del mantenimiento, firmada el 12 de abril.',
        ])->assertOk();

        $this->assertSame(EstadoHallazgo::Cerrado, $hallazgo->fresh()->estado);
        $this->assertDatabaseHas('seguimientos', [
            'hallazgo_id' => $hallazgo->id,
            'estado_nuevo' => EstadoHallazgo::Cerrado->value,
        ]);
    }

    #[Test]
    public function la_linea_de_tiempo_muestra_el_paso_por_cada_corte(): void
    {
        $hallazgo = Hallazgo::query()->vigentes()->firstOrFail();

        $this->getJson("/api/hallazgos/{$hallazgo->id}/linea-tiempo")
            ->assertOk()
            ->assertJsonCount(1, 'por_corte')
            ->assertJsonPath('por_corte.0.corte.periodo', '2026-03');
    }

    #[Test]
    public function la_serie_mensual_esta_disponible_en_la_api(): void
    {
        $this->getJson('/api/consolidado/serie')
            ->assertOk()
            ->assertJsonPath('datos.0.periodo', '2026-03')
            ->assertJsonPath('datos.0.hallazgos', 223);
    }

    #[Test]
    public function el_consolidado_se_descarga_en_excel(): void
    {
        $this->postJson('/api/consolidado/exportar', ['periodo' => '2026-03'])
            ->assertOk()
            ->assertDownload('consolidado-suh-2026-03.xlsx');
    }
}
