<?php

declare(strict_types=1);

namespace App\Services\Consolidado;

use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Corte;
use App\Models\HallazgoEstadoCorte;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\AxisText;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\ChartColor;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\GridLines;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Properties;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el consolidado en Excel.
 *
 * Réplica de las cuatro hojas que la E.S.E. ya recibe, para que quien lo abra
 * no note el cambio de herramienta, más dos que hoy no existen: el detalle de
 * cada hallazgo —sin el cual el consolidado no se puede trabajar— y la serie
 * mensual, que hoy exige abrir doce archivos.
 */
final class ExportadorConsolidado
{
    private const AZUL = '1F3864';
    private const GRIS = 'D9D9D9';
    private const TINTA = '404040';
    private const TINTA_SUAVE = '595959';

    /**
     * Mismos colores de estado que la app (frontend/src/domain/enums.ts): quien
     * pasa del tablero al Excel lee el estado por el color sin pensarlo.
     * En el orden de las columnas: cerrados, con evidencia, abiertos, sin dato.
     */
    private const COLORES_ESTADO = ['2A6A4E', 'C08A1E', 'A32E22', '9AA19B'];

    /** Un color por estándar, distinguibles entre sí y legibles con texto blanco. */
    private const COLORES_ESTANDAR = [
        'E0' => '7F7F7F',
        'E1' => '5B7DB1',
        'E2' => '2E3D8F',
        'E3' => '2A8C82',
        'E4' => 'C08A1E',
        'E5' => 'A32E22',
        'E6' => '8E5B9E',
        'E7' => '6B8E23',
    ];

    public function __construct(private readonly ServicioConsolidado $servicio = new ServicioConsolidado()) {}

