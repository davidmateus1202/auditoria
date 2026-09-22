<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Lectores;

use App\Domain\Enums\MarcaCriterio;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\CatalogoServicios;
use App\Domain\Extraccion\ClasificadorHallazgo;
use App\Domain\Extraccion\Dto\CriterioExtraido;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Domain\Extraccion\Dto\ResultadoExtraccion;
use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Domain\Extraccion\SegmentadorHallazgos;
use App\Domain\Extraccion\TextoNormalizador;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee la autoevaluación de una sede: SUH <sede>.xlsx
 *
 * Dos bloques distintos dentro del mismo libro:
 *   - una hoja por servicio habilitado, con los criterios marcados C/NC/NA;
 *     de ahí salen los porcentajes de cumplimiento
 *   - la hoja INFORME, con los pares ESTÁNDAR → HALLAZGO en prosa;
 *     de ahí salen los hallazgos
 *
 * A diferencia de la matriz de seguimiento, aquí el bloque de criterios NO
 * empieza siempre en la misma fila: cae en R10, R11 o R12 según cuánto ocupe la
 * descripción del servicio que va encima. Por eso todo se lee relativo a la
 * fila de títulos, nunca a un número fijo.
 */
final class LectorAutoevaluacion
{
    private const HOJA_INFORME = 'INFORME';

    /** Hojas pesadas o auxiliares que no aportan datos. */
    private const HOJAS_IGNORADAS = [
        'COPYRIGTH', 'COPYRIGHT', 'INDICE', 'RESULTADOS',
        'REGISTRO FOTOGRAFICO', 'FOTOS', 'PRUEBA', 'PRUEBA 1',
    ];

    private const TITULOS_CRITERIOS = ['ESTANDAR', 'C', 'NC', 'NA'];

    /** Cierran el bloque de hallazgos de la hoja INFORME. */
    private const FIN_DE_HALLAZGOS = ['FORTALEZAS', 'OPORTUNIDADES DE MEJORA', 'CONCLUSIONES'];

