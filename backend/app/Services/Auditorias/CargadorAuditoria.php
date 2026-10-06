<?php

declare(strict_types=1);

namespace App\Services\Auditorias;

use App\Domain\Enums\OrigenAuditoria;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\Dto\ResultadoExtraccion;
use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Models\ArchivoCargado;
use App\Models\Auditoria;
use App\Models\EvaluacionCriterio;
use App\Models\Sede;
use App\Models\Servicio;
use App\Services\Auditorias\Excepciones\SedeNoIdentificada;
use App\Services\Evidencias\ServicioFotos;
use App\Services\Extraccion\ExtractorExcel;
use App\Services\Reconciliacion\ServicioReconciliacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Recibe una autoevaluación, la extrae y la enfrenta contra los hallazgos
 * vigentes de la sede.
 *
 * Nada queda publicado al terminar: la auditoría entra como «por confirmar» y
 * espera a que una persona revise el cruce. Que un hallazgo se cierre porque
 * un archivo dejó de mencionarlo es exactamente la decisión que no puede
 * tomarse sola.
 */
final class CargadorAuditoria
{
    public function __construct(
        private readonly ExtractorExcel $extractor = new ExtractorExcel(),
        private readonly ServicioReconciliacion $reconciliacion = new ServicioReconciliacion(),
        private readonly ServicioFotos $fotos = new ServicioFotos(),
    ) {}

    /**
     * @param  array<string, string>  $correcciones  celdas corregidas en la vista previa, por «HOJA!A12»
     * @return array{auditoria:Auditoria, resultado:ResultadoExtraccion, propuesta:array, fotos:int}
     */
    public function cargar(UploadedFile $archivo, ?int $sedeId = null, ?string $periodo = null, array $correcciones = []): array
    {
        $huella = hash_file('sha256', $archivo->getRealPath());
        $yaCargado = ArchivoCargado::query()->where('hash_sha256', $huella)->first();

        if ($yaCargado !== null) {
            throw new RuntimeException(sprintf(
                'Este archivo ya se cargó el %s como «%s». Si es una versión nueva, guárdelo con los cambios antes de subirlo.',
                $yaCargado->created_at?->format('d/m/Y'),
                $yaCargado->nombre_original,
            ));
        }

        $ruta = $archivo->getRealPath();
        $formato = $this->extractor->detectar($ruta);

        if ($formato !== TipoFormato::Autoevaluacion) {
            throw new EstructuraInvalida(
                'El archivo es una matriz de seguimiento, no una autoevaluación. '.
                'Las matrices mensuales se suben desde la pantalla del corte.'
            );
        }

        $resultado = $this->extractor->extraer($ruta, TipoFormato::Autoevaluacion, $correcciones);
        $sede = $this->resolverSede($resultado, $sedeId);

        $carga = DB::transaction(function () use ($archivo, $resultado, $sede, $periodo, $huella, $correcciones): array {
            $auditoria = Auditoria::create([
                'sede_id' => $sede->id,
                'version' => Auditoria::siguienteVersion($sede->id),
                'periodo' => $periodo ?? now()->format('Y-m'),
                'auditor' => $resultado->auditor,
                'responsable' => $resultado->responsable,
                'origen' => OrigenAuditoria::Autoevaluacion,
                'estado' => 'por_confirmar',
                'normativa_declarada' => $resultado->normativaDeclarada,
            ]);

            $this->guardarArchivo($archivo, $auditoria, $resultado, $huella, $correcciones);
            $this->guardarCriterios($auditoria, $resultado);

            $propuesta = $this->reconciliacion->reconciliarVigencia($auditoria, $resultado);

            return [
                'auditoria' => $auditoria,
                'resultado' => $resultado,
                'propuesta' => $propuesta->resumen(),
            ];
        });

        // Las fotos van fuera de la transacción y nunca tumban la carga: una
        // auditoría sin sus fotos sigue sirviendo; una que no entra por culpa
        // de una imagen dañada, no.
        try {
            $carga['fotos'] = $this->fotos->procesar($carga['auditoria'], $ruta);
        } catch (Throwable $e) {
            report($e);
            $carga['fotos'] = 0;
            $resultado->advertir('No se pudieron extraer las fotos de evidencia del archivo: '.$e->getMessage());
        }

        return $carga;
    }

    /**
     * La sede sale del propio archivo —«Centro de Salud Morichal» en la hoja
     * INFORME— y solo se pide explícitamente cuando el archivo no la dice o la
     * dice de una forma que no está registrada.
     */
    private function resolverSede(ResultadoExtraccion $resultado, ?int $sedeId): Sede
    {
        if ($sedeId !== null) {
            return Sede::findOrFail($sedeId);
        }

        $sede = Sede::resolverPorTexto($resultado->sede);

        if ($sede === null) {
            throw new SedeNoIdentificada(
                sprintf(
                    'No se pudo identificar la sede a partir del archivo%s. Elíjala en la lista y vuelva a subirlo.',
                    $resultado->sede !== null ? " (dice «{$resultado->sede}»)" : '',
                ),
                textoEncontrado: $resultado->sede,
                sugerencia: Sede::sugerirPorTexto($resultado->sede),
                celda: $resultado->celdaSede,
            );
        }

        return $sede;
    }

    private function guardarArchivo(
        UploadedFile $archivo,
        Auditoria $auditoria,
        ResultadoExtraccion $resultado,
        string $huella,
        array $correcciones,
    ): void {
        // Se conserva el original para poder reprocesarlo si el extractor
        // mejora, sin pedirle nada al usuario.
        $ruta = $archivo->store('auditorias');

        ArchivoCargado::create([
            'auditoria_id' => $auditoria->id,
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => $ruta,
            'hash_sha256' => $huella,
            'formato' => TipoFormato::Autoevaluacion,
            'filas_leidas' => $resultado->filasLeidas,
            // Lo que se corrigió en pantalla queda registrado junto al
            // original, que se guarda intacto: así se sabe por qué el sistema
            // leyó algo distinto de lo que dice el archivo.
            'resumen' => $correcciones === []
                ? $resultado->resumen()
                : [...$resultado->resumen(), 'correcciones' => $correcciones],
            'procesado_en' => now(),
        ]);
    }

    private function guardarCriterios(Auditoria $auditoria, ResultadoExtraccion $resultado): void
    {
        $servicios = Servicio::query()->pluck('id', 'nombre');
        $filas = [];

        foreach ($resultado->criterios as $criterio) {
            $filas[] = [
                'auditoria_id' => $auditoria->id,
                'servicio_id' => $servicios[$criterio->servicio] ?? null,
                'estandar_codigo' => $criterio->codigoEstandar,
                'criterio' => $criterio->criterio,
                'marca' => $criterio->marca->value,
                'observacion' => $criterio->observacion,
                'hoja' => $criterio->hoja,
                'fila' => $criterio->fila,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($filas, 200) as $lote) {
            EvaluacionCriterio::insert($lote);
        }
    }
}
