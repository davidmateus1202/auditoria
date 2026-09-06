<?php

declare(strict_types=1);

namespace App\Services\Cortes;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera la matriz mensual en el formato que la E.S.E. ya usa.
 *
 * Reproduce la estructura verificada en el archivo original: membrete de
 * documento controlado en las filas 1 a 7, títulos en la 8, «I. CONDICIONES
 * TECNICO-CIENTIFICAS» en la 9, y el estándar y el responsable combinados en
 * vertical por grupo. Quien lo abra no debería notar que lo generó un sistema.
 *
 * Dos añadidos que el original no tiene y que hacen posible el regreso:
 *
 *   - columna H con un token REF por fila, para que al reimportar el vínculo
 *     sea exacto en vez de por parecido de texto;
 *   - hoja oculta _control con el corte, la fecha de exportación y una huella
 *     de los campos editables, que es lo que permite detectar que el mismo
 *     hallazgo se tocó en la app y en el Excel a la vez.
 */
final class ExportadorMatriz
{
    private const CODIGO_FORMATO = 'FR-130-48-V1';
    private const VIGENCIA = '08/04/2022';
    public const HOJA_CONTROL = '_control';

    /** Anchos tomados del archivo original, en caracteres. */
    private const ANCHOS = ['A' => 32, 'B' => 25, 'C' => 18, 'D' => 19, 'E' => 15, 'F' => 29, 'G' => 25, 'H' => 12];

    public function exportar(Corte $corte, string $rutaDestino): string
    {
        $libro = new Spreadsheet();
        $libro->removeSheetByIndex(0);

        $control = [];
        $indice = 0;

        foreach ($this->sedesConHallazgos($corte) as $sede) {
            $hoja = $libro->createSheet($indice++);
            $hoja->setTitle(mb_substr($sede->codigo, 0, 31));

            $fotos = $this->fotosDe($corte, $sede);
            $this->escribirHoja($hoja, $sede, $corte, $fotos);

            foreach ($fotos as $foto) {
                $control[] = [
                    'ref' => $foto->hallazgo->referencia(),
                    'hallazgo_id' => $foto->hallazgo->id,
                    'huella' => $foto->huellaEditables(),
                ];
            }
        }

        $this->escribirControl($libro, $corte, $control);
        $libro->setActiveSheetIndex(0);

        $this->asegurarDirectorio($rutaDestino);
        (new Xlsx($libro))->save($rutaDestino);
        $libro->disconnectWorksheets();

        return $rutaDestino;
    }

    /** @param  list<HallazgoEstadoCorte>  $fotos */
    private function escribirHoja(Worksheet $hoja, Sede $sede, Corte $corte, array $fotos): void
    {
        $this->membrete($hoja, $sede, $corte);
        $this->titulos($hoja);

        $hoja->setCellValue('A9', 'I. CONDICIONES TECNICO- CIENTIFICAS:');
        $hoja->mergeCells('A9:H9');
        $hoja->getStyle('A9')->getFont()->setBold(true);

        $fila = 10;
        $estandarActual = null;
        $inicioGrupo = $fila;
        $responsablesGrupo = [];

        foreach ($fotos as $foto) {
            $codigo = $foto->hallazgo->estandar_codigo;

            if ($estandarActual !== null && $codigo !== $estandarActual) {
                $this->combinarGrupo($hoja, $inicioGrupo, $fila - 1, $responsablesGrupo);
                $inicioGrupo = $fila;
                $responsablesGrupo = [];
            }

            $responsablesGrupo[] = (string) $foto->responsable;

            if ($codigo !== $estandarActual) {
                $hoja->setCellValue("C{$fila}", mb_strtoupper(CatalogoEstandares::nombre($codigo), 'UTF-8'));
                $estandarActual = $codigo;
            }

            $hoja->setCellValue("A{$fila}", $foto->hallazgo->descripcion);
            $hoja->mergeCells("A{$fila}:B{$fila}");
            $hoja->setCellValue("D{$fila}", (string) $foto->accion_propuesta);
            $hoja->setCellValue("E{$fila}", (string) $foto->responsable);
            $hoja->setCellValue("F{$fila}", $foto->estado->etiquetaExcel());
            $hoja->setCellValue("G{$fila}", (string) $foto->evidencia);
            $hoja->setCellValue("H{$fila}", $foto->hallazgo->referencia());

            $this->validarEstado($hoja, $fila);
            $fila++;
        }

        if ($estandarActual !== null) {
            $this->combinarGrupo($hoja, $inicioGrupo, $fila - 1, $responsablesGrupo);
        }

        $this->rematarSecciones($hoja, $fila);
        $this->estilos($hoja, $fila - 1);
    }

