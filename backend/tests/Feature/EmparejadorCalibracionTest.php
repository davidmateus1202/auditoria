<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reconciliacion\Emparejador;
use App\Domain\Reconciliacion\IndiceIdf;
use App\Domain\Reconciliacion\Similitud;
use App\Services\Extraccion\ExtractorExcel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\UsaArchivosReales;

/**
 * La calibración del emparejador, verificada contra los archivos reales.
 *
 * Morichal aparece en los dos archivos con los MISMOS catorce hallazgos
 * redactados por separado: es verdad de terreno gratis. El resto del municipio
 * aporta el contraste de hallazgos genuinamente distintos.
 *
 * Si estos números se mueven, los umbrales dejaron de estar justificados.
 */
class EmparejadorCalibracionTest extends TestCase
{
    use UsaArchivosReales;

    /** @var array<string, list<array{estandar:string,texto:string}>> */
    private array $porSede = [];

    private array $autoevaluacion = [];

    private Emparejador $emparejador;

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = new ExtractorExcel();

        $seguimiento = $extractor->extraer($this->archivoSeguimiento());
        $corpus = [];

        foreach ($seguimiento->hallazgosReales() as $hallazgo) {
            $this->porSede[$hallazgo->hoja][] = [
                'estandar' => $hallazgo->codigoEstandar,
                'texto' => $hallazgo->descripcion,
            ];
            $corpus[] = $hallazgo->descripcion;
        }

        foreach ($extractor->extraer($this->archivoAutoevaluacion())->hallazgosReales() as $hallazgo) {
            $this->autoevaluacion[] = [
                'estandar' => $hallazgo->codigoEstandar,
                'texto' => $hallazgo->descripcion,
            ];
        }