    /** @param list<int> $sedeIds */
    public function exportar(string $periodo, string $rutaDestino, array $sedeIds = []): string
    {
        $consolidado = $this->servicio->generar($periodo, $sedeIds);

        $libro = new Spreadsheet();
        $libro->removeSheetByIndex(0);
        $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $this->hojaGenerales($libro->createSheet(), $consolidado);
        $this->hojaPorSede($libro->createSheet(), $consolidado);
        $this->hojaPorEstandar($libro->createSheet(), $consolidado);
        $this->hojaSedePorEstandar($libro->createSheet(), $consolidado);
        $this->hojaDetalle($libro->createSheet(), $periodo, $sedeIds);
        $this->hojaSerie($libro->createSheet(), $sedeIds);

        $libro->setActiveSheetIndex(0);

        $directorio = dirname($rutaDestino);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0o775, true);
        }

        // Sin esto, PhpSpreadsheet escribe el archivo como si las gráficas
        // no existieran: hay que pedirle explícitamente que las incluya.
        (new Xlsx($libro))->setIncludeCharts(true)->save($rutaDestino);
        $libro->disconnectWorksheets();

        return $rutaDestino;
    }

    private function hojaGenerales(Worksheet $hoja, Consolidado $c): void
    {
        $hoja->setTitle('Hallazgos generales');
        $this->encabezado($hoja, $c, 'B2');

        $titulos = ['HALLAZGOS', 'CERRADOS', 'ABIERTOS CON EVIDENCIA', 'ABIERTOS', 'SIN DATO', '% AVANCE TOTAL'];
        $valores = [
            $c->generales['hallazgos'], $c->generales['cerrados'],
            $c->generales['abiertos_con_evidencia'], $c->generales['abiertos'],
            $c->generales['sin_dato'], $c->generales['pct_avance'],
        ];

        $this->escribirFila($hoja, 4, 'B', $titulos);
        $this->escribirFila($hoja, 5, 'B', $valores);
        $this->estiloTitulos($hoja, 'B4:G4');
        $this->bordes($hoja, 'B4:G5');
        $hoja->getStyle('B5:G5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle('B5:G5')->getFont()->setBold(true)->setSize(14);
        $hoja->getStyle('B5:F5')->getNumberFormat()->setFormatCode('#,##0');
        $hoja->getStyle('G5')->getNumberFormat()->setFormatCode('0.00%');
        $hoja->getRowDimension(4)->setRowHeight(32);
        $hoja->getRowDimension(5)->setRowHeight(26);
        $this->anchos($hoja, ['A' => 2, 'B' => 16, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 16, 'G' => 16]);

        // Distribución de estados: la pregunta que responde esta hoja es
        // «cuánto de cada cosa hay». El pastel da la proporción de cada
        // estado; las cantidades están en la tabla justo encima.
        $valoresPastel = $this->valoresNumero($hoja->getTitle(), 'C5', 'F5', 4);
        $valoresPastel->setFillColor(self::COLORES_ESTADO);
        $valoresPastel->setLabelLayout($this->etiquetasPastel());

        $grafica = $this->grafica(
            DataSeries::TYPE_PIECHART,
            'Distribución de hallazgos por estado',
            [],
            [$this->valoresTexto($hoja->getTitle(), 'C4', 'F4', 4)],
            [$valoresPastel],
            leyenda: Legend::POSITION_RIGHT,
        );

        $this->ubicar($hoja, $grafica, 'B', 7, 600, 330);
    }

    private function hojaPorSede(Worksheet $hoja, Consolidado $c): void
    {
        $hoja->setTitle('resumen por sede');
        $this->encabezado($hoja, $c, 'B2');

        $this->escribirFila($hoja, 4, 'B', [
            'CENTRO DE SALUD', 'TOTAL HALLAZGOS', 'CERRADOS', 'ABIERTOS CON EVIDENCIA',
            'ABIERTOS', 'SIN DATO', 'SIN REPORTAR', '% AVANCE', 'MÁS ANTIGUO (MESES)',
        ]);

        $fila = 5;

        foreach ($c->porSede as $sede) {
            $this->escribirFila($hoja, $fila, 'B', [
                $sede['nombre'], $sede['hallazgos'], $sede['cerrados'],
                $sede['abiertos_con_evidencia'], $sede['abiertos'], $sede['sin_dato'],
                $sede['sin_reportar'], $sede['pct_avance'], $sede['mas_antiguo_meses'],
            ]);
            $fila++;
        }

        $ultimaFilaDatos = $fila - 1;

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL', $c->generales['hallazgos'], $c->generales['cerrados'],
            $c->generales['abiertos_con_evidencia'], $c->generales['abiertos'], $c->generales['sin_dato'],
            $c->generales['sin_reportar'], $c->generales['pct_avance'], '']);

        $this->estiloTitulos($hoja, 'B4:J4');
        $this->estiloTotal($hoja, "B{$fila}:J{$fila}");
        $hoja->getStyle('I5:I'.$fila)->getNumberFormat()->setFormatCode('0.00%');
        $hoja->getStyle("C5:J{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->bordes($hoja, 'B4:J'.$fila);
        $hoja->getRowDimension(4)->setRowHeight(32);
        $this->anchos($hoja, ['A' => 2, 'B' => 36, 'C' => 12, 'D' => 12, 'E' => 14, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 12, 'J' => 14]);
        $hoja->freezePane('C5');

        if ($ultimaFilaDatos < 5) {
            return;
        }

        $cantidad = $ultimaFilaDatos - 4;
        $nombres = $this->valoresTexto($hoja->getTitle(), 'B5', 'B'.$ultimaFilaDatos, $cantidad);
        $alto = $this->altoBarras($cantidad);

        // Una barra por sede con sus cuatro estados: el tamaño de la barra es
        // cuántos hallazgos tiene y los colores, cómo va su gestión.
        $estados = $this->graficaEstados($hoja->getTitle(), 'Hallazgos por sede según su estado', $nombres, ['D', 'E', 'F', 'G'], 4, $ultimaFilaDatos);
        $siguiente = $this->ubicar($hoja, $estados, 'B', $fila + 3, 760, $alto);

        $avance = $this->graficaPorcentaje(
            $hoja->getTitle(),
            '% de avance por sede (hallazgos cerrados / total)',
            $nombres,
            $this->valoresNumero($hoja->getTitle(), 'I5', 'I'.$ultimaFilaDatos, $cantidad),
            self::COLORES_ESTADO[0],
        );
        $this->ubicar($hoja, $avance, 'B', $siguiente, 760, $alto);
    }

    private function hojaPorEstandar(Worksheet $hoja, Consolidado $c): void
    {
        $hoja->setTitle('resumen por estandar');
        $this->encabezado($hoja, $c, 'B2');

        $this->escribirFila($hoja, 4, 'B', [
            'ESTÁNDAR', 'TOTAL HALLAZGOS', 'CERRADOS', 'ABIERTOS CON EVIDENCIA',
            'ABIERTOS', 'SIN DATO', '% DEL TOTAL',
        ]);

        $fila = 5;

        foreach ($c->porEstandar as $estandar) {
            $this->escribirFila($hoja, $fila, 'B', [
                mb_strtoupper($estandar['nombre'], 'UTF-8'), $estandar['hallazgos'], $estandar['cerrados'],
                $estandar['abiertos_con_evidencia'], $estandar['abiertos'], $estandar['sin_dato'],
                $estandar['pct_del_total'],
            ]);
            $fila++;
        }

        $ultimaFilaDatos = $fila - 1;

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL', $c->generales['hallazgos'], '', '', '', '', '']);

        $this->estiloTitulos($hoja, 'B4:H4');
        $this->estiloTotal($hoja, "B{$fila}:H{$fila}");
        $hoja->getStyle('H5:H'.$fila)->getNumberFormat()->setFormatCode('0.00%');
        $hoja->getStyle("C5:H{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("B5:B{$fila}")->getAlignment()->setWrapText(true);
        $this->bordes($hoja, 'B4:H'.$fila);
        $hoja->getRowDimension(4)->setRowHeight(32);
        $this->anchos($hoja, ['A' => 2, 'B' => 46, 'C' => 12, 'D' => 12, 'E' => 14, 'F' => 12, 'G' => 12, 'H' => 12]);
        $hoja->freezePane('C5');

        if ($ultimaFilaDatos < 5) {
            return;
        }

        $cantidad = $ultimaFilaDatos - 4;
        $nombres = $this->valoresTexto($hoja->getTitle(), 'B5', 'B'.$ultimaFilaDatos, $cantidad);
        $alto = $this->altoBarras($cantidad);

        $estados = $this->graficaEstados($hoja->getTitle(), 'Hallazgos por estándar según su estado', $nombres, ['D', 'E', 'F', 'G'], 4, $ultimaFilaDatos);
        $siguiente = $this->ubicar($hoja, $estados, 'B', $fila + 3, 760, $alto);

        $participacion = $this->graficaPorcentaje(
            $hoja->getTitle(),
            'Participación de cada estándar en el total de hallazgos',
            $nombres,
            $this->valoresNumero($hoja->getTitle(), 'H5', 'H'.$ultimaFilaDatos, $cantidad),
            self::AZUL,
        );
        $this->ubicar($hoja, $participacion, 'B', $siguiente, 760, $alto);
    }

    private function hojaSedePorEstandar(Worksheet $hoja, Consolidado $c): void
    {
        $hoja->setTitle('resumen por sede y estandar');
        $this->encabezado($hoja, $c, 'B2');

        $codigos = CatalogoEstandares::codigos();
        $titulos = ['CENTRO DE SALUD'];

        foreach ($codigos as $codigo) {
            $titulos[] = mb_strtoupper(CatalogoEstandares::nombre($codigo), 'UTF-8');
        }

        $titulos[] = 'TOTAL SEDE';
        $titulos[] = 'ESTÁNDAR CON MÁS %';

        $this->escribirFila($hoja, 4, 'B', $titulos);

        $fila = 5;
        $totales = array_fill_keys($codigos, 0);

        foreach ($c->sedePorEstandar as $sede) {
            $valores = [$sede['nombre']];

            foreach ($codigos as $codigo) {
                $valores[] = $sede['estandares'][$codigo];
                $totales[$codigo] += $sede['estandares'][$codigo];
            }

            $valores[] = $sede['total'];
            $valores[] = $sede['pct_dominante'];

            $this->escribirFila($hoja, $fila, 'B', $valores);
            $fila++;
        }

        $ultimaFilaDatos = $fila - 1;

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL ESTÁNDAR', ...array_values($totales), $c->generales['hallazgos'], '']);

        $ultima = chr(ord('B') + count($titulos) - 1);
        $this->estiloTitulos($hoja, "B4:{$ultima}4");
        $this->estiloTotal($hoja, "B{$fila}:{$ultima}{$fila}");
        $hoja->getStyle("{$ultima}5:{$ultima}".($fila - 1))->getNumberFormat()->setFormatCode('0.00%');
        $hoja->getStyle("C5:{$ultima}{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->bordes($hoja, "B4:{$ultima}{$fila}");
        $hoja->getRowDimension(4)->setRowHeight(58);

        $anchos = ['A' => 2, 'B' => 36];
        for ($col = ord('C'); $col <= ord($ultima); $col++) {
            $anchos[chr($col)] = 15;
        }
        $this->anchos($hoja, $anchos);
        $hoja->freezePane('C5');

        if ($ultimaFilaDatos < 5) {
            return;
        }

        // Una barra por sede, apilada por estándar: lo que no se ve en la
        // matriz de números —dónde pesa cada estándar dentro de cada sede—
        // salta a la vista en la gráfica. Los estándares sin ningún hallazgo
        // se omiten: solo llenarían la leyenda de colores que no aparecen.
        $cantidad = $ultimaFilaDatos - 4;
        $etiquetas = [];
        $valoresSeries = [];

        foreach (array_keys($totales) as $indice => $codigo) {
            if ($totales[$codigo] === 0) {
                continue;
            }

            $columna = chr(ord('C') + $indice);
            $etiquetas[] = $this->valorTexto($hoja->getTitle(), "{$columna}4");
            $serie = $this->valoresNumero($hoja->getTitle(), "{$columna}5", "{$columna}{$ultimaFilaDatos}", $cantidad);
            $serie->setFillColor(self::COLORES_ESTANDAR[$codigo] ?? self::AZUL);
            $serie->setLabelLayout($this->etiquetasDentro());
            $valoresSeries[] = $serie;
        }

        if ($valoresSeries === []) {
            return;
        }

        $grafica = $this->grafica(
            DataSeries::TYPE_BARCHART,
            'Hallazgos por sede y estándar',
            $etiquetas,
            [$this->valoresTexto($hoja->getTitle(), 'B5', 'B'.$ultimaFilaDatos, $cantidad)],
            $valoresSeries,
            DataSeries::GROUPING_STACKED,
            horizontal: true,
            formatoEje: '#,##0',
        );

        $this->ubicar($hoja, $grafica, 'B', $fila + 3, 900, $this->altoBarras($cantidad) + 40);
    }

    /** Hoja nueva: sin el detalle, el consolidado no se puede trabajar. */
    private function hojaDetalle(Worksheet $hoja, string $periodo, array $sedeIds): void
    {
        $hoja->setTitle('detalle de hallazgos');

        $this->escribirFila($hoja, 1, 'A', [
            'SEDE', 'ESTÁNDAR', 'HALLAZGO', 'ESTADO', 'ACCIÓN PROPUESTA',
            'RESPONSABLE', 'EVIDENCIA', 'MESES ABIERTO', 'REPORTADO EN EL CORTE',
        ]);

        $corte = Corte::query()->where('periodo', $periodo)->firstOrFail();

        $delCorte = HallazgoEstadoCorte::query()
            ->with(['hallazgo.sede'])
            ->where('corte_id', $corte->id)
            ->when($sedeIds !== [], fn ($q) => $q->whereHas('hallazgo', fn ($h) => $h->whereIn('sede_id', $sedeIds)))
            ->get();

        // Los cerrados en meses anteriores también cuentan en los totales de
        // las otras hojas: sin ellos el detalle no cuadraría con el resumen.
        $cerradosAntes = $this->servicio->fotosCerradasAntes($corte, $sedeIds)
            ->select(['hallazgo_estado_corte.*', 'cortes.periodo as periodo_cierre'])
            ->with(['hallazgo.sede'])
            ->get();

        $fotos = $delCorte->concat($cerradosAntes)
            ->sortBy([
                fn ($f) => $f->hallazgo->sede->nombre,
                fn ($f) => CatalogoEstandares::orden($f->hallazgo->estandar_codigo),
            ]);

        $fila = 2;

        foreach ($fotos as $foto) {
            $this->escribirFila($hoja, $fila, 'A', [
                $foto->hallazgo->sede->nombre,
                CatalogoEstandares::nombre($foto->hallazgo->estandar_codigo),
                $foto->hallazgo->descripcion,
                $foto->estado->etiqueta(),
                (string) $foto->accion_propuesta,
                (string) $foto->responsable,
                (string) $foto->evidencia,
                isset($foto->periodo_cierre) ? 0 : $foto->meses_abierto,
                match (true) {
                    isset($foto->periodo_cierre) => "Cerrado en {$foto->periodo_cierre}",
                    $foto->presente_en_corte => 'Sí',
                    default => 'No',
                },
            ]);
            $fila++;
        }

        $ultimaFila = max(2, $fila - 1);

        $this->estiloTitulos($hoja, 'A1:I1');
        $hoja->getRowDimension(1)->setRowHeight(30);
        $hoja->getStyle("A2:I{$ultimaFila}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->getStyle("H2:I{$ultimaFila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->anchos($hoja, ['A' => 30, 'B' => 26, 'C' => 70, 'D' => 20, 'E' => 40, 'F' => 22, 'G' => 40, 'H' => 10, 'I' => 18]);
        $hoja->freezePane('A2');

        // Filtros en el encabezado: el detalle se usa sobre todo para sacar
        // «los abiertos de tal sede» sin copiar nada a otra hoja.
        $hoja->setAutoFilter("A1:I{$ultimaFila}");
    }

    /** Hoja nueva: la evolución que hoy exige abrir doce archivos. */
    private function hojaSerie(Worksheet $hoja, array $sedeIds): void
    {
        $hoja->setTitle('serie mensual');

        $this->escribirFila($hoja, 1, 'A', [
            'PERIODO', 'CERRADO', 'HALLAZGOS', 'CERRADOS',
            'ABIERTOS CON EVIDENCIA', 'ABIERTOS', 'SIN DATO', '% AVANCE',
        ]);

        $fila = 2;

        foreach ($this->servicio->serie($sedeIds) as $mes) {
            $this->escribirFila($hoja, $fila, 'A', [
                $mes['periodo'], $mes['cerrado'] ? 'Sí' : 'Abierto', $mes['hallazgos'],
                $mes['cerrados'], $mes['abiertos_con_evidencia'], $mes['abiertos'],
                $mes['sin_dato'], $mes['pct_avance'],
            ]);
            $fila++;
        }

        $ultimaFilaDatos = $fila - 1;

        $this->estiloTitulos($hoja, 'A1:H1');
        $hoja->getRowDimension(1)->setRowHeight(32);
        $hoja->getStyle('H2:H'.max(2, $ultimaFilaDatos))->getNumberFormat()->setFormatCode('0.00%');
        $hoja->getStyle('A2:H'.max(2, $ultimaFilaDatos))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->bordes($hoja, 'A1:H'.max(2, $ultimaFilaDatos));
        $this->anchos($hoja, ['A' => 12, 'B' => 11, 'C' => 12, 'D' => 12, 'E' => 14, 'F' => 12, 'G' => 12, 'H' => 12]);

        if ($ultimaFilaDatos < 2) {
            return;
        }

        $cantidad = $ultimaFilaDatos - 1;
        $periodos = $this->valoresTexto($hoja->getTitle(), 'A2', 'A'.$ultimaFilaDatos, $cantidad);
        $ancho = max(560, 120 + $cantidad * 70);

        // El avance acumulado: la línea que tiene que subir mes a mes.
        $avance = $this->valoresNumero($hoja->getTitle(), 'H2', 'H'.$ultimaFilaDatos, $cantidad);
        $avance->setFillColor(self::COLORES_ESTADO[0]);
        $avance->setLineWidth(28575); // 2,25 pt
        $avance->setPointMarker('circle');
        $avance->getMarkerFillColor()->setColorProperties(self::COLORES_ESTADO[0]);
        $avance->getMarkerBorderColor()->setColorProperties(self::COLORES_ESTADO[0]);
        $avance->setLabelLayout($this->etiquetasFuera('0.0%', 't'));

        $linea = $this->grafica(
            DataSeries::TYPE_LINECHART,
            'Avance acumulado mes a mes (% de hallazgos cerrados)',
            [$this->valorTexto($hoja->getTitle(), 'H1')],
            [$periodos],
            [$avance],
            leyenda: null,
            formatoEje: '0%',
        );
        $linea->getChartAxisY()->setAxisOptionsProperties(Properties::AXIS_LABELS_NEXT_TO, minimum: 0, maximum: 1);

        $siguiente = $this->ubicar($hoja, $linea, 'A', $ultimaFilaDatos + 3, $ancho, 300);

        // Cómo cambia la composición de estados entre meses.
        $composicion = $this->graficaEstados($hoja->getTitle(), 'Hallazgos por estado en cada mes', $periodos, ['D', 'E', 'F', 'G'], 1, $ultimaFilaDatos, horizontal: false);
        $this->ubicar($hoja, $composicion, 'A', $siguiente, $ancho, 320);
    }

    private function encabezado(Worksheet $hoja, Consolidado $c, string $celda): void
    {
        $hoja->setCellValue($celda, sprintf(
            'Consolidado de hallazgos SUH · corte %s%s',
            $c->periodo,
            $c->corteCerrado ? '' : '  (CORTE ABIERTO: las cifras aún pueden cambiar)'
        ));
        $hoja->getStyle($celda)->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::AZUL);
        $hoja->getRowDimension((int) preg_replace('/\D+/', '', $celda))->setRowHeight(22);
    }

    private function escribirFila(Worksheet $hoja, int $fila, string $desde, array $valores): void
    {
        $columna = ord($desde);

        foreach ($valores as $valor) {
            $hoja->setCellValue(chr($columna).$fila, $valor);
            $columna++;
        }
    }

    private function estiloTitulos(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL);
        $hoja->getStyle($rango)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
    }

    private function estiloTotal(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
    }

    private function bordes(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('BFBFBF');
    }

    /**
     * Anchos fijos en vez de autoajuste: el autoajuste depende del programa
     * que abra el archivo, y las gráficas se ubican midiendo estas columnas.
     *
     * @param  array<string, float>  $anchos
     */
    private function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    // ── Gráficas ─────────────────────────────────────────────────────────
    //
    // PhpSpreadsheet escribe gráficas nativas de Excel —se editan y se
    // recalculan en Excel como cualquier otra, no son una imagen pegada—
    // pero solo si el escritor recibe setIncludeCharts(true) al guardar.

    /**
     * Barras apiladas con los cuatro estados, en los colores de la app.
     *
     * @param  list<string>  $columnas  cerrados, con evidencia, abiertos y sin dato, en ese orden
     */
    private function graficaEstados(
        string $hoja,
        string $titulo,
        DataSeriesValues $categorias,
        array $columnas,
        int $filaTitulos,
        int $ultimaFila,
        bool $horizontal = true,
    ): Chart {
        $primera = $filaTitulos + 1;
        $cantidad = $ultimaFila - $filaTitulos;
        $etiquetas = [];
        $series = [];

        foreach ($columnas as $i => $columna) {
            $etiquetas[] = $this->valorTexto($hoja, "{$columna}{$filaTitulos}");
            $serie = $this->valoresNumero($hoja, "{$columna}{$primera}", "{$columna}{$ultimaFila}", $cantidad);
            $serie->setFillColor(self::COLORES_ESTADO[$i]);
            $serie->setLabelLayout($this->etiquetasDentro());
            $series[] = $serie;
        }

        return $this->grafica(
            DataSeries::TYPE_BARCHART,
            $titulo,
            $etiquetas,
            [$categorias],
            $series,
            DataSeries::GROUPING_STACKED,
            horizontal: $horizontal,
            formatoEje: '#,##0',
        );
    }

    /** Una sola barra por categoría con su porcentaje escrito al final. */
    private function graficaPorcentaje(
        string $hoja,
        string $titulo,
        DataSeriesValues $categorias,
        DataSeriesValues $valores,
        string $color,
    ): Chart {
        $valores->setFillColor($color);
        $valores->setLabelLayout($this->etiquetasFuera('0.0%', 'outEnd'));

        $grafica = $this->grafica(
            DataSeries::TYPE_BARCHART,
            $titulo,
            [],
            [$categorias],
            [$valores],
            horizontal: true,
            leyenda: null,
            formatoEje: '0%',
        );
        // Un poco de aire a la derecha para que la etiqueta de la barra más
        // larga no quede cortada contra el borde.
        $grafica->getChartAxisY()->setAxisOptionsProperties(Properties::AXIS_LABELS_NEXT_TO, minimum: 0);

        return $grafica;
    }

    /**
     * @param  list<DataSeriesValues>  $etiquetas  una por serie (puede ir vacía: una sola serie sin nombre, como la dona)
     * @param  list<DataSeriesValues>  $categorias  el eje de categorías, compartido por todas las series
     * @param  list<DataSeriesValues>  $valores  una por serie
     */
    private function grafica(
        string $tipo,
        string $titulo,
        array $etiquetas,
        array $categorias,
        array $valores,
        string $agrupacion = DataSeries::GROUPING_STANDARD,
        bool $horizontal = false,
        ?string $leyenda = Legend::POSITION_BOTTOM,
        string $formatoEje = 'General',
    ): Chart {
        $serie = new DataSeries($tipo, $agrupacion, range(0, count($valores) - 1), $etiquetas, $categorias, $valores);

        if ($tipo === DataSeries::TYPE_BARCHART) {
            $serie->setPlotDirection($horizontal ? DataSeries::DIRECTION_BAR : DataSeries::DIRECTION_COL);
        }

        $area = new PlotArea(null, [$serie]);
        $area->setNoFill(true);

        $tituloGrafica = new Title($titulo);
        $tituloGrafica->setFont($this->fuente(14, self::TINTA, negrita: true));

        $grafica = new Chart(
            uniqid('grafica_'),
            $tituloGrafica,
            $leyenda === null ? null : new Legend($leyenda, null, false),
            $area,
            displayBlanksAs: DataSeries::EMPTY_AS_GAP,
        );
        $grafica->setRoundedCorners(false);

        if ($tipo === DataSeries::TYPE_PIECHART) {
            return $grafica;
        }

        $categoriasEje = new Axis();
        $categoriasEje->setAxisText($this->textoEje(10));

        $valoresEje = new Axis();
        $valoresEje->setAxisText($this->textoEje(9));
        $valoresEje->setAxisNumberProperties($formatoEje, true);
        $cuadricula = new GridLines();
        $cuadricula->setLineColorProperties('E3E3E3');
        $valoresEje->setMajorGridlines($cuadricula);

        if ($horizontal) {
            // En barras horizontales Excel dibuja la primera categoría abajo;
            // se invierte para que el orden sea el de la tabla (la mayor
            // arriba) y el eje de valores se lleva al pie de la gráfica.
            $categoriasEje->setAxisOptionsProperties(Properties::AXIS_LABELS_NEXT_TO, axisOrientation: Properties::ORIENTATION_REVERSED);
            $valoresEje->setAxisOptionsProperties(Properties::AXIS_LABELS_NEXT_TO, horizontalCrosses: Properties::HORIZONTAL_CROSSES_MAXIMUM);
        }

        $grafica->setChartAxisX($categoriasEje);
        $grafica->setChartAxisY($valoresEje);

        return $grafica;
    }

    /** Cantidades en blanco dentro de cada tramo; los ceros no se escriben. */
    private function etiquetasDentro(): Layout
    {
        return $this->etiquetas('#,##0;;;', 'ctr', $this->fuente(9, 'FFFFFF', negrita: true));
    }

    private function etiquetasFuera(string $formato, string $posicion): Layout
    {
        return $this->etiquetas($formato, $posicion, $this->fuente(10, self::TINTA, negrita: true));
    }

    /**
     * Porcentaje de cada estado fuera del pastel, con línea guía: así se
     * leen también los tramos pequeños. Solo el porcentaje, porque Excel
     * separa valor y porcentaje con una coma y «54, 17%» se confunde con un
     * decimal.
     */
    private function etiquetasPastel(): Layout
    {
        $layout = $this->etiquetas('0.0%', 'outEnd', $this->fuente(11, self::TINTA, negrita: true));
        $layout->setShowVal(false);
        $layout->setShowPercent(true);
        $layout->setShowLeaderLines(true);

        return $layout;
    }

    private function etiquetas(string $formato, string $posicion, Font $fuente): Layout
    {
        $layout = new Layout();
        $layout->setShowVal(true);
        $layout->setShowCatName(false);
        $layout->setShowSerName(false);
        $layout->setShowPercent(false);
        $layout->setShowLegendKey(false);
        $layout->setShowLeaderLines(false);
        $layout->setLabelFont($fuente);

        if ($formato !== '') {
            $layout->setNumFmtCode($formato);
        }

        if ($posicion !== '') {
            $layout->setDLblPos($posicion);
        }

        return $layout;
    }

    private function textoEje(int $tamano): AxisText
    {
        $texto = new AxisText();
        $texto->setFont($this->fuente($tamano, self::TINTA_SUAVE));

        return $texto;
    }

    private function fuente(int $tamano, string $color, bool $negrita = false): Font
    {
        $fuente = new Font();
        $fuente->setSize($tamano);
        $fuente->setBold($negrita);
        $fuente->setChartColorFromObject(new ChartColor($color));

        return $fuente;
    }

    /** Alto de una gráfica de barras horizontales: crece con las categorías para que ninguna se pierda. */
    private function altoBarras(int $categorias): float
    {
        return max(260, 90 + $categorias * 30);
    }

    /**
     * Ancla la gráfica en ($columna, $fila) con el tamaño pedido en puntos,
     * midiendo los anchos y altos reales de la hoja.
     *
     * @return int la primera fila libre debajo de la gráfica, con un renglón de separación
     */
    private function ubicar(Worksheet $hoja, Chart $grafica, string $columna, int $fila, float $anchoPt, float $altoPt): int
    {
        // Excel mide en píxeles: un carácter de ancho son 7 px más 5 de margen,
        // y un punto son 4/3 de píxel.
        $restantePx = $anchoPt * 4 / 3;
        $indice = Coordinate::columnIndexFromString($columna);

        while (true) {
            $ancho = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->getWidth();
            $columnaPx = (int) round(($ancho < 0 ? 8.43 : $ancho) * 7 + 5);

            if ($restantePx <= $columnaPx) {
                break;
            }

            $restantePx -= $columnaPx;
            $indice++;
        }

        $restanteAlto = $altoPt;
        $ultimaFila = $fila;

        while (true) {
            $alto = $hoja->getRowDimension($ultimaFila)->getRowHeight();
            $filaPt = $alto < 0 ? 15.0 : $alto;

            if ($restanteAlto <= $filaPt) {
                break;
            }

            $restanteAlto -= $filaPt;
            $ultimaFila++;
        }

        $grafica->setTopLeftPosition($columna.$fila);
        $grafica->setBottomRightPosition(
            Coordinate::stringFromColumnIndex($indice).$ultimaFila,
            (int) round($restantePx),
            (int) round($restanteAlto * 4 / 3),
        );
        $hoja->addChart($grafica);

        return $ultimaFila + 2;
    }

    private function valorTexto(string $hoja, string $celda): DataSeriesValues
    {
        return new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$hoja}'!{$this->absoluta($celda)}", null, 1);
    }

    private function valoresTexto(string $hoja, string $desde, string $hasta, int $cantidad): DataSeriesValues
    {
        return new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$hoja}'!{$this->absoluta($desde)}:{$this->absoluta($hasta)}",
            null,
            max(1, $cantidad),
        );
    }

    private function valoresNumero(string $hoja, string $desde, string $hasta, int $cantidad): DataSeriesValues
    {
        return new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$hoja}'!{$this->absoluta($desde)}:{$this->absoluta($hasta)}",
            null,
            max(1, $cantidad),
        );
    }

    /** «B5» → «$B$5», la referencia absoluta que pide una serie de gráfica. */
    private function absoluta(string $celda): string
    {
        $columna = preg_replace('/\d+/', '', $celda);
        $fila = preg_replace('/\D+/', '', $celda);

        return "\${$columna}\${$fila}";
    }
}
