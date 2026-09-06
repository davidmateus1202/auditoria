<?php

declare(strict_types=1);

namespace App\Services\Extraccion;

use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\Dto\ResultadoExtraccion;
use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Domain\Extraccion\Lectores\LectorAutoevaluacion;
use App\Domain\Extraccion\Lectores\LectorSeguimiento;
use App\Domain\Extraccion\TextoNormalizador;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ExcepcionLectura;

/**
 * Punto de entrada de la extracción: detecta el formato y delega en el lector.
 *
 * La detección mira el contenido, no el nombre del archivo: los dos formatos
 * son inconfundibles por sus hojas y sus encabezados, y el nombre depende de
 * quién lo haya guardado.
 */
final class ExtractorExcel
{
    public function __construct(
        private readonly LectorAutoevaluacion $autoevaluacion = new LectorAutoevaluacion(),
        private readonly LectorSeguimiento $seguimiento = new LectorSeguimiento(),
    ) {}

    public function extraer(string $ruta, ?TipoFormato $formatoEsperado = null): ResultadoExtraccion
    {
        if (! is_readable($ruta)) {
            throw EstructuraInvalida::formatoNoReconocido("No se pudo leer el archivo en «{$ruta}».");
        }

        $formato = $formatoEsperado ?? $this->detectar($ruta);

        return match ($formato) {
            TipoFormato::Autoevaluacion => $this->autoevaluacion->leer($ruta),
            TipoFormato::Seguimiento => $this->seguimiento->leer($ruta),
        };
    }

    /**
     * La autoevaluación trae una hoja INFORME y una RESULTADOS; la matriz de
     * seguimiento trae una hoja por sede y ninguna de las dos.
     */
    public function detectar(string $ruta): TipoFormato
    {
        try {
            $reader = IOFactory::createReaderForFile($ruta);
            $hojas = array_map(
                static fn (string $n): string => TextoNormalizador::canonica($n),
                $reader->listWorksheetNames($ruta)
            );
        } catch (ExcepcionLectura $e) {
            throw EstructuraInvalida::formatoNoReconocido(
                'El archivo no es un libro de Excel legible: '.$e->getMessage()
            );
        }

        if (in_array('INFORME', $hojas, true)) {
            return TipoFormato::Autoevaluacion;
        }

        if (in_array('GRAFICOS POR IPS', $hojas, true) || in_array('LISTA EQUIPOS', $hojas, true)) {
            return TipoFormato::Seguimiento;
        }

        // Sin señales de nombre, decide la estructura: si alguna hoja tiene los
        // títulos de la matriz, es un seguimiento.
        try {
            return $this->seguimiento->leer($ruta) instanceof ResultadoExtraccion
                ? TipoFormato::Seguimiento
                : TipoFormato::Autoevaluacion;
        } catch (EstructuraInvalida) {
            throw EstructuraInvalida::formatoNoReconocido(sprintf(
                'Ni hoja INFORME (autoevaluación) ni hojas de sede con encabezado de seguimiento. Hojas encontradas: %s.',
                implode(', ', array_slice($hojas, 0, 8))
            ));
        }
    }
}
