<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Hallazgo;
use App\Models\Sede;
use App\Services\Consolidado\ExportadorConsolidado;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El Excel con datos sintéticos: no depende de los archivos de referencia,
 * así que corre siempre.
 */
class ExportadorConsolidadoTest extends TestCase
{
    use RefreshDatabase;

    private string $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        $this->ruta = storage_path('framework/testing/consolidado-sintetico.xlsx');

        $sedes = Sede::query()->take(2)->get();
        $estandares = [CatalogoEstandares::E2_INFRAESTRUCTURA, CatalogoEstandares::E3_DOTACION];

        foreach ($sedes as $s => $sede) {
            foreach (range(1, 4) as $n) {
                Hallazgo::create([
                    'sede_id' => $sede->id,
                    'estandar_codigo' => $estandares[$n % 2],
                    'descripcion' => "Hallazgo {$n} de la sede {$s}",
                    'huella' => sha1("{$s}-{$n}"),
                    'estado' => EstadoHallazgo::Abierto,
                ]);
            }
        }

        // Enero cierra dos hallazgos; febrero arranca sin ellos en su foto.
        $cortes = new ServicioCorte();
        $enero = $cortes->abrir('2026-01');
        $enero->estados()->limit(2)->update(['estado' => EstadoHallazgo::Cerrado->value]);
        $cortes->cerrar('2026-01');
        $cortes->abrir('2026-02');
    }

    protected function tearDown(): void
    {
        @unlink($this->ruta);
        parent::tearDown();
    }

    #[Test]
    public function cada_hoja_de_resumen_trae_sus_graficas(): void
    {
        (new ExportadorConsolidado())->exportar('2026-02', $this->ruta);

        $lector = new Xlsx();
        $lector->setIncludeCharts(true);
        $libro = $lector->load($this->ruta);

        $graficas = [];
        foreach ($libro->getAllSheets() as $hoja) {
            $graficas[$hoja->getTitle()] = $hoja->getChartCount();
        }

        $this->assertSame([
            'Hallazgos generales' => 1,
            'resumen por sede' => 2,
            'resumen por estandar' => 2,
            'resumen por sede y estandar' => 1,
            'detalle de hallazgos' => 0,
            'serie mensual' => 2,
        ], $graficas);

        $libro->disconnectWorksheets();
    }

    #[Test]
    public function el_detalle_cuadra_con_los_totales_incluidos_los_cerrados_antes(): void
    {
        (new ExportadorConsolidado())->exportar('2026-02', $this->ruta);
        $libro = (new Xlsx())->load($this->ruta);

        $total = $libro->getSheetByName('Hallazgos generales')->getCell('B5')->getValue();
        $detalle = $libro->getSheetByName('detalle de hallazgos');

        $this->assertSame(8, $total);
        $this->assertSame($total, $detalle->getHighestDataRow() - 1);

        $marcas = array_column($detalle->rangeToArray('I2:I9'), 0);
        $this->assertSame(2, count(array_keys($marcas, 'Cerrado en 2026-01', true)));

        $libro->disconnectWorksheets();
    }
}
