<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\CatalogoServicios;
use App\Domain\Extraccion\ClasificadorHallazgo;
use App\Domain\Extraccion\MapeadorEstado;
use App\Domain\Extraccion\SegmentadorHallazgos;
use App\Domain\Extraccion\TextoNormalizador;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DominioExtraccionTest extends TestCase
{
    #[Test]
    #[DataProvider('variantesDeEstandar')]
    public function el_catalogo_resuelve_las_variantes_reales(string $texto, string $esperado): void
    {
        $this->assertSame($esperado, CatalogoEstandares::resolver($texto));
    }

    /** Variantes medidas sobre los dos archivos: solo cambian mayúsculas y tildes. */
    public static function variantesDeEstandar(): array
    {
        return [
            'mayúsculas con tilde' => ['DOTACIÓN', CatalogoEstandares::E3_DOTACION],
            'mayúsculas sin tilde' => ['DOTACION', CatalogoEstandares::E3_DOTACION],
            'capitalizado' => ['Talento Humano', CatalogoEstandares::E1_TALENTO_HUMANO],
            'todo mayúsculas' => ['TALENTO HUMANO', CatalogoEstandares::E1_TALENTO_HUMANO],
            'con espacios sobrantes' => ['  INFRAESTRUCTURA  ', CatalogoEstandares::E2_INFRAESTRUCTURA],
            'con la y del seguimiento' => ['MEDICAMENTOS Y DISPOSITIVOS MEDICOS E INSUMOS', CatalogoEstandares::E4_MEDICAMENTOS],
            'con la coma de la autoevaluación' => ['MEDICAMENTOS, DISPOSITIVOS MÉDICOS E INSUMOS', CatalogoEstandares::E4_MEDICAMENTOS],
            'forma corta' => ['INTERDEPENDENCIA', CatalogoEstandares::E7_INTERDEPENDENCIA],
            'forma larga' => ['INTERDEPENDENCIA DE SERVICIOS', CatalogoEstandares::E7_INTERDEPENDENCIA],
            'abreviatura de RESULTADOS' => ['7.Interdep de Serv', CatalogoEstandares::E7_INTERDEPENDENCIA],
        ];
    }

    #[Test]
    public function un_estandar_desconocido_no_se_asigna_al_mas_parecido(): void
    {
        // Asignar por parecido es como se contamina una serie histórica sin que
        // nadie se entere: sin coincidencia exacta, no hay resolución.
        $this->assertNull(CatalogoEstandares::resolver('INFRESTRUCTURA'));
        $this->assertNull(CatalogoEstandares::resolver('DOTACION DE EQUIPOS BIOMEDICOS'));
        $this->assertNull(CatalogoEstandares::resolver('ESTANDAR NUEVO'));
    }

    #[Test]
    public function los_rotulos_de_las_secciones_ii_y_iii_se_reconocen_como_fuera_de_alcance(): void
    {
        foreach (['CONDICIONES TECNICO ADMINISTRATIVAS', 'CAPACIDAD DE SUFICIENCIA PATRIMONIAL Y FINANCIERA'] as $rotulo) {
            $this->assertNull(CatalogoEstandares::resolver($rotulo));
            $this->assertTrue(CatalogoEstandares::estaFueraDeAlcance($rotulo), "«{$rotulo}» debe ignorarse, no rechazar el archivo");
        }
    }

    #[Test]
    public function e0_esta_marcado_como_no_normativo(): void
    {
        $this->assertFalse(CatalogoEstandares::esNormativo(CatalogoEstandares::E0_SERVICIOS_NO_PRESTADOS));
        $this->assertTrue(CatalogoEstandares::esNormativo(CatalogoEstandares::E2_INFRAESTRUCTURA));
        $this->assertStringContainsString('3100', CatalogoEstandares::NORMATIVA);
    }

    #[Test]
    #[DataProvider('celdasDeHallazgo')]
    public function clasifica_lo_que_hay_en_la_celda(string $texto, ClasificacionHallazgo $esperado): void
    {
        $this->assertSame($esperado, ClasificadorHallazgo::clasificar($texto));
    }

    public static function celdasDeHallazgo(): array
    {
        return [
            'cumple' => ['Cumple', ClasificacionHallazgo::Cumple],
            'no aplica' => ['No Aplica', ClasificacionHallazgo::Cumple],
            'sin hallazgos' => ['Sin hallazgos', ClasificacionHallazgo::Cumple],
            'no verificado' => ['No verificado', ClasificacionHallazgo::SinDato],
            'no auditado' => ['No auditado', ClasificacionHallazgo::SinDato],
            'vacío' => ['', ClasificacionHallazgo::SinDato],
            'texto corto' => ['Falta', ClasificacionHallazgo::Dudoso],
            'hallazgo real' => [
                'Las puertas de los consultorios se encuentran deterioradas.',
                ClasificacionHallazgo::Hallazgo,
            ],
        ];
    }

    #[Test]
    public function los_tres_estados_de_la_matriz_se_mapean_exactos(): void
    {
        $this->assertSame(EstadoHallazgo::Abierto, MapeadorEstado::mapear('Hallazgo Abierto'));
        $this->assertSame(EstadoHallazgo::AbiertoConEvidencia, MapeadorEstado::mapear('Hallazgos Abiertos con Evidencia de Gestión'));
        $this->assertSame(EstadoHallazgo::Cerrado, MapeadorEstado::mapear('Hallazgos Cerrados'));

        // Una celda vacía es ausencia de seguimiento, no un estado elegido.
        $this->assertSame(EstadoHallazgo::SinDato, MapeadorEstado::mapear(''));
        $this->assertTrue(MapeadorEstado::esReconocible(''));
        $this->assertFalse(MapeadorEstado::esReconocible('En trámite'));
    }

    #[Test]
    public function una_lista_introducida_por_dos_puntos_es_un_solo_hallazgo(): void
    {
        $celda = "Consultorio donde se realiza examen físico: Ambiente con mínimo 10 m2 no cuenta con:\n"
            ."Área para entrevista.\n"
            ."Área de examen.\n"
            .'separadas entre sí por barrera física fija o móvil';

        $fragmentos = SegmentadorHallazgos::segmentar($celda);

        $this->assertCount(1, $fragmentos);
        $this->assertStringContainsString('barrera física', $fragmentos[0]);
    }

    #[Test]
    public function varias_vinetas_independientes_si_se_separan(): void
    {
        $celda = "- Las puertas de los consultorios están deterioradas.\n"
            ."- La nevera de odontología tiene la base oxidada.\n"
            .'- Las escalerillas están en regular estado.';

        $this->assertCount(3, SegmentadorHallazgos::segmentar($celda));
    }

    #[Test]
    public function una_linea_partida_se_vuelve_a_unir(): void
    {
        $celda = "Las áreas de vacunación y la estación de enfermería\nrequieren mantenimiento de pintura y estuco.";

        $fragmentos = SegmentadorHallazgos::segmentar($celda);

        $this->assertCount(1, $fragmentos);
        $this->assertStringContainsString('mantenimiento', $fragmentos[0]);
    }

    #[Test]
    public function la_normalizacion_colapsa_tildes_espacios_y_puntuacion(): void
    {
        $this->assertSame('DOTACION', TextoNormalizador::canonica('  Dotación.  '));
        $this->assertSame('DOTACION', TextoNormalizador::canonica("Dotaci\u{00F3}n"));
        $this->assertSame('HISTORIA CLINICA Y REGISTROS', TextoNormalizador::canonica('Historia Clínica y Registros'));
        // El espacio duro llega desde Excel y no lo captura \s de forma fiable.
        $this->assertSame('TALENTO HUMANO', TextoNormalizador::canonica("Talento\u{00A0}Humano"));
    }

    #[Test]
    public function el_catalogo_de_servicios_resuelve_por_el_nombre_de_la_hoja(): void
    {
        // Dos hojas no traen fila de título: el nombre tiene que salir de aquí.
        $this->assertSame('Hospitalización de baja complejidad', CatalogoServicios::resolver('Hospit Baja')['nombre']);
        $this->assertSame('Laboratorio clínico', CatalogoServicios::resolver('Laboratorio')['nombre']);
        $this->assertSame('Todos los servicios', CatalogoServicios::resolver('Todos ')['nombre']);

        // Una sede puede habilitar un servicio nuevo: eso no invalida el archivo.
        $nuevo = CatalogoServicios::resolver('Rehabilitacion');
        $this->assertFalse($nuevo['enCatalogo']);
        $this->assertSame('Rehabilitacion', $nuevo['nombre']);
    }
}
