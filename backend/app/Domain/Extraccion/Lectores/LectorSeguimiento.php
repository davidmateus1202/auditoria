<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Lectores;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\ClasificadorHallazgo;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Domain\Extraccion\Dto\ResultadoExtraccion;
use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Domain\Extraccion\MapeadorEstado;
use App\Domain\Extraccion\TextoNormalizador;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee la matriz mensual de seguimiento: una hoja por sede.
 *
 * Estructura verificada sobre las diez hojas del archivo real:
 *   - encabezado de documento controlado FR-130-48-V1 en las filas 1 a 7
 *   - títulos SIEMPRE en la fila 8
 *   - «I. CONDICIONES TECNICO-CIENTIFICAS» en la fila 9, y luego las secciones
 *     II y III, cuyas filas no cuentan como hallazgos
 *   - columna C (ESTÁNDAR) combinada en vertical por grupo → arrastre
 */
final class LectorSeguimiento
{
    public const COL_HALLAZGO = 'A';
    public const COL_ESTANDAR = 'C';
    public const COL_ACCION = 'D';
    public const COL_RESPONSABLE = 'E';
    public const COL_SEGUIMIENTO = 'F';
    public const COL_EVIDENCIA = 'G';
    public const COL_REFERENCIA = 'H';

    /** Hojas que no son sedes: gráficos, listados auxiliares y restos. */
    private const HOJAS_IGNORADAS = [
        'GRAFICOS POR IPS',
        'LISTA EQUIPOS',
        'SEGUIMIENTO 31 MAYO 2022',
        'HOJA1',
        // Hoja oculta que agrega la exportación con el corte y las huellas de
        // cada fila. Vuelve en el archivo y no es una sede.
        'CONTROL',
        // Vistas agregadas del consolidado histórico: no son una sede, son un
        // resumen de todas.
        'AMBULANCIAS',
        'ESE GENERAL',
    ];

    private const TITULOS_ESPERADOS = [
        self::COL_HALLAZGO => 'HALLAZGOS',
        self::COL_ESTANDAR => 'ESTANDAR',
        self::COL_ACCION => 'ACCIONES PROPUESTAS',
        self::COL_SEGUIMIENTO => 'SEGUIMIENTO A CUMPLIMIENTO',
    ];

    public function leer(string $ruta): ResultadoExtraccion
    {
        $resultado = new ResultadoExtraccion(TipoFormato::Seguimiento);

        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $libro = $reader->load($ruta);

        $hojasLeidas = 0;

        foreach ($libro->getAllSheets() as $hoja) {
            if ($this->debeIgnorarse($hoja)) {
                continue;
            }

            $this->leerHoja($hoja, $resultado);
            $hojasLeidas++;
        }

        $libro->disconnectWorksheets();

        if ($hojasLeidas === 0) {
            throw EstructuraInvalida::formatoNoReconocido(
                'No se encontró ninguna hoja de sede con la estructura de la matriz de seguimiento.'
            );
        }

        return $resultado;
    }

    private function debeIgnorarse(Worksheet $hoja): bool
    {
        $nombre = TextoNormalizador::canonica($hoja->getTitle());

        return $nombre === ''
            || in_array($nombre, self::HOJAS_IGNORADAS, true)
            || $hoja->getHighestDataRow() < 9;
    }

    private function leerHoja(Worksheet $hoja, ResultadoExtraccion $resultado): void
    {
        $nombreHoja = trim($hoja->getTitle());
        $filaTitulos = $this->localizarTitulos($hoja, $nombreHoja);
        $ultima = $hoja->getHighestDataRow();

        $estandar = null;
        $responsableGrupo = '';

        for ($fila = $filaTitulos + 1; $fila <= $ultima; $fila++) {
            $textoHallazgo = TextoNormalizador::visible($this->celda($hoja, self::COL_HALLAZGO, $fila));
            $textoEstandar = TextoNormalizador::visible($this->celda($hoja, self::COL_ESTANDAR, $fila));

            // Un encabezado de sección romana corta el arrastre del estándar.
            // Sin esta regla, las filas de «Condiciones técnico-administrativas»
            // heredan «Interdependencia» de la fila anterior y se cuentan como
            // hallazgos que no existen.
            if ($this->esEncabezadoDeSeccion($textoHallazgo)) {
                $estandar = null;

                continue;
            }

            if ($textoEstandar !== '') {
                if (CatalogoEstandares::estaFueraDeAlcance($textoEstandar)) {
                    $estandar = null;

                    continue;
                }

                $codigo = CatalogoEstandares::resolver($textoEstandar);

                if ($codigo === null) {
                    throw EstructuraInvalida::estandarDesconocido($nombreHoja, $fila, $textoEstandar);
                }

                $estandar = $codigo;
                $responsableGrupo = TextoNormalizador::visible($this->celda($hoja, self::COL_RESPONSABLE, $fila));
            }

            if ($textoHallazgo === '') {
                continue;
            }

            $resultado->filasLeidas++;

            if ($estandar === null) {
                $resultado->descartar($nombreHoja, $fila, 'fuera de la sección de hallazgos', $textoHallazgo);

                continue;
            }

            $this->registrar($hoja, $fila, $nombreHoja, $estandar, $textoHallazgo, $responsableGrupo, $resultado);
        }
    }

