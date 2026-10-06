<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArchivoCargado;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Una carga que falla por celdas que no coinciden con el catálogo se corrige
 * desde la pantalla, sin editar el Excel: el servidor dice cuáles son, con su
 * sugerencia, y acepta los valores corregidos al volver a cargar.
 */
class CorreccionCargaTest extends TestCase
{
    use RefreshDatabase;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->create());
        Storage::fake();
        $this->carpeta = storage_path('framework/testing/correccion');

        if (! is_dir($this->carpeta)) {
            mkdir($this->carpeta, 0o775, true);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta.'/*.xlsx') ?: []);
        parent::tearDown();
    }

    /** Una autoevaluación mínima: hoja INFORME con la sede y dos hallazgos. */
    private function autoevaluacion(string $sede, string $estandar1, string $estandar2 = 'Dotación'): UploadedFile
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('INFORME');
        $hoja->fromArray([
            ['CENTRO DE SALUD', $sede],
            ['FECHA', '2026-10-01'],
            ['ESTÁNDAR', 'HALLAZGO'],
            [$estandar1, 'La rampa de acceso no tiene pasamanos en ninguno de sus costados.'],
            [$estandar2, 'El tensiómetro del consultorio dos no tiene hoja de vida del equipo.'],
        ]);

        $ruta = $this->carpeta.'/'.uniqid('suh_').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return new UploadedFile($ruta, 'SUH prueba.xlsx', null, null, true);
    }

    private function nombreMorichal(): string
    {
        return Sede::where('codigo', 'MORICHAL')->value('nombre');
    }

    #[Test]
    public function informa_todas_las_celdas_no_reconocidas_con_su_sugerencia(): void
    {
        $archivo = $this->autoevaluacion($this->nombreMorichal(), 'Infraestructura fisica y sanitaria', 'DOTACON');

        $respuesta = $this->post('/api/auditorias/cargar', ['archivos' => [$archivo]], ['Accept' => 'application/json']);

        $fallo = $respuesta->json('fallidas.0');
        $this->assertSame('celdas_no_reconocidas', $fallo['tipo']);
        $this->assertCount(2, $fallo['problemas']);
        $this->assertSame(['INFORME', 'A4', 'Infraestructura'], [
            $fallo['problemas'][0]['hoja'], $fallo['problemas'][0]['celda'], $fallo['problemas'][0]['sugerencia'],
        ]);
        $this->assertSame(['A5', 'DOTACON', 'Dotación'], [
            $fallo['problemas'][1]['celda'], $fallo['problemas'][1]['encontrado'], $fallo['problemas'][1]['sugerencia'],
        ]);
    }

    #[Test]
    public function con_las_correcciones_carga_y_las_deja_registradas(): void
    {
        $archivo = $this->autoevaluacion($this->nombreMorichal(), 'INFRAESTRUCURA');

        $this->post('/api/auditorias/cargar', [
            'archivos' => [$archivo],
            'correcciones' => json_encode([['hoja' => 'INFORME', 'celda' => 'A4', 'valor' => 'Infraestructura']]),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('cargadas.0.extraccion.hallazgos', 2)
            ->assertJsonCount(0, 'fallidas');

        $this->assertSame(
            ['INFORME!A4' => 'Infraestructura'],
            ArchivoCargado::query()->latest('id')->first()->resumen['correcciones'],
        );
    }

    #[Test]
    public function la_sede_no_identificada_trae_la_sede_mas_parecida(): void
    {
        $archivo = $this->autoevaluacion($this->nombreMorichal().'l', 'Infraestructura');

        $fallo = $this->post('/api/auditorias/cargar', ['archivos' => [$archivo]], ['Accept' => 'application/json'])
            ->json('fallidas.0');

        $this->assertSame('sede_no_identificada', $fallo['tipo']);
        $this->assertSame($this->nombreMorichal(), $fallo['sugerencia']['nombre']);
        $this->assertSame(['INFORME', 'B1'], [$fallo['hoja'], $fallo['celda']]);
    }

    #[Test]
    public function una_correccion_mal_formada_se_rechaza(): void
    {
        $this->post('/api/auditorias/cargar', [
            'archivos' => [$this->autoevaluacion($this->nombreMorichal(), 'Infraestructura')],
            'correcciones' => json_encode([['hoja' => 'INFORME', 'celda' => 'A0; DROP', 'valor' => 'x']]),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correcciones');
    }
}