    private function membrete(Worksheet $hoja, Sede $sede, Corte $corte): void
    {
        $hoja->setCellValue('C1', 'EMPRESA SOCIAL DEL ESTADO DEL MUNICIPIO DE VILLAVICENCIO');
        $hoja->setCellValue('C3', 'GESTION DE LA CALIDAD');
        $hoja->setCellValue('C4', 'FORMATO SEGUIMIENTO AUDITORIA SISTEMA UNICO DE HABILITACION');
        $hoja->setCellValue('G1', self::CODIGO_FORMATO);
        $hoja->setCellValue('G2', 'Vigencia: '.self::VIGENCIA);
        $hoja->setCellValue('G3', 'Documento Controlado');
        $hoja->setCellValue('G4', 'Página 1 de 1');

        foreach (['C1:F2', 'C3:F3', 'C4:F4'] as $rango) {
            $hoja->mergeCells($rango);
        }

        $hoja->setCellValue(
            'A5',
            'SEGUIMIENTO AUDITORIA SISTEMA UNICO DE HABILITACION E.S.E. MUNICIPAL '
            .'SEGÚN RESOLUCION '.CatalogoEstandares::NORMATIVA
        );
        $hoja->mergeCells('A5:H5');

        $hoja->setCellValue('A6', 'FECHA DE LA AUDITORIA SUH:');
        $hoja->setCellValue('E6', 'AUDITOR:');
        $hoja->setCellValue('F6', 'SECRETARIA DEPARTAMENTAL DE SALUD DEL META');

        $hoja->setCellValue('A7', 'CENTRO DE SALUD');
        $hoja->setCellValue('B7', $sede->nombre);
        $hoja->setCellValue('E7', 'FECHA DE SEGUIMIENTO');
        $hoja->setCellValue('G7', $corte->periodo);

        $hoja->getStyle('A5')->getFont()->setBold(true);
        $hoja->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle('C1:C4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function titulos(Worksheet $hoja): void
    {
        $titulos = [
            'A8' => 'HALLAZGOS',
            'C8' => 'ESTÁNDAR',
            'D8' => 'ACCIONES PROPUESTAS',
            'E8' => 'RESPONSABLES',
            'F8' => 'SEGUIMIENTO A CUMPLIMIENTO',
            'G8' => 'EVIDENCIA DEL CUMPLIMIENTO',
            'H8' => 'REF · no modificar',
        ];

        foreach ($titulos as $celda => $texto) {
            $hoja->setCellValue($celda, $texto);
        }

        $hoja->mergeCells('A8:B8');
        $hoja->getStyle('A8:H8')->getFont()->setBold(true);
        $hoja->getStyle('A8:H8')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9D9D9');
        $hoja->getStyle('A8:H8')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
    }

    /**
     * La lista desplegable es la corrección más barata de todo el proyecto:
     * hoy el estado se escribe libre, y de ahí salen las variantes que el
     * importador tiene que interpretar.
     */
    private function validarEstado(Worksheet $hoja, int $fila): void
    {
        $validacion = $hoja->getCell("F{$fila}")->getDataValidation();
        $validacion->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(false)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Estado no válido')
            ->setError('Elija uno de los tres estados de la lista.')
            ->setFormula1('"'.implode(',', EstadoHallazgo::opcionesExcel()).'"');
    }

    /**
     * El estándar sí va combinado en vertical, como el original: por
     * construcción es el mismo en todo el grupo.
     *
     * El responsable NO se combina cuando varía dentro del grupo. Combinar
     * borra el contenido de las celdas de abajo, así que al releer el archivo
     * todas las filas heredarían el responsable de la primera — treinta filas
     * volvían con un responsable que no era el suyo. La fidelidad al dato pesa
     * más que la fidelidad estética al formato; donde el grupo comparte
     * responsable, se combina igual que siempre.
     *
     * @param  list<string>  $responsables
     */
    private function combinarGrupo(Worksheet $hoja, int $desde, int $hasta, array $responsables): void
    {
        if ($hasta <= $desde) {
            return;
        }

        $hoja->mergeCells("C{$desde}:C{$hasta}");
        $hoja->getStyle("C{$desde}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        if (count(array_unique($responsables)) === 1) {
            $hoja->mergeCells("E{$desde}:E{$hasta}");
            $hoja->getStyle("E{$desde}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        }
    }

    /** Las secciones II y III existen en el formato aunque no lleven hallazgos. */
    private function rematarSecciones(Worksheet $hoja, int $fila): void
    {
        $hoja->setCellValue("A{$fila}", 'II. CONDICIONES TECNICO ADMINISTRATIVAS Y CAPACIDAD DE SUFICIENCIA PATRIMONIAL Y FINANCIERA:');
        $hoja->mergeCells("A{$fila}:H{$fila}");
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
    }

    private function estilos(Worksheet $hoja, int $ultimaFila): void
    {
        foreach (self::ANCHOS as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        if ($ultimaFila >= 10) {
            $rango = "A10:H{$ultimaFila}";
            $hoja->getStyle($rango)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $hoja->getStyle("A8:H{$ultimaFila}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            // El REF se ve, pero en gris y pequeño: está ahí para el sistema.
            $hoja->getStyle("H10:H{$ultimaFila}")->getFont()->setSize(8)->getColor()->setRGB('999999');
        }

        $hoja->getStyle('A1:H8')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $hoja->freezePane('A9');
    }

    /** @param  list<array{ref:string,hallazgo_id:int,huella:string}>  $control */
    private function escribirControl(Spreadsheet $libro, Corte $corte, array $control): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle(self::HOJA_CONTROL);

        $hoja->setCellValue('A1', 'periodo');
        $hoja->setCellValue('B1', $corte->periodo);
        $hoja->setCellValue('A2', 'exportado_en');
        $hoja->setCellValue('B2', now()->toIso8601String());
        $hoja->setCellValue('A3', 'filas');
        $hoja->setCellValue('B3', count($control));

        $hoja->setCellValue('A5', 'ref');
        $hoja->setCellValue('B5', 'hallazgo_id');
        $hoja->setCellValue('C5', 'huella_editables');

        $fila = 6;

        foreach ($control as $registro) {
            $hoja->setCellValue("A{$fila}", $registro['ref']);
            $hoja->setCellValue("B{$fila}", $registro['hallazgo_id']);
            $hoja->setCellValue("C{$fila}", $registro['huella']);
            $fila++;
        }

        $hoja->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    }

    /** @return \Illuminate\Support\Collection<int, Sede> */
    private function sedesConHallazgos(Corte $corte)
    {
        $ids = HallazgoEstadoCorte::query()
            ->where('corte_id', $corte->id)
            ->join('hallazgos', 'hallazgos.id', '=', 'hallazgo_estado_corte.hallazgo_id')
            ->distinct()
            ->pluck('hallazgos.sede_id');

        return Sede::query()->whereIn('id', $ids)->orderBy('nombre')->get();
    }

    /** @return list<HallazgoEstadoCorte> */
    private function fotosDe(Corte $corte, Sede $sede): array
    {
        return HallazgoEstadoCorte::query()
            ->with('hallazgo')
            ->where('corte_id', $corte->id)
            ->whereHas('hallazgo', fn ($q) => $q->where('sede_id', $sede->id))
            ->get()
            ->sortBy([
                fn (HallazgoEstadoCorte $f) => CatalogoEstandares::orden($f->hallazgo->estandar_codigo),
                fn (HallazgoEstadoCorte $f) => $f->hallazgo->id,
            ])
            ->values()
            ->all();
    }

    private function asegurarDirectorio(string $ruta): void
    {
        $directorio = dirname($ruta);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0o775, true);
        }
    }
}
