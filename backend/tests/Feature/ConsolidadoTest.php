<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use App\Models\User;
use App\Services\Consolidado\ExportadorConsolidado;
use App\Services\Consolidado\ServicioConsolidado;
use App\Services\Cortes\ServicioCorte;
use App\Services\Reconciliacion\ImportadorLineaBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * El consolidado contra las cifras que la E.S.E. ya conoce.
 */
class ConsolidadoTest extends TestCase
{
    use RefreshDatabase;
    use UsaArchivosReales;

    private ServicioConsolidado $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        $this->servicio = new ServicioConsolidado();

        (new ImportadorLineaBase())->importar($this->archivoSeguimiento(), '2026-03');
        (new ServicioCorte())->cerrar('2026-03');
    }

    #[Test]
    public function los_generales_reproducen_el_corte_cargado(): void
    {
        $generales = $this->servicio->generar('2026-03')->generales;

        $this->assertSame(223, $generales['hallazgos']);
        $this->assertSame(33, $generales['cerrados']);
        $this->assertSame(69, $generales['abiertos_con_evidencia']);
        $this->assertSame(116, $generales['abiertos']);
        $this->assertSame(5, $generales['sin_dato']);

        // 14,8 % real, contra el 19,7 % que reporta el consolidado histórico.
        $this->assertEqualsWithDelta(0.148, $generales['pct_avance'], 0.001);
        $this->assertEqualsWithDelta(0.457, $generales['pct_con_gestion'], 0.001);
    }

    #[Test]
    public function el_resumen_por_sede_coincide_con_el_conteo_manual(): void
    {
        $porSede = collect($this->servicio->generar('2026-03')->porSede)->keyBy('sede');

        $this->assertSame(34, $porSede['BARZAL']['hallazgos']);
        $this->assertSame(32, $porSede['RECREO']['hallazgos']);
        $this->assertSame(14, $porSede['MORICHAL']['hallazgos']);
        $this->assertSame(13, $porSede['CEMI']['hallazgos']);
        $this->assertCount(10, $porSede);

        // Ordenado de mayor a menor: la sede que más pide atención va primero.
        $this->assertSame('BARZAL', $this->servicio->generar('2026-03')->porSede[0]['sede']);
    }

    #[Test]
    public function el_resumen_por_estandar_mantiene_el_reparto(): void
    {
        $porEstandar = collect($this->servicio->generar('2026-03')->porEstandar)->keyBy('estandar');

        $this->assertSame(120, $porEstandar[CatalogoEstandares::E2_INFRAESTRUCTURA]['hallazgos']);
        $this->assertSame(60, $porEstandar[CatalogoEstandares::E3_DOTACION]['hallazgos']);
        $this->assertSame(26, $porEstandar[CatalogoEstandares::E4_MEDICAMENTOS]['hallazgos']);

        // Ocho de cada diez hallazgos son infraestructura o dotación.
        $this->assertEqualsWithDelta(
            0.807,
            $porEstandar[CatalogoEstandares::E2_INFRAESTRUCTURA]['pct_del_total']
                + $porEstandar[CatalogoEstandares::E3_DOTACION]['pct_del_total'],
            0.001
        );
    }

    #[Test]
    public function la_matriz_sede_por_estandar_cuadra_en_los_dos_sentidos(): void
    {
        $matriz = $this->servicio->generar('2026-03')->sedePorEstandar;

        $totalFilas = 0;
        $totalColumnas = array_fill_keys(CatalogoEstandares::codigos(), 0);

        foreach ($matriz as $sede) {
            $this->assertSame(
                array_sum($sede['estandares']),
                $sede['total'],
                "La fila de {$sede['sede']} no cuadra con su total"
            );
            $totalFilas += $sede['total'];

            foreach ($sede['estandares'] as $codigo => $cantidad) {
                $totalColumnas[$codigo] += $cantidad;
            }
        }

        $this->assertSame(223, $totalFilas);
        $this->assertSame(223, array_sum($totalColumnas));
        $this->assertSame(120, $totalColumnas[CatalogoEstandares::E2_INFRAESTRUCTURA]);
    }

    #[Test]
    public function morichal_tiene_infraestructura_como_estandar_dominante(): void
    {
        $morichal = collect($this->servicio->generar('2026-03')->sedePorEstandar)->firstWhere('sede', 'MORICHAL');

        $this->assertSame(CatalogoEstandares::E2_INFRAESTRUCTURA, $morichal['estandar_dominante']);
        $this->assertEqualsWithDelta(8 / 14, $morichal['pct_dominante'], 0.001);
    }

    #[Test]
    public function filtrar_por_sedes_recorta_todas_las_vistas(): void
    {
        $ids = Sede::whereIn('codigo', ['MORICHAL', 'CEMI'])->pluck('id')->all();
        $consolidado = $this->servicio->generar('2026-03', $ids);

        $this->assertSame(27, $consolidado->generales['hallazgos']);
        $this->assertCount(2, $consolidado->porSede);
        $this->assertCount(2, $consolidado->sedePorEstandar);
    }

    #[Test]
    public function el_consolidado_de_un_mes_cerrado_no_cambia_despues(): void
    {
        $marzo = $this->servicio->generar('2026-03')->generales;

        // Pasa abril y se cierra todo.
        $cortes = new ServicioCorte();
        $cortes->abrir('2026-04');
        HallazgoEstadoCorte::query()
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->update(['estado' => EstadoHallazgo::Cerrado->value]);
        $cortes->cerrar('2026-04');

        // El de marzo sigue dando lo mismo: por eso se puede auditar hacia atrás.
        $this->assertSame($marzo, $this->servicio->generar('2026-03')->generales);
        $this->assertSame(190, $this->servicio->generar('2026-04')->generales['cerrados']);
    }

    #[Test]
    public function la_serie_mensual_muestra_la_evolucion(): void
    {
        $cortes = new ServicioCorte();
        $cortes->abrir('2026-04');
        $cortes->cerrar('2026-04');

        $serie = $this->servicio->serie();

        $this->assertCount(2, $serie);
        $this->assertSame('2026-03', $serie[0]['periodo']);
        $this->assertSame(223, $serie[0]['hallazgos']);
        // En abril solo viajan los vigentes: los 33 cerrados ya no cuentan.
        $this->assertSame(190, $serie[1]['hallazgos']);
    }

    #[Test]
    public function el_excel_trae_las_cuatro_hojas_de_siempre_y_las_dos_nuevas(): void
    {
        $ruta = storage_path('framework/testing/consolidado.xlsx');
        (new ExportadorConsolidado())->exportar('2026-03', $ruta);

        $hojas = IOFactory::createReaderForFile($ruta)->listWorksheetNames($ruta);

        $this->assertSame([
            'Hallazgos generales',
            'resumen por sede',
            'resumen por estandar',
            'resumen por sede y estandar',
            'detalle de hallazgos',
            'serie mensual',
        ], $hojas);

        $libro = IOFactory::load($ruta);

        $this->assertSame(223, $libro->getSheetByName('Hallazgos generales')->getCell('B5')->getValue());
        // El detalle tiene una fila por hallazgo más el encabezado.
        $this->assertSame(224, $libro->getSheetByName('detalle de hallazgos')->getHighestDataRow());

        $libro->disconnectWorksheets();
        @unlink($ruta);
    }

    #[Test]
    public function la_api_entrega_los_tres_cortes_desde_el_mismo_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['sede' => 10, 'estandar' => 6, 'sede_estandar' => 10] as $agrupacion => $filas) {
            $this->postJson('/api/consolidado', ['periodo' => '2026-03', 'agrupacion' => $agrupacion])
                ->assertOk()
                ->assertJsonPath('agrupacion', $agrupacion)
                ->assertJsonPath('generales.hallazgos', 223)
                ->assertJsonCount($filas, 'datos');
        }
    }

    #[Test]
    public function la_api_avisa_cuando_el_corte_todavia_esta_abierto(): void
    {
        Sanctum::actingAs(User::factory()->create());
        (new ServicioCorte())->abrir('2026-04');

        $this->postJson('/api/consolidado', ['periodo' => '2026-04'])
            ->assertOk()
            ->assertJsonPath('corte_cerrado', false);

        // Por omisión entrega el último corte cerrado: las cifras firmes.
        $this->postJson('/api/consolidado')
            ->assertOk()
            ->assertJsonPath('periodo', '2026-03')
            ->assertJsonPath('corte_cerrado', true);
    }
}
