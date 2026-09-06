<?php

declare(strict_types=1);

namespace App\Services\Cortes;

use App\Domain\Enums\DestinoReconciliacion;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Domain\Extraccion\TextoNormalizador;
use App\Domain\Reconciliacion\Emparejador;
use App\Domain\Reconciliacion\IndiceIdf;
use App\Domain\Reconciliacion\Similitud;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Reconciliacion;
use App\Models\Sede;
use App\Services\Extraccion\ExtractorExcel;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Flujo B — el corte mensual vuelve desde Excel.
 *
 * Emparejar por texto un archivo que nosotros mismos generamos sería absurdo:
 * cada fila viaja con un token REF y el vínculo es exacto. El emparejamiento
 * por similitud queda como red de seguridad para cuando alguien borra la
 * columna, copia a un libro nuevo o reordena las filas.
 *
 * Y como el estado se puede cambiar por los dos lados, se comparan tres
 * versiones: la que se exportó, la que trae el Excel y la que hay hoy en base.
 * Si cambió un solo lado, se aplica. Si cambiaron los dos, es conflicto y lo
 * resuelve una persona — en un dato que sustenta un reporte a la Secretaría,
 * un cambio pisado en silencio es peor que una pregunta de más.
 */
final class ImportadorMatriz
{
    public function __construct(private readonly ExtractorExcel $extractor = new ExtractorExcel()) {}

    /**
     * @return array{
     *     corte:string, filas:int, aplicados:int, sin_cambio:int,
     *     conflictos:int, revision:int, nuevos:int, ausentes:int,
     *     periodo_del_archivo:?string, advertencias:list<string>
     * }
     */
    public function importar(string $ruta, string $periodo): array
    {
        $corte = Corte::query()->where('periodo', $periodo)->first()
            ?? throw new RuntimeException("No existe el corte {$periodo}.");

        if (! $corte->admiteEscritura()) {
            throw new RuntimeException("El corte {$periodo} está cerrado. Reabrirlo es decisión del administrador.");
        }

        $huellasExportadas = $this->leerControl($ruta);
        $resultado = $this->extractor->extraer($ruta, TipoFormato::Seguimiento);

        return DB::transaction(function () use ($corte, $resultado, $huellasExportadas): array {
            $conteo = [
                'aplicados' => 0, 'sin_cambio' => 0, 'conflictos' => 0,
                'revision' => 0, 'nuevos' => 0,
            ];
            $advertencias = [];
            $tocados = [];

            foreach ($resultado->hallazgosReales() as $entrante) {
                $sede = Sede::resolverPorTexto($entrante->hoja);

                if ($sede === null) {
                    $advertencias[] = "La hoja «{$entrante->hoja}» no corresponde a ninguna sede registrada.";

                    continue;
                }

                $hallazgoId = $this->resolver($entrante, $sede);

                if ($hallazgoId === null) {
                    $this->registrarNuevo($corte, $entrante, $sede);
                    $conteo['nuevos']++;

                    continue;
                }

                $tocados[$hallazgoId] = true;
                $clave = $this->aplicar($corte, $hallazgoId, $entrante, $huellasExportadas);
                $conteo[$clave]++;
            }

            $ausentes = $this->marcarAusentes($corte, $tocados);

            return [
                'corte' => $corte->periodo,
                'filas' => $resultado->totalHallazgos(),
                ...$conteo,
                'ausentes' => $ausentes,
                'periodo_del_archivo' => $huellasExportadas['__periodo'] ?? null,
                'advertencias' => array_values(array_unique([...$advertencias, ...$resultado->advertencias])),
            ];
        });
    }

    /**
     * Primero el REF, y solo si falla el emparejamiento por texto. El bloqueo
     * por sede se aplica igual en la segunda vía.
     */
    private function resolver(HallazgoExtraido $entrante, Sede $sede): ?int
    {
        $porReferencia = Hallazgo::interpretarReferencia($entrante->referencia);

        if ($porReferencia !== null) {
            $hallazgo = Hallazgo::find($porReferencia);

            // Un REF válido de otra sede sería una fila copiada entre hojas.
            if ($hallazgo !== null && $hallazgo->sede_id === $sede->id) {
                return $porReferencia;
            }
        }

        $candidatos = Hallazgo::query()
            ->deSede($sede->id)
            ->deEstandar($entrante->codigoEstandar)
            ->pluck('descripcion', 'id')
            ->all();

        if ($candidatos === []) {
            return null;
        }

        $emparejador = new Emparejador(new Similitud(new IndiceIdf(array_values($candidatos))));
        $resultado = $emparejador->mejor($entrante->descripcion, $candidatos);

        return $resultado->esFirme() ? (int) $resultado->clave : null;
    }

