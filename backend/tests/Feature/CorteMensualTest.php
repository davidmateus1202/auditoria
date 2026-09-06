<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Reconciliacion;
use App\Models\Sede;
use App\Services\Cortes\ExportadorMatriz;
use App\Services\Cortes\ImportadorMatriz;
use App\Services\Cortes\ServicioCorte;
use App\Services\Reconciliacion\ImportadorLineaBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * El ciclo mensual completo, incluido el ida y vuelta con Excel.
 *
 * El caso de conflicto solo aparece cuando dos personas trabajan en paralelo,
 * que es justo cuando nadie está mirando: por eso se prueba aquí.
 */
class CorteMensualTest extends TestCase
{
    use RefreshDatabase;
    use UsaArchivosReales;

    private ServicioCorte $cortes;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        $this->cortes = new ServicioCorte();
        $this->carpeta = storage_path('framework/testing/matrices');

        (new ImportadorLineaBase())->importar($this->archivoSeguimiento(), '2026-03');
        $this->cortes->cerrar('2026-03');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->carpeta)) {
            array_map('unlink', glob($this->carpeta.'/*.xlsx') ?: []);
        }

        parent::tearDown();
    }

    private function exportar(string $periodo): string
    {
        $corte = Corte::where('periodo', $periodo)->firstOrFail();

        return (new ExportadorMatriz())->exportar($corte, $this->carpeta."/matriz-{$periodo}.xlsx");
    }

    #[Test]
    public function abrir_un_mes_arrastra_los_vigentes_sin_marcarlos(): void
    {
        $corte = $this->cortes->abrir('2026-04');

        // 223 hallazgos menos los 33 cerrados: solo viajan los que exigen gestión.
        $this->assertSame(190, $corte->estados()->count());
        $this->assertSame(0, $corte->estados()->where('presente_en_corte', true)->count());

        $estado = $this->cortes->estado('2026-04');
        $this->assertSame(190, $estado['pendientes']);
        $this->assertCount(10, $estado['por_sede']);
    }

    #[Test]
    public function los_meses_abiertos_se_acumulan_corte_a_corte(): void
    {
        $this->cortes->abrir('2026-04');
        $this->cortes->cerrar('2026-04');
        $this->cortes->abrir('2026-05');

        $foto = HallazgoEstadoCorte::query()
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-05'))
            ->first();

        // Marzo lo trajo con 1; abril suma 2 y mayo 3. Un hallazgo abierto hace
        // catorce meses no es lo mismo que uno del mes pasado, y hoy en el
        // consolidado se ven idénticos.
        $this->assertSame(3, $foto->meses_abierto);
    }

    #[Test]
    public function cerrar_un_mes_congela_la_foto_y_actualiza_la_cache(): void
    {
        $corte = $this->cortes->abrir('2026-04');
        $foto = $corte->estados()->first();

        $foto->update(['estado' => EstadoHallazgo::Cerrado, 'presente_en_corte' => true]);
        $this->cortes->cerrar('2026-04');

        $this->assertSame(EstadoHallazgo::Cerrado, Hallazgo::find($foto->hallazgo_id)->estado);
        $this->assertFalse(Corte::where('periodo', '2026-04')->firstOrFail()->admiteEscritura());
    }

    #[Test]
    public function un_corte_cerrado_conserva_su_foto_aunque_todo_cambie_despues(): void
    {
        $abiertosEnMarzo = HallazgoEstadoCorte::query()
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-03'))
            ->where('estado', EstadoHallazgo::Abierto->value)
            ->count();

        $this->cortes->abrir('2026-04');
        HallazgoEstadoCorte::query()
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->update(['estado' => EstadoHallazgo::Cerrado->value]);
        $this->cortes->cerrar('2026-04');

        // El consolidado de marzo tiene que seguir dando lo mismo en diciembre.
        $this->assertSame(116, $abiertosEnMarzo);
        $this->assertSame(
            $abiertosEnMarzo,
            HallazgoEstadoCorte::query()
                ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-03'))
                ->where('estado', EstadoHallazgo::Abierto->value)
                ->count()
        );
    }

    #[Test]
    public function reabrir_un_mes_exige_motivo(): void
    {
        $this->expectExceptionMessageMatches('/exige un motivo/');
        $this->cortes->reabrir('2026-03', '   ');
    }

    #[Test]
    public function la_matriz_exportada_conserva_el_formato_de_siempre(): void
    {
        $ruta = $this->exportar('2026-03');
        $libro = IOFactory::createReaderForFile($ruta);
        $libro->setReadDataOnly(true);
        $hojas = $libro->listWorksheetNames($ruta);

        $this->assertContains('MORICHAL', $hojas);
        $this->assertContains(ExportadorMatriz::HOJA_CONTROL, $hojas);

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName('MORICHAL');

        // Títulos en la fila 8 y sección I en la 9, como el original.
        $this->assertSame('HALLAZGOS', $hoja->getCell('A8')->getValue());
        $this->assertSame('ESTÁNDAR', $hoja->getCell('C8')->getValue());
        $this->assertSame('SEGUIMIENTO A CUMPLIMIENTO', $hoja->getCell('F8')->getValue());
        $this->assertStringContainsString('CONDICIONES', (string) $hoja->getCell('A9')->getValue());
        $this->assertSame('CENTRO DE SALUD', $hoja->getCell('A7')->getValue());

        // Y cada fila viaja con su token.
        $this->assertMatchesRegularExpression('/^H-\d+-[0-9a-f]{4}$/', (string) $hoja->getCell('H10')->getValue());
    }

    #[Test]
    public function el_estado_sale_con_lista_desplegable(): void
    {
        $libro = IOFactory::load($this->exportar('2026-03'));
        $validacion = $libro->getSheetByName('MORICHAL')->getCell('F10')->getDataValidation();

        // Hoy el estado se escribe libre y de ahí salen las variantes que el
        // importador tiene que interpretar. Con la lista deja de pasar.
        $this->assertTrue($validacion->getShowDropDown());
        $this->assertStringContainsString('Hallazgos Cerrados', $validacion->getFormula1());
    }

    #[Test]
    public function un_cambio_hecho_solo_en_excel_se_aplica(): void
    {
        $this->cortes->abrir('2026-04');
        $ruta = $this->exportar('2026-04');

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName('MORICHAL');
        $ref = (string) $hoja->getCell('H10')->getValue();
        $hoja->setCellValue('F10', EstadoHallazgo::Cerrado->etiquetaExcel());
        $hoja->setCellValue('G10', 'Se ejecutó el mantenimiento en abril.');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

        $resumen = (new ImportadorMatriz())->importar($ruta, '2026-04');

        $this->assertSame(1, $resumen['aplicados']);
        $this->assertSame(0, $resumen['conflictos']);
        $this->assertSame(0, $resumen['ausentes'], 'todas las filas volvieron');

        $foto = HallazgoEstadoCorte::query()
            ->where('hallazgo_id', Hallazgo::interpretarReferencia($ref))
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->firstOrFail();

        $this->assertSame(EstadoHallazgo::Cerrado, $foto->estado);
        $this->assertStringContainsString('mantenimiento', $foto->evidencia);
    }

    #[Test]
    public function tocar_el_mismo_hallazgo_por_los_dos_lados_es_conflicto(): void
    {
        $this->cortes->abrir('2026-04');
        $ruta = $this->exportar('2026-04');

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName('MORICHAL');
        $ref = (string) $hoja->getCell('H10')->getValue();
        $hoja->setCellValue('F10', EstadoHallazgo::Cerrado->etiquetaExcel());
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

        // Mientras el archivo estaba afuera, alguien lo cambió en la app.
        $id = Hallazgo::interpretarReferencia($ref);
        HallazgoEstadoCorte::query()
            ->where('hallazgo_id', $id)
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->update([
                'estado' => EstadoHallazgo::AbiertoConEvidencia->value,
                'evidencia' => 'Contrato de mantenimiento en ejecución.',
            ]);

        $resumen = (new ImportadorMatriz())->importar($ruta, '2026-04');

        $this->assertSame(1, $resumen['conflictos']);

        // Nadie gana en silencio: el estado de la app se conserva hasta que
        // una persona decida.
        $foto = HallazgoEstadoCorte::query()
            ->where('hallazgo_id', $id)
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->firstOrFail();

        $this->assertSame(EstadoHallazgo::AbiertoConEvidencia, $foto->estado);
        $this->assertDatabaseHas('reconciliaciones', [
            'hallazgo_id' => $id,
            'destino' => DestinoReconciliacion::Conflicto->value,
            'resuelto' => false,
        ]);
    }

    #[Test]
    public function si_borran_la_columna_ref_el_texto_sigue_emparejando(): void
    {
        $this->cortes->abrir('2026-04');
        $ruta = $this->exportar('2026-04');

        $libro = IOFactory::load($ruta);

        // Alguien borra la columna del token y quita la hoja de control.
        foreach ($libro->getAllSheets() as $hoja) {
            if ($hoja->getTitle() !== ExportadorMatriz::HOJA_CONTROL) {
                $hoja->getStyle('H1:H500');
                $hoja->removeColumn('H');
            }
        }
        $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName(ExportadorMatriz::HOJA_CONTROL)));
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

        $resumen = (new ImportadorMatriz())->importar($ruta, '2026-04');

        // El REF es un atajo de precisión, no una dependencia.
        $this->assertSame(0, $resumen['nuevos'], 'ninguna fila debería quedar huérfana');
        $this->assertSame(0, $resumen['ausentes']);
        $this->assertSame(190, $resumen['sin_cambio'] + $resumen['aplicados']);
    }

    #[Test]
    public function una_fila_que_no_vuelve_no_se_cierra_sino_que_queda_senalada(): void
    {
        $this->cortes->abrir('2026-04');
        $ruta = $this->exportar('2026-04');

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName('MORICHAL');
        $ref = (string) $hoja->getCell('H10')->getValue();
        $id = Hallazgo::interpretarReferencia($ref);
        $estadoPrevio = HallazgoEstadoCorte::query()
            ->where('hallazgo_id', $id)
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->firstOrFail()
            ->estado
            ->value;

        // Se le olvidó digitar esa fila.
        $hoja->removeRow(10);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);

        $resumen = (new ImportadorMatriz())->importar($ruta, '2026-04');

        $this->assertSame(1, $resumen['ausentes']);

        $foto = HallazgoEstadoCorte::query()
            ->where('hallazgo_id', $id)
            ->whereHas('corte', fn ($q) => $q->where('periodo', '2026-04'))
            ->firstOrFail();

        // Conserva su estado: lo más probable es que la fila se quedara sin
        // digitar, no que el problema se haya resuelto.
        $this->assertSame($estadoPrevio, $foto->estado->value);
        $this->assertFalse($foto->presente_en_corte);
        $this->assertDatabaseHas('reconciliaciones', [
            'hallazgo_id' => $id,
            'destino' => DestinoReconciliacion::AusenteDelCorte->value,
        ]);
    }

    #[Test]
    public function no_se_puede_importar_sobre_un_corte_cerrado(): void
    {
        $ruta = $this->exportar('2026-03');

        $this->expectExceptionMessageMatches('/está cerrado/');
        (new ImportadorMatriz())->importar($ruta, '2026-03');
    }
}