    private function registrar(
        Worksheet $hoja,
        int $fila,
        string $nombreHoja,
        string $estandar,
        string $textoHallazgo,
        string $responsableGrupo,
        ResultadoExtraccion $resultado,
    ): void {
        $textoEstado = $this->celda($hoja, self::COL_SEGUIMIENTO, $fila);

        if (! MapeadorEstado::esReconocible($textoEstado)) {
            $resultado->advertir(sprintf(
                'Estado no reconocido en «%s» fila %d: «%s». Se registró como sin dato.',
                $nombreHoja,
                $fila,
                TextoNormalizador::visible($textoEstado)
            ));
        }

        $responsableFila = TextoNormalizador::visible($this->celda($hoja, self::COL_RESPONSABLE, $fila));

        $resultado->agregarHallazgo(new HallazgoExtraido(
            codigoEstandar: $estandar,
            descripcion: $textoHallazgo,
            clasificacion: ClasificadorHallazgo::clasificar($textoHallazgo),
            hoja: $nombreHoja,
            fila: $fila,
            estado: MapeadorEstado::mapear($textoEstado),
            accionPropuesta: TextoNormalizador::visible($this->celda($hoja, self::COL_ACCION, $fila)) ?: null,
            responsable: ($responsableFila !== '' ? $responsableFila : $responsableGrupo) ?: null,
            evidencia: TextoNormalizador::visible($this->celda($hoja, self::COL_EVIDENCIA, $fila)) ?: null,
            referencia: TextoNormalizador::visible($this->celda($hoja, self::COL_REFERENCIA, $fila)) ?: null,
        ));
    }

    /**
     * El anclaje no es un número de fila sino la fila que trae los títulos.
     * En este formato siempre es la 8, pero buscarla cuesta lo mismo y no se
     * rompe si algún día alguien inserta una fila en el membrete.
     */
    private function localizarTitulos(Worksheet $hoja, string $nombreHoja): int
    {
        $limite = min(20, $hoja->getHighestDataRow());

        for ($fila = 1; $fila <= $limite; $fila++) {
            $coincide = true;

            foreach (self::TITULOS_ESPERADOS as $columna => $esperado) {
                if (TextoNormalizador::canonica($this->celda($hoja, $columna, $fila)) !== $esperado) {
                    $coincide = false;

                    break;
                }
            }

            if ($coincide) {
                return $fila;
            }
        }

        throw EstructuraInvalida::encabezadoNoEncontrado(
            $nombreHoja,
            implode(' | ', self::TITULOS_ESPERADOS)
        );
    }

    /**
     * «I. CONDICIONES TECNICO-CIENTIFICAS», «II. …», «III. …».
     *
     * Se exige la palabra CONDICIONES además del ordinal: un hallazgo podría
     * empezar con una letra suelta, y confundirlo con una sección haría perder
     * todo lo que viene después. La red de seguridad es que las filas de las
     * secciones II y III también traen un rótulo fuera de alcance en la columna
     * de estándar, así que se descartan por dos caminos distintos.
     */
    private function esEncabezadoDeSeccion(string $texto): bool
    {
        return preg_match('/^(?:I|II|III|IV|V) CONDICIONES\b/u', TextoNormalizador::canonica($texto)) === 1;
    }

    private function celda(Worksheet $hoja, string $columna, int $fila): string
    {
        $celda = $hoja->getCell($columna.$fila);
        // Ver LectorAutoevaluacion::celda(): algunas plantillas traen texto
        // como fórmula hacia una hoja oculta; se lee el valor que Excel ya
        // calculó y cacheó, no la fórmula cruda.
        $valor = $celda->isFormula() ? $celda->getOldCalculatedValue() : $celda->getValue();

        return is_scalar($valor) ? (string) $valor : '';
    }
}
