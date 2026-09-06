<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\Reconciliacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Cortes\ServicioCorte;
use App\Services\Reconciliacion\ImportadorLineaBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * Cargar una autoevaluación de punta a punta: subir, extraer, reconciliar y
 * confirmar. Es la entrada principal del sistema.
 */
class CargaAuditoriaTest extends TestCase
{
    use RefreshDatabase;
    use UsaArchivosReales;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->create());
    }

    private function archivoDeMorichal(): UploadedFile
    {
        return new UploadedFile(
            $this->archivoAutoevaluacion(),
            'SUH Morichal.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            test: true,
        );
    }

    private function conLineaBase(): void
    {
        (new ImportadorLineaBase())->importar($this->archivoSeguimiento(), '2026-03');
        (new ServicioCorte())->cerrar('2026-03');
    }

    #[Test]
    public function sube_una_autoevaluacion_y_detecta_la_sede_sola(): void
    {
        $respuesta = $this->post('/api/auditorias/cargar', [
            'archivos' => [$this->archivoDeMorichal()],
        ])->assertCreated();

        $respuesta->assertJsonPath('cargadas.0.sede', 'Centro de Salud Morichal');
        $respuesta->assertJsonPath('cargadas.0.extraccion.hallazgos', 14);
        $respuesta->assertJsonPath('fallidas', []);

        // Nada queda publicado: la auditoría espera confirmación.
        $this->assertDatabaseHas('auditorias', ['estado' => 'por_confirmar', 'version' => 1]);
        $this->assertSame(444, \App\Models\EvaluacionCriterio::count());
    }

    #[Test]
    public function el_mismo_archivo_no_se_carga_dos_veces(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]])
            ->assertCreated();

        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]])
            ->assertStatus(422)
            ->assertJsonPath('cargadas', [])
            ->assertJsonFragment(['archivo' => 'SUH Morichal.xlsx']);
    }

    #[Test]
    public function rechaza_una_matriz_de_seguimiento_explicando_donde_va(): void
    {
        $archivo = new UploadedFile(
            $this->archivoSeguimiento(),
            'Seguimiento.xls',
            'application/vnd.ms-excel',
            test: true,
        );

        $this->post('/api/auditorias/cargar', ['archivos' => [$archivo]])
            ->assertStatus(422)
            ->assertJsonPath('fallidas.0.archivo', 'Seguimiento.xls');
    }

    #[Test]
    public function rechaza_un_archivo_que_no_es_excel(): void
    {
        $this->post('/api/auditorias/cargar', [
            'archivos' => [UploadedFile::fake()->create('informe.pdf', 40)],
        ])->assertStatus(422)->assertJsonValidationErrors('archivos.0');
    }

    #[Test]
    public function la_primera_carga_de_una_sede_registra_todo_como_nuevo(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]])
            ->assertCreated();

        $auditoria = Auditoria::firstOrFail();

        $this->getJson("/api/auditorias/{$auditoria->id}/reconciliacion")
            ->assertOk()
            ->assertJsonPath('pendientes_de_decision', 0)
            ->assertJsonCount(14, 'por_destino.nuevo');
    }

    #[Test]
    public function confirmar_publica_la_auditoria_y_crea_los_hallazgos(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $auditoria = Auditoria::firstOrFail();

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar")
            ->assertOk()
            ->assertJsonPath('aplicado.nuevos', 14);

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();

        $this->assertSame(14, Hallazgo::deSede($sede->id)->count());
        $this->assertSame(8, Hallazgo::deSede($sede->id)->deEstandar('E2')->count());
        $this->assertSame('publicada', $auditoria->fresh()->estado);
        $this->assertDatabaseCount('hallazgo_apariciones', 14);
    }

    #[Test]
    public function contra_la_linea_base_reconoce_lo_que_persiste(): void
    {
        $this->conLineaBase();

        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]])
            ->assertCreated();

        $auditoria = Auditoria::where('origen', 'autoevaluacion')->firstOrFail();

        // Doce siguen vigentes y dos figuraban cerrados: son reincidencias.
        $this->getJson("/api/auditorias/{$auditoria->id}/reconciliacion")
            ->assertOk()
            ->assertJsonCount(12, 'por_destino.persiste')
            ->assertJsonCount(2, 'por_destino.reincidencia')
            ->assertJsonPath('pendientes_de_decision', 2);
    }

    #[Test]
    public function no_se_puede_confirmar_con_decisiones_pendientes(): void
    {
        $this->conLineaBase();
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $auditoria = Auditoria::where('origen', 'autoevaluacion')->firstOrFail();

        // Las reincidencias exigen que alguien decida si se reabren, y el
        // mensaje dice cuántas y cuáles en vez de un «faltan datos» inútil.
        $mensaje = $this->postJson("/api/auditorias/{$auditoria->id}/confirmar")
            ->assertStatus(422)
            ->json('mensaje');

        $this->assertStringContainsString('2 decisiones sin tomar', $mensaje);
        $this->assertStringContainsString('Reincidencia', $mensaje);

        $this->assertSame('por_confirmar', $auditoria->fresh()->estado);
    }

    #[Test]
    public function una_reincidencia_confirmada_reabre_el_hallazgo(): void
    {
        $this->conLineaBase();
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $auditoria = Auditoria::where('origen', 'autoevaluacion')->firstOrFail();

        $reincidencias = Reconciliacion::query()
            ->where('auditoria_id', $auditoria->id)
            ->where('destino', DestinoReconciliacion::Reincidencia->value)
            ->get();

        $decisiones = [];
        foreach ($reincidencias as $r) {
            $decisiones[$r->id] = ['accion' => 'reabrir'];
        }

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar", ['decisiones' => $decisiones])
            ->assertOk()
            ->assertJsonPath('aplicado.reabiertos', 2)
            ->assertJsonPath('aplicado.persiste', 12);

        foreach ($reincidencias as $r) {
            $this->assertSame(EstadoHallazgo::Abierto, Hallazgo::find($r->hallazgo_id)->estado);
        }
    }

    #[Test]
    public function cerrar_desde_el_cruce_exige_evidencia(): void
    {
        $this->conLineaBase();

        // Se inventa un hallazgo que la autoevaluación no reporta: al cargarla,
        // el reconciliador lo propondrá para cierre.
        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();
        $huerfano = Hallazgo::create([
            'sede_id' => $sede->id,
            'estandar_codigo' => 'E2',
            'descripcion' => 'La rampa de acceso al centro de salud carece de pasamanos en ambos costados.',
            'huella' => sha1('huerfano'),
            'estado' => EstadoHallazgo::Abierto,
        ]);

        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $auditoria = Auditoria::where('origen', 'autoevaluacion')->firstOrFail();

        $movimientos = Reconciliacion::where('auditoria_id', $auditoria->id)->get();
        $cierre = $movimientos->firstWhere('hallazgo_id', $huerfano->id);

        $this->assertNotNull($cierre);
        $this->assertSame(DestinoReconciliacion::CandidatoCierre, $cierre->destino);

        $decisiones = [];
        foreach ($movimientos as $m) {
            if ($m->destino->exigeConfirmacion()) {
                $decisiones[$m->id] = $m->id === $cierre->id
                    ? ['accion' => 'cerrar']            // sin evidencia: debe fallar
                    : ['accion' => 'reabrir'];
            }
        }

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar", ['decisiones' => $decisiones])
            ->assertStatus(422)
            ->assertSee('evidencia', false);

        // Con evidencia sí cierra.
        $decisiones[$cierre->id] = [
            'accion' => 'cerrar',
            'evidencia' => 'Acta de obra del 12 de abril: pasamanos instalados en ambos costados.',
        ];

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar", ['decisiones' => $decisiones])
            ->assertOk()
            ->assertJsonPath('aplicado.cerrados', 1);

        $this->assertSame(EstadoHallazgo::Cerrado, $huerfano->fresh()->estado);
        $this->assertDatabaseHas('seguimientos', ['hallazgo_id' => $huerfano->id]);
    }

    #[Test]
    public function mantener_abierto_un_candidato_a_cierre_no_lo_cierra(): void
    {
        $this->conLineaBase();

        $sede = Sede::where('codigo', 'MORICHAL')->firstOrFail();
        $huerfano = Hallazgo::create([
            'sede_id' => $sede->id,
            'estandar_codigo' => 'E2',
            'descripcion' => 'La rampa de acceso al centro de salud carece de pasamanos en ambos costados.',
            'huella' => sha1('huerfano2'),
            'estado' => EstadoHallazgo::Abierto,
        ]);

        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $auditoria = Auditoria::where('origen', 'autoevaluacion')->firstOrFail();

        $decisiones = [];
        foreach (Reconciliacion::where('auditoria_id', $auditoria->id)->get() as $m) {
            if ($m->destino->exigeConfirmacion()) {
                $decisiones[$m->id] = $m->hallazgo_id === $huerfano->id
                    ? ['accion' => 'mantener']
                    : ['accion' => 'reabrir'];
            }
        }

        $this->postJson("/api/auditorias/{$auditoria->id}/confirmar", ['decisiones' => $decisiones])
            ->assertOk()
            ->assertJsonPath('aplicado.cerrados', 0);

        $this->assertSame(EstadoHallazgo::Abierto, $huerfano->fresh()->estado);
    }

    #[Test]
    public function cada_carga_crea_una_version_y_conserva_la_anterior(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);
        $primera = Auditoria::firstOrFail();
        $this->postJson("/api/auditorias/{$primera->id}/confirmar")->assertOk();

        // El mismo contenido con otro nombre: otra versión, no un reemplazo.
        $copia = tempnam(sys_get_temp_dir(), 'suh').'.xlsx';
        copy($this->archivoAutoevaluacion(), $copia);
        file_put_contents($copia, file_get_contents($copia).' ');

        $this->post('/api/auditorias/cargar', [
            'archivos' => [new UploadedFile($copia, 'SUH Morichal v2.xlsx', null, test: true)],
        ]);

        $this->assertSame(2, Auditoria::where('sede_id', $primera->sede_id)->count());
        $this->assertSame([1, 2], Auditoria::orderBy('version')->pluck('version')->all());

        @unlink($copia);
    }

    #[Test]
    public function el_listado_muestra_las_auditorias_con_su_sede(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->archivoDeMorichal()]]);

        $this->getJson('/api/auditorias')
            ->assertOk()
            ->assertJsonPath('datos.0.sede.codigo', 'MORICHAL')
            ->assertJsonPath('datos.0.criterios_count', 444);
    }
}