    public function leer(string $ruta): ResultadoExtraccion
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);

        // El archivo real pesa 9 MB casi todo en imágenes; cargar solo las hojas
        // con datos evita traerse el registro fotográfico a memoria.
        $reader->setLoadSheetsOnly($this->hojasUtiles($reader->listWorksheetNames($ruta)));
        $libro = $reader->load($ruta);

        $informe = $this->buscarInforme($libro->getAllSheets());

        if ($informe === null) {
            throw EstructuraInvalida::hojaAusente(self::HOJA_INFORME);
        }

        $meta = $this->leerMetadatos($informe);

        $resultado = new ResultadoExtraccion(
            formato: TipoFormato::Autoevaluacion,
            sede: $meta['sede'],
            auditor: $meta['auditor'],
            responsable: $meta['responsable'],
            fechaAuditoria: $meta['fecha'],
            normativaDeclarada: $this->normativaDeclarada($libro->getAllSheets()),
        );

        // La etiqueta normativa del archivo no se usa para nada operativo: la
        // plantilla todavía dice "RES 2003 DE 2014" en varias hojas mientras la
        // hoja INFORME ya cita la 3100 de 2019. Se guarda solo para avisar.
        if ($resultado->normativaDeclarada !== null
            && ! str_contains($resultado->normativaDeclarada, '3100')) {
            $resultado->advertir(sprintf(
                'La plantilla declara «%s». El marco vigente es la %s; se aplicó el catálogo vigente.',
                $resultado->normativaDeclarada,
                CatalogoEstandares::NORMATIVA
            ));
        }

        $this->leerHallazgos($informe, $resultado);

        foreach ($libro->getAllSheets() as $hoja) {
            if ($this->esInforme($hoja)) {
                continue;
            }

            $this->leerCriterios($hoja, $resultado);
        }

        $libro->disconnectWorksheets();

        return $resultado;
    }

    /** @param list<string> $nombres @return list<string> */
    private function hojasUtiles(array $nombres): array
    {
        return array_values(array_filter(
            $nombres,
            static fn (string $n): bool => ! in_array(TextoNormalizador::canonica($n), self::HOJAS_IGNORADAS, true)
        ));
    }

    /** @param list<Worksheet> $hojas */
    private function buscarInforme(array $hojas): ?Worksheet
    {
        foreach ($hojas as $hoja) {
            if ($this->esInforme($hoja)) {
                return $hoja;
            }
        }

        return null;
    }

    private function esInforme(Worksheet $hoja): bool
    {
        return TextoNormalizador::canonica($hoja->getTitle()) === self::HOJA_INFORME;
    }

    /**
     * Cabecera de la hoja INFORME: Centro de Salud, Fecha, Responsable, Auditor.
     *
     * @return array{sede:?string,fecha:?string,responsable:?string,auditor:?string}
     */
    private function leerMetadatos(Worksheet $informe): array
    {
        $etiquetas = [
            'CENTRO DE SALUD' => 'sede',
            'FECHA' => 'fecha',
            'RESPONSABLE' => 'responsable',
            'AUDITOR' => 'auditor',
            'AUDITOR ES' => 'auditor',
        ];

        $meta = ['sede' => null, 'fecha' => null, 'responsable' => null, 'auditor' => null];
        $limite = min(12, $informe->getHighestDataRow());

        for ($fila = 1; $fila <= $limite; $fila++) {
            $clave = TextoNormalizador::canonica($this->celda($informe, 'A', $fila));
            $campo = $etiquetas[$clave] ?? null;

            if ($campo === null || $meta[$campo] !== null) {
                continue;
            }

            $valor = TextoNormalizador::visible($this->celda($informe, 'B', $fila));

            if ($valor !== '') {
                $meta[$campo] = $valor;
            }
        }

        return $meta;
    }

    /** @param list<Worksheet> $hojas */
    private function normativaDeclarada(array $hojas): ?string
    {
        foreach ($hojas as $hoja) {
            $limite = min(15, $hoja->getHighestDataRow());

            for ($fila = 1; $fila <= $limite; $fila++) {
                foreach (['A', 'B'] as $columna) {
                    $texto = $this->celda($hoja, $columna, $fila);

                    if (preg_match('/RES\w*\.?\s*(?:N[°º]?\s*)?(\d{4})\s*(?:DE|\/)\s*(\d{4})/iu', $texto, $m) === 1) {
                        return "Resolución {$m[1]} de {$m[2]}";
                    }
                }
            }
        }

        return null;
    }

    /**
     * Hoja INFORME: pares ESTÁNDAR → HALLAZGO. La columna de estándar viene
     * combinada, así que se arrastra el último valor visto.
     */
    private function leerHallazgos(Worksheet $informe, ResultadoExtraccion $resultado): void
    {
        $nombreHoja = trim($informe->getTitle());
        $inicio = $this->localizarTitulosHallazgos($informe, $nombreHoja);
        $ultima = $informe->getHighestDataRow();
        $estandar = null;

        for ($fila = $inicio; $fila <= $ultima; $fila++) {
            $textoEstandar = TextoNormalizador::visible($this->celda($informe, 'A', $fila));

            if (in_array(TextoNormalizador::canonica($textoEstandar), self::FIN_DE_HALLAZGOS, true)) {
                break;
            }

            if ($textoEstandar !== '') {
                $codigo = CatalogoEstandares::resolver($textoEstandar);

                if ($codigo === null) {
                    if (CatalogoEstandares::estaFueraDeAlcance($textoEstandar)) {
                        $estandar = null;

                        continue;
                    }

                    throw EstructuraInvalida::estandarDesconocido($nombreHoja, $fila, $textoEstandar);
                }

                $estandar = $codigo;
            }

            $celdaHallazgo = TextoNormalizador::visible($this->celda($informe, 'B', $fila));

            if ($celdaHallazgo === '' || $estandar === null) {
                continue;
            }

            $resultado->filasLeidas++;

            // Una celda puede traer varios problemas separados por saltos de
            // línea o viñetas; si no se parten, la sede subreporta.
            foreach (SegmentadorHallazgos::segmentar($celdaHallazgo) as $fragmento) {
                $resultado->agregarHallazgo(new HallazgoExtraido(
                    codigoEstandar: $estandar,
                    descripcion: $fragmento,
                    clasificacion: ClasificadorHallazgo::clasificar($fragmento),
                    hoja: $nombreHoja,
                    fila: $fila,
                ));
            }
        }
    }

    private function localizarTitulosHallazgos(Worksheet $informe, string $nombreHoja): int
    {
        $limite = min(25, $informe->getHighestDataRow());

        for ($fila = 1; $fila <= $limite; $fila++) {
            if (TextoNormalizador::canonica($this->celda($informe, 'A', $fila)) === 'ESTANDAR'
                && TextoNormalizador::canonica($this->celda($informe, 'B', $fila)) === 'HALLAZGO') {
                return $fila + 1;
            }
        }

        throw EstructuraInvalida::encabezadoNoEncontrado($nombreHoja, 'ESTÁNDAR | HALLAZGO');
    }

    /**
     * Hoja de servicio: criterios de habilitación marcados C/NC/NA.
     * Se ignora en silencio la hoja que no tenga el bloque, porque el libro
     * trae hojas auxiliares que no son servicios.
     */
    private function leerCriterios(Worksheet $hoja, ResultadoExtraccion $resultado): void
    {
        $nombreHoja = trim($hoja->getTitle());
        $filaTitulos = $this->localizarTitulosCriterios($hoja);

        if ($filaTitulos === null) {
            return;
        }

        $servicio = CatalogoServicios::resolver($nombreHoja, $this->tituloInterno($hoja, $filaTitulos));

        if (! $servicio['enCatalogo']) {
            $resultado->advertir(sprintf(
                'La hoja «%s» no corresponde a ningún servicio del catálogo. Se registró como «%s».',
                $nombreHoja,
                $servicio['nombre']
            ));
        }

        $nombreServicio = $servicio['nombre'];
        $ultima = $hoja->getHighestDataRow();
        $estandar = null;

        for ($fila = $filaTitulos + 1; $fila <= $ultima; $fila++) {
            $textoEstandar = TextoNormalizador::visible($this->celda($hoja, 'A', $fila));

            if ($textoEstandar !== '' && ! CatalogoEstandares::estaFueraDeAlcance($textoEstandar)) {
                $codigo = CatalogoEstandares::resolver($textoEstandar);

                // En la columna de estándar de algunas hojas cae la descripción
                // del servicio, que es un párrafo largo. No es un estándar
                // desconocido: es texto que no debería estar ahí.
                if ($codigo === null) {
                    if (mb_strlen($textoEstandar, 'UTF-8') > 80) {
                        $resultado->descartar($nombreHoja, $fila, 'texto descriptivo en la columna de estándar', $textoEstandar);

                        continue;
                    }

                    throw EstructuraInvalida::estandarDesconocido($nombreHoja, $fila, $textoEstandar);
                }

                $estandar = $codigo;
            }

            $criterio = TextoNormalizador::visible($this->celda($hoja, 'B', $fila));

            if ($criterio === '' || $estandar === null) {
                continue;
            }

            $resultado->filasLeidas++;

            $resultado->agregarCriterio(new CriterioExtraido(
                servicio: $nombreServicio,
                codigoEstandar: $estandar,
                criterio: $criterio,
                marca: $this->marca($hoja, $fila),
                observacion: TextoNormalizador::visible($this->celda($hoja, 'F', $fila)),
                hoja: $nombreHoja,
                fila: $fila,
            ));
        }
    }

    /**
     * Cualquier contenido no vacío en la columna cuenta como marca: en los
     * archivos es una "X" 209 veces, y tres celdas traen la letra del estándar.
     */
    private function marca(Worksheet $hoja, int $fila): MarcaCriterio
    {
        foreach (['C' => MarcaCriterio::Cumple, 'D' => MarcaCriterio::NoCumple, 'E' => MarcaCriterio::NoAplica] as $columna => $marca) {
            if (TextoNormalizador::canonica($this->celda($hoja, $columna, $fila)) !== '') {
                return $marca;
            }
        }

        return MarcaCriterio::SinMarcar;
    }

    private function localizarTitulosCriterios(Worksheet $hoja): ?int
    {
        $limite = min(30, $hoja->getHighestDataRow());

        for ($fila = 1; $fila <= $limite; $fila++) {
            $encontrados = [
                TextoNormalizador::canonica($this->celda($hoja, 'A', $fila)),
                TextoNormalizador::canonica($this->celda($hoja, 'C', $fila)),
                TextoNormalizador::canonica($this->celda($hoja, 'D', $fila)),
                TextoNormalizador::canonica($this->celda($hoja, 'E', $fila)),
            ];

            if ($encontrados === self::TITULOS_CRITERIOS) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Título del servicio dentro de la hoja, cuando existe.
     *
     * Solo algunas hojas lo traen. Encima del encabezado también hay un enlace
     * «Índice», la descripción del servicio y a veces un punto suelto, así que
     * se descartan esos candidatos en vez de tomar el primero que aparezca.
     */
    private function tituloInterno(Worksheet $hoja, int $filaTitulos): ?string
    {
        $ruido = ['INDICE', 'ENTIDAD', 'FECHA', 'RESPONSABLE', 'AUDITOR', 'ESTANDAR'];

        for ($fila = $filaTitulos - 1; $fila >= max(1, $filaTitulos - 8); $fila--) {
            $texto = TextoNormalizador::visible($this->celda($hoja, 'A', $fila));
            $clave = TextoNormalizador::canonica($texto);

            if ($clave === '' || in_array($clave, $ruido, true)) {
                continue;
            }

            // La descripción del servicio es un párrafo y arranca con ese rótulo.
            if (str_contains($texto, ':') || mb_strlen($texto, 'UTF-8') > 80) {
                continue;
            }

            if (mb_strlen($clave, 'UTF-8') >= 4) {
                return $texto;
            }
        }

        return null;
    }

    private function celda(Worksheet $hoja, string $columna, int $fila): string
    {
        $celda = $hoja->getCell($columna.$fila);
        // Algunas plantillas traen el nombre de la sede como fórmula que
        // referencia una hoja oculta (p. ej. ='Todos '!B4:F4); getValue()
        // devolvería la fórmula cruda, no el texto. Excel ya calculó y guardó
        // el resultado, así que se lee ese valor cacheado en vez de
        // reevaluar la fórmula.
        $valor = $celda->isFormula() ? $celda->getOldCalculatedValue() : $celda->getValue();

        return is_scalar($valor) ? (string) $valor : '';
    }
}
