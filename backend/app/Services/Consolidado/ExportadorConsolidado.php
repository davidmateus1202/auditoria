<?php

declare(strict_types=1);

namespace App\Services\Consolidado;

use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Corte;
use App\Models\HallazgoEstadoCorte;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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

    public function __construct(private readonly ServicioConsolidado $servicio = new ServicioConsolidado()) {}

    /** @param list<int> $sedeIds */
    public function exportar(string $periodo, string $rutaDestino, array $sedeIds = []): string
    {
        $consolidado = $this->servicio->generar($periodo, $sedeIds);

        $libro = new Spreadsheet();
        $libro->removeSheetByIndex(0);

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

        (new Xlsx($libro))->save($rutaDestino);
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
        $hoja->getStyle('G5')->getNumberFormat()->setFormatCode('0.00%');
        $this->autoancho($hoja, 'B', 'G');
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

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL', $c->generales['hallazgos'], $c->generales['cerrados'],
            $c->generales['abiertos_con_evidencia'], $c->generales['abiertos'], $c->generales['sin_dato'],
            $c->generales['sin_reportar'], $c->generales['pct_avance'], '']);

        $this->estiloTitulos($hoja, 'B4:J4');
        $hoja->getStyle("B{$fila}:J{$fila}")->getFont()->setBold(true);
        $hoja->getStyle('I5:I'.$fila)->getNumberFormat()->setFormatCode('0.00%');
        $this->bordes($hoja, 'B4:J'.$fila);
        $this->autoancho($hoja, 'B', 'J');
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

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL', $c->generales['hallazgos'], '', '', '', '', '']);

        $this->estiloTitulos($hoja, 'B4:H4');
        $hoja->getStyle("B{$fila}:H{$fila}")->getFont()->setBold(true);
        $hoja->getStyle('H5:H'.$fila)->getNumberFormat()->setFormatCode('0.00%');
        $this->bordes($hoja, 'B4:H'.$fila);
        $this->autoancho($hoja, 'B', 'H');
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

        $this->escribirFila($hoja, $fila, 'B', ['TOTAL ESTÁNDAR', ...array_values($totales), $c->generales['hallazgos'], '']);

        $ultima = chr(ord('B') + count($titulos) - 1);
        $this->estiloTitulos($hoja, "B4:{$ultima}4");
        $hoja->getStyle("B{$fila}:{$ultima}{$fila}")->getFont()->setBold(true);
        $columnaPct = chr(ord('B') + count($titulos) - 1);
        $hoja->getStyle("{$columnaPct}5:{$columnaPct}".($fila - 1))->getNumberFormat()->setFormatCode('0.00%');
        $this->bordes($hoja, "B4:{$ultima}{$fila}");
        $this->autoancho($hoja, 'B', $ultima);
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

        $fotos = HallazgoEstadoCorte::query()
            ->with(['hallazgo.sede'])
            ->where('corte_id', $corte->id)
            ->when($sedeIds !== [], fn ($q) => $q->whereHas('hallazgo', fn ($h) => $h->whereIn('sede_id', $sedeIds)))
            ->get()
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
                $foto->meses_abierto,
                $foto->presente_en_corte ? 'Sí' : 'No',
            ]);
            $fila++;
        }

        $this->estiloTitulos($hoja, 'A1:I1');
        $hoja->getStyle('C2:C'.max(2, $fila - 1))->getAlignment()->setWrapText(true);
        $hoja->getColumnDimension('C')->setWidth(70);
        $hoja->getColumnDimension('E')->setWidth(40);
        $hoja->freezePane('A2');

        foreach (['A', 'B', 'D', 'F', 'G', 'H', 'I'] as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }
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

        $this->estiloTitulos($hoja, 'A1:H1');
        $hoja->getStyle('H2:H'.max(2, $fila - 1))->getNumberFormat()->setFormatCode('0.00%');
        $this->autoancho($hoja, 'A', 'H');
    }

    private function encabezado(Worksheet $hoja, Consolidado $c, string $celda): void
    {
        $hoja->setCellValue($celda, sprintf(
            'Consolidado de hallazgos SUH · corte %s%s',
            $c->periodo,
            $c->corteCerrado ? '' : '  (CORTE ABIERTO: las cifras aún pueden cambiar)'
        ));
        $hoja->getStyle($celda)->getFont()->setBold(true)->setSize(12);
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

    private function bordes(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function autoancho(Worksheet $hoja, string $desde, string $hasta): void
    {
        for ($c = ord($desde); $c <= ord($hasta); $c++) {
            $hoja->getColumnDimension(chr($c))->setAutoSize(true);
        }
    }
}