        // El IDF se calcula sobre los hallazgos reales del municipio, no sobre
        // un corpus general del español.
        $this->emparejador = new Emparejador(new Similitud(new IndiceIdf($corpus)));
    }

    #[Test]
    public function empareja_los_catorce_hallazgos_de_morichal_entre_los_dos_archivos(): void
    {
        $vigentes = $this->porSede['MORICHAL'];

        $this->assertCount(14, $vigentes);
        $this->assertCount(14, $this->autoevaluacion);

        $aciertos = 0;
        $peorVerdadero = 1.0;
        $usados = [];

        foreach ($this->autoevaluacion as $indice => $entrante) {
            $candidatos = [];

            foreach ($vigentes as $j => $vigente) {
                if ($vigente['estandar'] === $entrante['estandar'] && ! isset($usados[$j])) {
                    $candidatos[$j] = $vigente['texto'];
                }
            }

            $resultado = $this->emparejador->mejor($entrante['texto'], $candidatos);

            $this->assertTrue(
                $resultado->esFirme(),
                sprintf(
                    'El hallazgo %d («%s…») debía emparejar con firmeza y quedó en %.3f',
                    $indice,
                    mb_substr($entrante['texto'], 0, 40),
                    $resultado->similitud
                )
            );

            $usados[$resultado->clave] = true;
            $peorVerdadero = min($peorVerdadero, $resultado->similitud);
            $aciertos++;
        }

        $this->assertSame(14, $aciertos, 'los catorce pares deben resolverse');

        // El par más difícil es el de servicios no prestados: la matriz de
        // seguimiento le añade «Optometría» a la lista del informe.
        $this->assertGreaterThan(
            Emparejador::UMBRAL_ALTO,
            $peorVerdadero,
            'el peor par verdadero debe quedar por encima del umbral'
        );
        $this->assertGreaterThan(0.90, $peorVerdadero);
    }

    #[Test]
    public function el_orden_en_la_hoja_no_se_usa_como_pista(): void
    {
        // Dos hallazgos de Morichal aparecen en orden invertido entre un
        // archivo y otro. Si el emparejador se apoyara en la posición, fallaría.
        $vigentes = $this->porSede['MORICHAL'];
        $revueltos = $vigentes;
        shuffle($revueltos);

        $aciertos = 0;

        foreach ($this->autoevaluacion as $entrante) {
            $candidatos = [];

            foreach ($revueltos as $j => $vigente) {
                if ($vigente['estandar'] === $entrante['estandar']) {
                    $candidatos[$j] = $vigente['texto'];
                }
            }

            if ($this->emparejador->mejor($entrante['texto'], $candidatos)->esFirme()) {
                $aciertos++;
            }
        }

        $this->assertSame(14, $aciertos);
    }

    #[Test]
    public function dentro_de_una_sede_los_hallazgos_distintos_no_se_confunden(): void
    {
        $similitud = new Similitud(new IndiceIdf($this->todosLosTextos()));
        $peor = 0.0;
        $pares = 0;
        $sobreUmbral = 0;

        foreach ($this->porSede as $hallazgos) {
            $total = count($hallazgos);

            for ($i = 0; $i < $total; $i++) {
                for ($j = $i + 1; $j < $total; $j++) {
                    if ($hallazgos[$i]['estandar'] !== $hallazgos[$j]['estandar']) {
                        continue;
                    }

                    $puntaje = $similitud->entre($hallazgos[$i]['texto'], $hallazgos[$j]['texto']);
                    $peor = max($peor, $puntaje);
                    $pares++;

                    if ($puntaje >= Emparejador::UMBRAL_ALTO) {
                        $sobreUmbral++;
                    }
                }
            }
        }

        $this->assertGreaterThan(1000, $pares, 'la muestra debe ser grande para que el dato signifique algo');
        $this->assertSame(0, $sobreUmbral, 'ningún par de hallazgos distintos debe superar el umbral');
        $this->assertLessThan(0.60, $peor, 'el par distinto más parecido debe quedar lejos del corte');
    }

    #[Test]
    public function entre_sedes_distintas_si_hay_coincidencias_perfectas(): void
    {
        // Por eso el bloqueo por sede es obligatorio: los auditores copian el
        // mismo texto de un centro de salud a otro. «Los martillos de reflejos
        // se encuentran en regular estado» está idéntico en Barzal y Porvenir.
        // Sin bloqueo, el sistema cerraría hallazgos de una sede con evidencia
        // de otra.
        $similitud = new Similitud(new IndiceIdf($this->todosLosTextos()));
        $sedes = array_keys($this->porSede);
        $identicos = 0;
        $sobreUmbral = 0;

        for ($a = 0; $a < count($sedes); $a++) {
            for ($b = $a + 1; $b < count($sedes); $b++) {
                foreach ($this->porSede[$sedes[$a]] as $uno) {
                    foreach ($this->porSede[$sedes[$b]] as $otro) {
                        if ($uno['estandar'] !== $otro['estandar']) {
                            continue;
                        }

                        $puntaje = $similitud->entre($uno['texto'], $otro['texto']);

                        if ($puntaje >= Emparejador::UMBRAL_ALTO) {
                            $sobreUmbral++;
                        }

                        if ($puntaje > 0.999) {
                            $identicos++;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $sobreUmbral, 'hay decenas de textos repetidos entre sedes');
        $this->assertGreaterThan(0, $identicos, 'y varios idénticos carácter por carácter');
    }

    #[Test]
    public function la_reescritura_se_gradua_de_persiste_a_revision(): void
    {
        $similitud = new Similitud(new IndiceIdf($this->todosLosTextos()));

        $original = 'Las puertas de los consultorios se encuentran deterioradas especialmente '
            .'las de vacunacion y Enfermeria.';

        // Reescrituras que conservan el término que distingue el problema:
        // el emparejador las reconoce sin intervención.
        foreach ([
            'reordenada y acortada' => 'Puertas deterioradas en los consultorios de vacunacion y enfermeria.',
            'con la puntuación cambiada' => 'Las puertas de los consultorios se encuentran deterioradas, en especial '
                .'las de vacunacion y enfermeria.',
        ] as $caso => $reescrito) {
            $this->assertGreaterThanOrEqual(
                Emparejador::UMBRAL_ALTO,
                $similitud->entre($original, $reescrito),
                "La reescritura «{$caso}» debería seguir emparejando sola"
            );
        }

        // Cuando el sinónimo se lleva el término que discrimina —«deterioradas»
        // por «en mal estado»— lo que queda en común es vocabulario genérico de
        // la misma sala. Ese es exactamente el perfil de un hallazgo DISTINTO
        // sobre el mismo lugar, así que la decisión pasa a una persona en vez
        // de cerrarse sola.
        $conSinonimo = 'Las puertas de los consultorios medicos estan en mal estado, sobre todo '
            .'las del area de vacunacion y enfermeria.';
        $puntaje = $similitud->entre($original, $conSinonimo);

        $this->assertGreaterThanOrEqual(Emparejador::UMBRAL_BAJO, $puntaje);
        $this->assertLessThan(Emparejador::UMBRAL_ALTO, $puntaje);
    }

    #[Test]
    public function dos_problemas_distintos_del_mismo_sitio_no_se_emparejan(): void
    {
        $similitud = new Similitud(new IndiceIdf($this->todosLosTextos()));

        $puertas = 'Las puertas de los consultorios se encuentran deterioradas especialmente '
            .'las de vacunacion y Enfermeria.';
        $ventanas = 'Las ventanas de los consultorios de vacunacion no tienen seguro y '
            .'permiten el ingreso de vectores.';

        // Comparten sala y servicio pero no el problema: no debe quedar ni en
        // la banda de revisión.
        $this->assertLessThan(Emparejador::UMBRAL_BAJO, $similitud->entre($puertas, $ventanas));
    }

    #[Test]
    public function un_empate_tecnico_no_se_resuelve_solo(): void
    {
        $emparejador = $this->emparejador;

        $entrante = 'La nevera de odontología se encuentra con la base oxidada.';
        $candidatos = [
            1 => 'La nevera de odontología se encuentra con la base oxidada.',
            2 => 'La nevera de odontología se encuentra con la base oxidada.',
        ];

        $resultado = $emparejador->mejor($entrante, $candidatos);

        // Elegir entre dos candidatos idénticos es una moneda al aire, y una
        // moneda al aire no puede cerrar un hallazgo.
        $this->assertFalse($resultado->esFirme());
        $this->assertTrue($resultado->esDudosa());
    }

    /** @return list<string> */
    private function todosLosTextos(): array
    {
        $textos = [];

        foreach ($this->porSede as $hallazgos) {
            foreach ($hallazgos as $hallazgo) {
                $textos[] = $hallazgo['texto'];
            }
        }

        return $textos;
    }
}