    /**
     * @param  array<string, string>  $huellasExportadas
     * @return 'aplicados'|'sin_cambio'|'conflictos'
     */
    private function aplicar(Corte $corte, int $hallazgoId, HallazgoExtraido $entrante, array $huellasExportadas): string
    {
        $foto = HallazgoEstadoCorte::query()
            ->where('corte_id', $corte->id)
            ->where('hallazgo_id', $hallazgoId)
            ->first();

        if ($foto === null) {
            $foto = HallazgoEstadoCorte::create([
                'hallazgo_id' => $hallazgoId,
                'corte_id' => $corte->id,
                'estado' => EstadoHallazgo::SinDato,
                'presente_en_corte' => false,
            ]);
        }

        $entranteEstado = $entrante->estado ?? EstadoHallazgo::SinDato;
        $huellaEntrante = HallazgoEstadoCorte::calcularHuella(
            $entranteEstado,
            $entrante->accionPropuesta,
            $entrante->responsable,
            $entrante->evidencia,
        );
        $huellaActual = $foto->huellaEditables();

        if ($huellaEntrante === $huellaActual) {
            $foto->update(['presente_en_corte' => true]);

            return 'sin_cambio';
        }

        $huellaExportada = $huellasExportadas[$foto->hallazgo_id] ?? null;

        // Si la app cambió el dato después de exportar Y el Excel también trae
        // otro valor, los dos lados se movieron: nadie gana en silencio.
        $cambioLaApp = $huellaExportada !== null && $huellaExportada !== $huellaActual;

        if ($cambioLaApp) {
            Reconciliacion::create([
                'flujo' => 'corte',
                'corte_id' => $corte->id,
                'hallazgo_id' => $hallazgoId,
                'destino' => DestinoReconciliacion::Conflicto,
                'texto_entrante' => $this->describir($entranteEstado, $entrante),
                'resuelto' => false,
            ]);

            $foto->update(['presente_en_corte' => true]);

            return 'conflictos';
        }

        $foto->update([
            'estado' => $entranteEstado,
            'accion_propuesta' => $entrante->accionPropuesta,
            'responsable' => $entrante->responsable,
            'evidencia' => $entrante->evidencia,
            'presente_en_corte' => true,
        ]);

        return 'aplicados';
    }

    /**
     * Una fila sin correspondencia es un hallazgo registrado directamente por
     * seguimiento, sin auditoría de respaldo. Se anota como tal para que se vea.
     */
    private function registrarNuevo(Corte $corte, HallazgoExtraido $entrante, Sede $sede): void
    {
        Reconciliacion::create([
            'flujo' => 'corte',
            'corte_id' => $corte->id,
            'hallazgo_id' => null,
            'destino' => DestinoReconciliacion::Nuevo,
            'texto_entrante' => $entrante->descripcion,
            'resuelto' => false,
        ]);
    }

    /**
     * Los que nadie reportó este mes conservan su estado y quedan señalados.
     * NUNCA se cierran por omisión: lo más probable es que la fila se haya
     * quedado sin digitar, no que el problema se haya resuelto.
     *
     * @param  array<int, bool>  $tocados
     */
    private function marcarAusentes(Corte $corte, array $tocados): int
    {
        $ausentes = HallazgoEstadoCorte::query()
            ->where('corte_id', $corte->id)
            ->where('presente_en_corte', false)
            ->when($tocados !== [], fn ($q) => $q->whereNotIn('hallazgo_id', array_keys($tocados)))
            ->get();

        foreach ($ausentes as $foto) {
            Reconciliacion::firstOrCreate([
                'flujo' => 'corte',
                'corte_id' => $corte->id,
                'hallazgo_id' => $foto->hallazgo_id,
                'destino' => DestinoReconciliacion::AusenteDelCorte,
            ], ['resuelto' => false]);
        }

        return $ausentes->count();
    }

    /**
     * Lee la hoja oculta de control: el periodo del libro y la huella que
     * tenía cada fila al momento de exportar.
     *
     * @return array<int|string, string>
     */
    private function leerControl(string $ruta): array
    {
        try {
            $reader = IOFactory::createReaderForFile($ruta);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([ExportadorMatriz::HOJA_CONTROL]);
            $libro = $reader->load($ruta);
            $hoja = $libro->getSheetByName(ExportadorMatriz::HOJA_CONTROL);
        } catch (\Throwable) {
            // Un archivo que no salió de aquí no tiene hoja de control, y eso
            // no es un error: se cae al emparejamiento por texto.
            return [];
        }

        if ($hoja === null) {
            return [];
        }

        $huellas = ['__periodo' => TextoNormalizador::visible((string) $hoja->getCell('B1')->getValue())];

        for ($fila = 6; $fila <= $hoja->getHighestDataRow(); $fila++) {
            $id = $hoja->getCell("B{$fila}")->getValue();

            if (is_numeric($id)) {
                $huellas[(int) $id] = (string) $hoja->getCell("C{$fila}")->getValue();
            }
        }

        $libro->disconnectWorksheets();

        return $huellas;
    }

    private function describir(EstadoHallazgo $estado, HallazgoExtraido $entrante): string
    {
        return sprintf(
            'Excel: %s | acción: %s | responsable: %s',
            $estado->etiqueta(),
            $entrante->accionPropuesta ?? '—',
            $entrante->responsable ?? '—',
        );
    }
}
