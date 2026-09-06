<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Domain\Reconciliacion\Dto\HallazgoVigente;
use App\Domain\Reconciliacion\Emparejador;
use App\Domain\Reconciliacion\IndiceIdf;
use App\Domain\Reconciliacion\ReconciliadorVigencia;
use App\Domain\Reconciliacion\Similitud;
use App\Services\Extraccion\ExtractorExcel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

class ReconciliadorVigenciaTest extends TestCase
{
    use UsaArchivosReales;

    private function reconciliador(array $corpus = []): ReconciliadorVigencia
    {
        return new ReconciliadorVigencia(new Emparejador(new Similitud(new IndiceIdf($corpus))));
    }

    private function entrante(string $estandar, string $texto): HallazgoExtraido
    {
        return new HallazgoExtraido(
            codigoEstandar: $estandar,
            descripcion: $texto,
            clasificacion: ClasificacionHallazgo::Hallazgo,
            hoja: 'INFORME',
            fila: 1,
        );
    }

    #[Test]
    public function los_catorce_hallazgos_de_morichal_persisten_al_reauditar(): void
    {
        $extractor = new ExtractorExcel();
        $seguimiento = $extractor->extraer($this->archivoSeguimiento());
        $autoevaluacion = $extractor->extraer($this->archivoAutoevaluacion());

        $vigentes = [];
        $corpus = [];
        $id = 1;

        foreach ($seguimiento->hallazgosReales() as $hallazgo) {
            $corpus[] = $hallazgo->descripcion;

            if ($hallazgo->hoja === 'MORICHAL') {
                $vigentes[] = new HallazgoVigente($id++, $hallazgo->codigoEstandar, $hallazgo->descripcion);
            }
        }

        $propuesta = $this->reconciliador($corpus)->reconciliar(
            $vigentes,
            $autoevaluacion->hallazgosReales(),
            $autoevaluacion->estandaresEvaluados(),
        );

        // Es la misma auditoría vista desde los dos archivos: todo persiste,
        // nada nace y nada se cierra.
        $this->assertCount(14, $propuesta->de(DestinoReconciliacion::Persiste));
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::Nuevo));
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::CandidatoCierre));
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::Revision));
        $this->assertSame(0, $propuesta->pendientes());
    }

    #[Test]
    public function un_hallazgo_que_ya_no_aparece_se_propone_para_cierre(): void
    {
        $vigentes = [
            new HallazgoVigente(1, CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.'),
            new HallazgoVigente(2, CatalogoEstandares::E3_DOTACION, 'Las escalerillas se encuentran en regular estado y algunas balanzas fallan.'),
        ];

        $entrantes = [
            $this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.'),
        ];

        $propuesta = $this->reconciliador()->reconciliar(
            $vigentes,
            $entrantes,
            [CatalogoEstandares::E2_INFRAESTRUCTURA, CatalogoEstandares::E3_DOTACION],
        );

        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Persiste));

        $cierres = $propuesta->de(DestinoReconciliacion::CandidatoCierre);
        $this->assertCount(1, $cierres);
        $this->assertSame(2, $cierres[0]->hallazgoId);
        $this->assertTrue($cierres[0]->destino->exigeConfirmacion(), 'ningún cierre se aplica solo');
    }

    #[Test]
    public function sin_estandar_evaluado_no_hay_cierre_sino_alerta_de_cobertura(): void
    {
        $vigentes = [
            new HallazgoVigente(1, CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.'),
            new HallazgoVigente(2, CatalogoEstandares::E3_DOTACION, 'Las escalerillas se encuentran en regular estado y algunas balanzas fallan.'),
        ];

        // La auditoría nueva solo revisó infraestructura: dotación volvió sin dato.
        $propuesta = $this->reconciliador()->reconciliar(
            $vigentes,
            [$this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.')],
            [CatalogoEstandares::E2_INFRAESTRUCTURA],
        );

        // Que no aparezca no prueba que se resolvió: puede que nadie lo mirara.
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::CandidatoCierre));

        $noVerificados = $propuesta->de(DestinoReconciliacion::NoVerificado);
        $this->assertCount(1, $noVerificados);
        $this->assertSame(2, $noVerificados[0]->hallazgoId);
        $this->assertStringContainsString('no se evaluó', $noVerificados[0]->motivo);
    }

    #[Test]
    public function un_problema_nuevo_se_registra_como_nuevo(): void
    {
        $propuesta = $this->reconciliador()->reconciliar(
            [new HallazgoVigente(1, CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.')],
            [
                $this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.'),
                $this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'El deposito de residuos no cuenta con seguridad y las puertas permanecen abiertas.'),
            ],
            [CatalogoEstandares::E2_INFRAESTRUCTURA],
        );

        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Persiste));
        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Nuevo));
    }

    #[Test]
    public function nunca_compara_entre_estandares_distintos(): void
    {
        // Mismo texto, distinto estándar: el bloqueo lo impide, y con razón —
        // el estándar es parte de la identidad del hallazgo.
        $texto = 'La nevera de odontologia se encuentra con la base oxidada.';

        $propuesta = $this->reconciliador()->reconciliar(
            [new HallazgoVigente(1, CatalogoEstandares::E3_DOTACION, $texto)],
            [$this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, $texto)],
            [CatalogoEstandares::E2_INFRAESTRUCTURA, CatalogoEstandares::E3_DOTACION],
        );

        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Nuevo));
        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::CandidatoCierre));
        $this->assertSame([], $propuesta->de(DestinoReconciliacion::Persiste));
    }

    #[Test]
    public function un_vigente_no_puede_emparejarse_con_dos_entrantes(): void
    {
        $texto = 'Las escalerillas se encuentran en regular estado y algunas balanzas estan fallando.';

        $propuesta = $this->reconciliador()->reconciliar(
            [new HallazgoVigente(1, CatalogoEstandares::E3_DOTACION, $texto)],
            [
                $this->entrante(CatalogoEstandares::E3_DOTACION, $texto),
                $this->entrante(CatalogoEstandares::E3_DOTACION, $texto),
            ],
            [CatalogoEstandares::E3_DOTACION],
        );

        // El primero se lleva el vínculo; el segundo queda como nuevo para que
        // alguien decida si es un duplicado de la carga.
        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Persiste));
        $this->assertCount(1, $propuesta->de(DestinoReconciliacion::Nuevo));
    }

    #[Test]
    public function la_zona_gris_va_a_revision_con_sus_alternativas(): void
    {
        $propuesta = $this->reconciliador()->reconciliar(
            [new HallazgoVigente(1, CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas especialmente las de vacunacion y Enfermeria.')],
            [$this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios medicos estan en mal estado, sobre todo las del area de vacunacion y enfermeria.')],
            [CatalogoEstandares::E2_INFRAESTRUCTURA],
        );

        $revisiones = $propuesta->de(DestinoReconciliacion::Revision);
        $this->assertCount(1, $revisiones);
        $this->assertNotEmpty($revisiones[0]->alternativas, 'el auditor tiene que ver contra qué se comparó');
        $this->assertSame(1, $propuesta->pendientes());
    }

    #[Test]
    public function una_sede_sin_historia_registra_todo_como_nuevo(): void
    {
        $propuesta = $this->reconciliador()->reconciliar(
            [],
            [
                $this->entrante(CatalogoEstandares::E2_INFRAESTRUCTURA, 'Las puertas de los consultorios se encuentran deterioradas.'),
                $this->entrante(CatalogoEstandares::E3_DOTACION, 'Las escalerillas se encuentran en regular estado.'),
            ],
            [CatalogoEstandares::E2_INFRAESTRUCTURA, CatalogoEstandares::E3_DOTACION],
        );

        $this->assertCount(2, $propuesta->de(DestinoReconciliacion::Nuevo));
        $this->assertSame(0, $propuesta->pendientes(), 'la primera carga no exige decisiones');
    }
}
