<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\FotoAuditoria;
use App\Models\Sede;
use App\Models\User;
use App\Services\Evidencias\ServicioFotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Las fotos del registro fotográfico se extraen al cargar la auditoría y se
 * sirven en el módulo de evidencias.
 */
class EvidenciasTest extends TestCase
{
    use RefreshDatabase;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->create());
        Storage::fake();
        $this->carpeta = storage_path('framework/testing/evidencias');

        if (! is_dir($this->carpeta)) {
            mkdir($this->carpeta, 0o775, true);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta.'/*') ?: []);
        parent::tearDown();
    }

    #[Test]
    public function reconoce_las_hojas_de_fotos_por_su_nombre(): void
    {
        foreach (['FOTOS', 'fotos', 'Fotos', 'FOTO', 'EVIDENCIAS', 'Evidencia', 'FOTOGRAFIAS', 'registro fotografico'] as $nombre) {
            $this->assertTrue(ServicioFotos::esHojaDeFotos($nombre), $nombre);
        }

        foreach (['INFORME', 'RESULTADOS', 'Laboratorio', 'Indice'] as $nombre) {
            $this->assertFalse(ServicioFotos::esHojaDeFotos($nombre), $nombre);
        }
    }

    #[Test]
    public function al_cargar_extrae_las_fotos_y_el_modulo_las_sirve(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->autoevaluacion()]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('cargadas.0.fotos', 2);

        // Dos fotos distintas: el logo se descarta, la repetida cuenta una vez
        // y la imagen de la hoja INFORME no es evidencia.
        $fotos = FotoAuditoria::query()->orderBy('orden')->get();
        $this->assertCount(2, $fotos);
        $this->assertSame(['Fotos', 'Fotos'], $fotos->pluck('hoja')->all());
        $this->assertSame(['B2', 'E2'], $fotos->pluck('celda')->all());

        $listado = $this->getJson('/api/evidencias')->assertOk()->assertJsonPath('total', 2);
        $foto = $listado->json('datos.0.fotos.0');

        $this->get($foto['miniatura_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($foto['imagen_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function la_imagen_no_se_entrega_sin_firma(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->autoevaluacion()]], ['Accept' => 'application/json']);
        $foto = FotoAuditoria::query()->firstOrFail();

        $this->get("/api/evidencias/{$foto->id}/imagen?tamano=completa")->assertForbidden();
    }

    #[Test]
    public function eliminar_la_auditoria_borra_sus_fotos(): void
    {
        $this->post('/api/auditorias/cargar', ['archivos' => [$this->autoevaluacion()]], ['Accept' => 'application/json']);
        $auditoria = Auditoria::query()->firstOrFail();
        $rutas = FotoAuditoria::query()->pluck('ruta')->all();
        Storage::assertExists($rutas);

        $this->deleteJson("/api/auditorias/{$auditoria->id}?forzar=1")->assertOk();

        $this->assertSame(0, FotoAuditoria::count());
        Storage::assertMissing($rutas);
    }

    /** Autoevaluación mínima con una hoja «Fotos». */
    private function autoevaluacion(): UploadedFile
    {
        $libro = new Spreadsheet();
        $informe = $libro->getActiveSheet()->setTitle('INFORME');
        $informe->fromArray([
            ['CENTRO DE SALUD', Sede::where('codigo', 'MORICHAL')->value('nombre')],
            ['ESTÁNDAR', 'HALLAZGO'],
            ['Infraestructura', 'La rampa de acceso no tiene pasamanos en ninguno de sus costados.'],
        ]);
        // Una imagen fuera de las hojas de fotos (el logo del informe) no es evidencia.
        $this->pegar($informe, $this->imagen(400, 300, [10, 10, 10]), 'D1');

        $fotos = $libro->createSheet()->setTitle('Fotos');
        $this->pegar($fotos, $this->imagen(60, 60, [0, 0, 200]), 'A1');      // logo: muy pequeño
        $this->pegar($fotos, $this->imagen(640, 480, [200, 0, 0]), 'E2');
        $this->pegar($fotos, $this->imagen(480, 640, [0, 150, 0]), 'B2');
        $this->pegar($fotos, $this->imagen(640, 480, [200, 0, 0]), 'B20');   // repetida

        $ruta = $this->carpeta.'/'.uniqid('suh_').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return new UploadedFile($ruta, 'SUH Morichal.xlsx', null, null, true);
    }

    /** @param array{int, int, int} $rgb */
    private function imagen(int $ancho, int $alto, array $rgb): string
    {
        $ruta = $this->carpeta.'/'.implode('-', [$ancho, $alto, ...$rgb]).'.png';
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, (int) imagecolorallocate($imagen, ...$rgb));
        imagepng($imagen, $ruta);

        return $ruta;
    }

    private function pegar(Worksheet $hoja, string $imagen, string $celda): void
    {
        $dibujo = new Drawing();
        $dibujo->setPath($imagen);
        $dibujo->setCoordinates($celda);
        $dibujo->setWorksheet($hoja);
    }
}
