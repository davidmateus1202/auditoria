<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Services\Extraccion\ExtractorExcel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * La matriz de seguimiento real, con las cifras verificadas a mano como
 * referencia. Si alguien cambia el extractor y estos números se mueven, algo
 * se rompió: son el contrato del sistema con los datos de la E.S.E.
 */
class ExtraccionSeguimientoTest extends TestCase
{
    use UsaArchivosReales;

    private function extraer()
    {
        return (new ExtractorExcel())->extraer($this->archivoSeguimiento());
    }

    #[Test]
    public function detecta_el_formato_por_el_contenido_y_no_por_el_nombre(): void
    {
        $this->assertSame(
            TipoFormato::Seguimiento,
            (new ExtractorExcel())->detectar($this->archivoSeguimiento())
        );
    }

    #[Test]
    public function cuenta_223_hallazgos_reales_y_no_los_238_del_consolidado(): void
    {
        $resultado = $this->extraer();

        // El consolidado histórico reporta 238 porque cuenta como hallazgos
        // cerrados 15 filas que dicen «Cumple» o «No auditado».
        $this->assertSame(253, $resultado->filasLeidas, 'filas con texto bajo la sección I');
        $this->assertSame(223, $resultado->totalHallazgos(), 'hallazgos reales tras descartar los centinelas');

        $clasificacion = $resultado->porClasificacion();
        $this->assertSame(20, $clasificacion['cumple']);
        $this->assertSame(10, $clasificacion['sin_dato']);
        $this->assertSame(30, $clasificacion['cumple'] + $clasificacion['sin_dato'], '253 - 223 = 30 filas que no son hallazgo');
    }

    #[Test]
    public function el_reparto_por_estandar_coincide_con_el_conteo_manual(): void
    {
        $porEstandar = array_filter($this->extraer()->porEstandar());

        $this->assertSame([
            CatalogoEstandares::E2_INFRAESTRUCTURA => 120,
            CatalogoEstandares::E3_DOTACION => 60,
            CatalogoEstandares::E4_MEDICAMENTOS => 26,
            CatalogoEstandares::E5_PROCESOS_PRIORITARIOS => 15,
            CatalogoEstandares::E7_INTERDEPENDENCIA => 1,
            CatalogoEstandares::E0_SERVICIOS_NO_PRESTADOS => 1,
        ], $porEstandar);

        // Ocho de cada diez hallazgos del municipio son infraestructura o dotación.
        $this->assertSame(180, $porEstandar[CatalogoEstandares::E2_INFRAESTRUCTURA] + $porEstandar[CatalogoEstandares::E3_DOTACION]);
    }

    #[Test]
    public function el_reparto_por_estado_reproduce_el_consolidado_en_abiertos(): void
    {
        $porEstado = $this->extraer()->porEstado();

        // Los 116 abiertos coinciden exactamente con el consolidado oficial.
        $this->assertSame(116, $porEstado[EstadoHallazgo::Abierto->value]);
        $this->assertSame(69, $porEstado[EstadoHallazgo::AbiertoConEvidencia->value]);
        $this->assertSame(33, $porEstado[EstadoHallazgo::Cerrado->value]);
        $this->assertSame(5, $porEstado[EstadoHallazgo::SinDato->value]);
        $this->assertSame(223, array_sum($porEstado));
    }

    #[Test]
    public function el_avance_real_es_menor_que_el_reportado(): void
    {
        $porEstado = $this->extraer()->porEstado();
        $avance = $porEstado[EstadoHallazgo::Cerrado->value] / 223;

        // 14,8 % real contra el 19,7 % que reporta el consolidado.
        $this->assertEqualsWithDelta(0.148, $avance, 0.001);
        $this->assertLessThan(0.197, $avance);
    }

    #[Test]
    public function cada_sede_aporta_los_hallazgos_contados_a_mano(): void
    {
        $porSede = [];

        foreach ($this->extraer()->hallazgos as $hallazgo) {
            if ($hallazgo->cuenta()) {
                $porSede[$hallazgo->hoja] = ($porSede[$hallazgo->hoja] ?? 0) + 1;
            }
        }

        ksort($porSede);

        $this->assertSame([
            'BARZAL' => 34, 'CEMI' => 13, 'ESPERANZA' => 26, 'KIRPAS' => 18,
            'MORICHAL' => 14, 'POPULAR' => 17, 'PORFIA' => 27, 'PORVENIR' => 25,
            'RECREO' => 32, 'RELIQUIA' => 17,
        ], $porSede);

        $this->assertSame(223, array_sum($porSede));
    }

    #[Test]
    public function las_secciones_ii_y_iii_no_aportan_hallazgos(): void
    {
        // Sin cortar el arrastre del estándar en los encabezados romanos, las
        // filas de «Condiciones técnico-administrativas» heredarían
        // Interdependencia y sumarían 20 hallazgos que no existen.
        $resultado = $this->extraer();

        $this->assertSame(1, $resultado->porEstandar()[CatalogoEstandares::E7_INTERDEPENDENCIA]);
        $this->assertSame([], $resultado->descartes, 'nada debería quedar huérfano de estándar');
    }

    #[Test]
    public function conserva_la_accion_y_el_responsable_de_cada_hallazgo(): void
    {
        $morichal = array_values(array_filter(
            $this->extraer()->hallazgos,
            static fn ($h) => $h->hoja === 'MORICHAL' && $h->cuenta()
        ));

        $this->assertCount(14, $morichal);
        $this->assertStringContainsString('Medicina laboral', $morichal[0]->descripcion);
        $this->assertSame(CatalogoEstandares::E0_SERVICIOS_NO_PRESTADOS, $morichal[0]->codigoEstandar);
        $this->assertNotNull($morichal[0]->accionPropuesta);
        $this->assertNotNull($morichal[0]->responsable, 'el responsable viene combinado en vertical y se arrastra');
    }
}
