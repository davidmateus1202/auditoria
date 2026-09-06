<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Services\Extraccion\ExtractorExcel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * La autoevaluación real de Morichal: es la entrada principal del sistema.
 *
 * Los porcentajes de cumplimiento se contrastan contra la hoja RESULTADOS del
 * propio archivo, que es la cifra que la E.S.E. viene reportando.
 */
class ExtraccionAutoevaluacionTest extends TestCase
{
    use UsaArchivosReales;

    private function extraer()
    {
        return (new ExtractorExcel())->extraer($this->archivoAutoevaluacion());
    }

    #[Test]
    public function detecta_el_formato_por_la_hoja_informe(): void
    {
        $this->assertSame(
            TipoFormato::Autoevaluacion,
            (new ExtractorExcel())->detectar($this->archivoAutoevaluacion())
        );
    }

    #[Test]
    public function lee_los_metadatos_de_la_cabecera_del_informe(): void
    {
        $resultado = $this->extraer();

        $this->assertSame('Centro de Salud Morichal', $resultado->sede);
        $this->assertStringContainsString('Luz Delia Miraval', (string) $resultado->auditor);
        $this->assertNotNull($resultado->fechaAuditoria);
    }

    #[Test]
    public function extrae_14_hallazgos_reales_de_la_hoja_informe(): void
    {
        $resultado = $this->extraer();

        $this->assertSame(14, $resultado->totalHallazgos());

        $this->assertSame([
            CatalogoEstandares::E2_INFRAESTRUCTURA => 8,
            CatalogoEstandares::E3_DOTACION => 3,
            CatalogoEstandares::E4_MEDICAMENTOS => 1,
            CatalogoEstandares::E5_PROCESOS_PRIORITARIOS => 1,
            CatalogoEstandares::E0_SERVICIOS_NO_PRESTADOS => 1,
        ], array_filter($resultado->porEstandar()));
    }

    #[Test]
    public function no_cuenta_como_hallazgo_lo_que_dice_cumple_o_no_verificado(): void
    {
        $clasificacion = $this->extraer()->porClasificacion();

        // «Cumple» en Talento humano y «No Aplica» en Interdependencia.
        $this->assertSame(2, $clasificacion['cumple']);
        // «No verificado» en Historia clínica: es alerta de cobertura, no un logro.
        $this->assertSame(1, $clasificacion['sin_dato']);
        $this->assertSame(14, $clasificacion['hallazgo']);
    }

    #[Test]
    public function una_celda_con_una_lista_es_un_solo_hallazgo(): void
    {
        // La celda del consultorio de examen físico termina en «no cuenta con:»
        // y sigue con tres áreas en líneas aparte. Partirla daría 16 hallazgos
        // en vez de 14, que es justo el defecto que este sistema corrige.
        $consultorio = array_values(array_filter(
            $this->extraer()->hallazgosReales(),
            static fn ($h) => str_contains($h->descripcion, 'examen f')
        ));

        $this->assertCount(1, $consultorio);
        $this->assertStringContainsString('Área para entrevista', $consultorio[0]->descripcion);
        $this->assertStringContainsString('barrera física', $consultorio[0]->descripcion);
    }

    #[Test]
    public function el_cumplimiento_oficial_reproduce_la_hoja_resultados(): void
    {
        $esperado = [
            CatalogoEstandares::E1_TALENTO_HUMANO => 12 / 13,
            CatalogoEstandares::E2_INFRAESTRUCTURA => 20 / 27,
            CatalogoEstandares::E3_DOTACION => 5 / 8,
            CatalogoEstandares::E4_MEDICAMENTOS => 7 / 10,
            CatalogoEstandares::E5_PROCESOS_PRIORITARIOS => 20 / 23,
            CatalogoEstandares::E6_HISTORIA_CLINICA => 1.0,
        ];

        $obtenido = [];

        foreach ($this->extraer()->cumplimiento() as $resumen) {
            if ($resumen->servicio === 'Todos los servicios') {
                $obtenido[$resumen->codigoEstandar] = $resumen->porcentajeOficial();
            }
        }

        foreach ($esperado as $codigo => $valor) {
            $this->assertEqualsWithDelta(
                $valor,
                $obtenido[$codigo],
                0.0001,
                "El cumplimiento oficial de {$codigo} no coincide con la hoja RESULTADOS"
            );
        }
    }

    #[Test]
    public function el_total_del_servicio_reproduce_la_formula_de_la_hoja(): void
    {
        $servicio = $this->extraer()->porServicio()['Todos los servicios'];

        // La celda J7 de RESULTADOS es =(C7+D7+E7+F7+G7+H7)/6: promedio simple
        // de los seis primeros estándares, con Interdependencia fuera del total.
        $this->assertEqualsWithDelta(0.8097304802, $servicio->totalOficial(), 0.000001);

        // La razón agregada sobre todos los criterios da distinto porque no
        // depende de cuántos criterios tenga cada estándar.
        $this->assertEqualsWithDelta(0.8111, $servicio->totalPonderado(), 0.001);
    }

    #[Test]
    public function el_total_estricto_es_menor_porque_no_regala_los_no_aplica(): void
    {
        $servicio = $this->extraer()->porServicio()['Todos los servicios'];

        // La fórmula oficial cuenta «No aplica» como cumplimiento y por eso infla.
        $this->assertGreaterThan($servicio->totalEstricto(), $servicio->totalOficial());
        $this->assertEqualsWithDelta(0.7119, $servicio->totalEstricto(), 0.001);
    }

    #[Test]
    public function el_total_oficial_ignora_interdependencia(): void
    {
        $servicio = $this->extraer()->porServicio()['Consulta externa general'];

        // Consulta externa tiene Interdependencia evaluada, pero la hoja divide
        // siempre entre seis y deja esa columna fuera: 0,9444 y no 0,9524.
        $this->assertEqualsWithDelta(0.9444444, $servicio->totalOficial(), 0.000001);
        $this->assertArrayHasKey(
            CatalogoEstandares::E7_INTERDEPENDENCIA,
            $servicio->porEstandar,
            'el estándar se calcula, pero no entra al total oficial'
        );
    }

    #[Test]
    public function resuelve_los_once_servicios_del_catalogo(): void
    {
        $servicios = [];

        foreach ($this->extraer()->criterios as $criterio) {
            $servicios[$criterio->servicio] = true;
        }

        // Dos hojas no traen fila de título («Hospit Baja» y «Laboratorio»):
        // el nombre sale del catálogo, no de leer lo que haya encima.
        $this->assertCount(11, $servicios);
        $this->assertArrayHasKey('Hospitalización de baja complejidad', $servicios);
        $this->assertArrayHasKey('Laboratorio clínico', $servicios);
        $this->assertArrayHasKey('Todos los servicios', $servicios);
    }

    #[Test]
    public function avisa_que_la_plantilla_cita_la_norma_derogada(): void
    {
        $resultado = $this->extraer();

        // La plantilla dice «RES 2003 DE 2014» en ocho hojas mientras su propia
        // hoja INFORME ya cita la 3100 de 2019. El catálogo aplicado es siempre
        // el vigente: la etiqueta del archivo solo genera un aviso.
        $this->assertStringContainsString('2003', (string) $resultado->normativaDeclarada);
        $this->assertNotEmpty($resultado->advertencias);
        $this->assertStringContainsString(
            CatalogoEstandares::NORMATIVA,
            implode(' ', $resultado->advertencias)
        );
    }
}
