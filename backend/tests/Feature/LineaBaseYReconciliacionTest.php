<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Models\Auditoria;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use App\Services\Extraccion\ExtractorExcel;
use App\Services\Reconciliacion\ImportadorLineaBase;
use App\Services\Reconciliacion\ServicioReconciliacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * El ciclo completo contra la base de datos: cargar la línea base histórica y
 * después reconciliar una autoevaluación contra ella.
 */
class LineaBaseYReconciliacionTest extends TestCase
{
    use RefreshDatabase;
    use UsaArchivosReales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
    }

    private function importar(string $periodo = '2026-03'): array
    {
        return (new ImportadorLineaBase())->importar($this->archivoSeguimiento(), $periodo);
    }

    #[Test]
    public function la_linea_base_carga_los_223_hallazgos_en_las_diez_sedes(): void
    {
        $resumen = $this->importar();

        $this->assertSame(223, $resumen['hallazgos']);
        $this->assertSame(10, $resumen['sedes']);
        $this->assertSame([], $resumen['sin_sede'], 'las diez hojas deben resolver a una sede registrada');

        $this->assertSame(223, Hallazgo::count());
        $this->assertSame(223, HallazgoEstadoCorte::count());
    }

    #[Test]
    public function los_estados_llegan_como_estan_en_la_matriz(): void
    {
        $this->importar();

        $this->assertSame(116, Hallazgo::where('estado', EstadoHallazgo::Abierto->value)->count());
        $this->assertSame(69, Hallazgo::where('estado', EstadoHallazgo::AbiertoConEvidencia->value)->count());
        $this->assertSame(33, Hallazgo::where('estado', EstadoHallazgo::Cerrado->value)->count());
        $this->assertSame(5, Hallazgo::where('estado', EstadoHallazgo::SinDato->value)->count());
    }

    #[Test]
    public function morichal_queda_con_sus_catorce_hallazgos(): void
    {
        $this->importar();

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();

        $this->assertSame(14, Hallazgo::deSede($sede->id)->count());
        $this->assertSame(8, Hallazgo::deSede($sede->id)->deEstandar('E2')->count());
        $this->assertSame(3, Hallazgo::deSede($sede->id)->deEstandar('E3')->count());
    }

    #[Test]
    public function el_corte_queda_abierto_y_la_importacion_es_repetible(): void
    {
        $this->importar();
        $corte = Corte::where('periodo', '2026-03')->firstOrFail();

        $this->assertTrue($corte->admiteEscritura());

        // Si la matriz entra con errores hay que poder rehacerla: repetir la
        // importación no debe duplicar nada mientras el corte siga abierto.
        $this->importar();

        $this->assertSame(223, Hallazgo::count());
        $this->assertSame(223, HallazgoEstadoCorte::count());
    }

    #[Test]
    public function un_corte_cerrado_no_admite_reimportacion(): void
    {
        $this->importar();

        Corte::where('periodo', '2026-03')->update(['estado' => 'cerrado']);

        $this->expectExceptionMessageMatches('/está cerrado/');
        $this->importar();
    }

    #[Test]
    public function la_autoevaluacion_de_morichal_reconcilia_contra_su_linea_base(): void
    {
        $this->importar();

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();
        $auditoria = Auditoria::create([
            'sede_id' => $sede->id,
            'version' => Auditoria::siguienteVersion($sede->id),
            'periodo' => '2026-04',
            'estado' => 'por_confirmar',
        ]);

        $resultado = (new ExtractorExcel())->extraer($this->archivoAutoevaluacion());
        $propuesta = (new ServicioReconciliacion())->reconciliarVigencia($auditoria, $resultado);

        // Es la misma auditoría vista desde el otro archivo, pero dos de los
        // catorce hallazgos ya figuraban cerrados en la línea base. Vuelven a
        // reportarse, así que no son nuevos: son reincidencias, y reabrirlos
        // exige que alguien lo confirme.
        $this->assertCount(12, $propuesta->de(DestinoReconciliacion::Persiste));
        $this->assertCount(2, $propuesta->de(DestinoReconciliacion::Reincidencia));
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::Nuevo));
        $this->assertSame(2, $propuesta->pendientes());

        $this->assertDatabaseCount('reconciliaciones', 14);
        $this->assertDatabaseHas('reconciliaciones', [
            'auditoria_id' => $auditoria->id,
            'destino' => DestinoReconciliacion::Reincidencia->value,
            'resuelto' => false,
        ]);
    }

    #[Test]
    public function un_hallazgo_cerrado_que_reaparece_no_se_registra_como_nuevo(): void
    {
        $this->importar();

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();
        $cerrado = Hallazgo::deSede($sede->id)
            ->where('estado', EstadoHallazgo::Cerrado->value)
            ->firstOrFail();

        $auditoria = Auditoria::create([
            'sede_id' => $sede->id,
            'version' => Auditoria::siguienteVersion($sede->id),
            'periodo' => '2026-05',
            'estado' => 'por_confirmar',
        ]);

        $resultado = new \App\Domain\Extraccion\Dto\ResultadoExtraccion(
            \App\Domain\Enums\TipoFormato::Autoevaluacion
        );
        $resultado->agregarHallazgo(new \App\Domain\Extraccion\Dto\HallazgoExtraido(
            codigoEstandar: $cerrado->estandar_codigo,
            descripcion: $cerrado->descripcion,
            clasificacion: \App\Domain\Enums\ClasificacionHallazgo::Hallazgo,
            hoja: 'INFORME',
            fila: 10,
        ));

        $propuesta = (new ServicioReconciliacion())->reconciliarVigencia($auditoria, $resultado);
        $reincidencias = $propuesta->de(DestinoReconciliacion::Reincidencia);

        $this->assertCount(1, $reincidencias);
        $this->assertSame($cerrado->id, $reincidencias[0]->hallazgoId);
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::Nuevo));
    }

    #[Test]
    public function el_bloqueo_por_sede_impide_emparejar_con_otro_centro(): void
    {
        $this->importar();

        // Barzal y Porvenir comparten textos idénticos. Si la reconciliación de
        // Barzal pudiera alcanzar los hallazgos de Porvenir, cerraría problemas
        // de un centro con evidencia de otro.
        $barzal = Sede::where('codigo', 'BARZAL')->firstOrFail();
        $servicio = new ServicioReconciliacion();

        $vigentes = $servicio->vigentesDe($barzal);
        $idsDeBarzal = Hallazgo::deSede($barzal->id)->pluck('id')->all();

        $this->assertNotEmpty($vigentes);

        foreach ($vigentes as $vigente) {
            $this->assertContains(
                $vigente->id,
                $idsDeBarzal,
                'la lista de candidatos no puede salirse de la sede'
            );
        }
    }

    #[Test]
    public function el_comando_de_linea_base_reporta_lo_cargado(): void
    {
        $this->artisan('suh:importar-linea-base', [
            'archivo' => $this->archivoSeguimiento(),
            '--periodo' => '2026-03',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('223');
    }

    #[Test]
    public function el_comando_rechaza_un_periodo_mal_escrito(): void
    {
        $this->artisan('suh:importar-linea-base', [
            'archivo' => $this->archivoSeguimiento(),
            '--periodo' => 'marzo',
        ])->assertFailed();
    }
}
