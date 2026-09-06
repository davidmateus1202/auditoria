<?php

declare(strict_types=1);

namespace App\Services\Reconciliacion;

use App\Domain\Enums\EstadoCorte;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Enums\OrigenAuditoria;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\Dto\HallazgoExtraido;
use App\Models\Auditoria;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use App\Services\Extraccion\ExtractorExcel;
use Illuminate\Support\Facades\DB;

/**
 * Carga la matriz de seguimiento como primer corte del sistema.
 *
 * Es la única vez que los estados entran desde el histórico sin reconciliarse
 * contra nada: a partir de aquí el estado lo mantiene la aplicación, y cada mes
 * nuevo pasa por el flujo B.
 *
 * Ojo con este paso: si la matriz entra con errores, esos errores se arrastran
 * a todos los cierres futuros y a la antigüedad de cada hallazgo. Por eso el
 * importador es idempotente mientras el corte siga abierto — se puede rehacer
 * cuantas veces haga falta hasta darlo por bueno.
 */
final class ImportadorLineaBase
{
    public function __construct(private readonly ExtractorExcel $extractor = new ExtractorExcel()) {}

    /**
     * @return array{
     *     corte: string, hallazgos: int, sedes: int,
     *     por_estado: array<string,int>, sin_sede: list<string>
     * }
     */
    public function importar(string $ruta, string $periodo): array
    {
        $resultado = $this->extractor->extraer($ruta, TipoFormato::Seguimiento);

        return DB::transaction(function () use ($resultado, $periodo): array {
            $corte = $this->corteDestino($periodo);

            // Rehacer una importación no debe duplicar nada: se limpia lo que
            // este mismo corte hubiera dejado antes.
            HallazgoEstadoCorte::query()->where('corte_id', $corte->id)->delete();

            $porSede = $this->agruparPorSede($resultado->hallazgosReales());
            $sinSede = [];
            $creados = 0;
            $porEstado = [];

            foreach ($porSede as $nombreHoja => $hallazgos) {
                $sede = Sede::resolverPorTexto($nombreHoja);

                if ($sede === null) {
                    $sinSede[] = $nombreHoja;

                    continue;
                }

                $auditoria = $this->auditoriaDeLineaBase($sede, $periodo);

                foreach ($hallazgos as $extraido) {
                    $hallazgo = $this->registrar($sede, $auditoria, $extraido);
                    $this->congelar($hallazgo, $corte, $extraido);

                    $creados++;
                    $clave = $hallazgo->estado->value;
                    $porEstado[$clave] = ($porEstado[$clave] ?? 0) + 1;
                }
            }

            return [
                'corte' => $corte->periodo,
                'hallazgos' => $creados,
                'sedes' => count($porSede) - count($sinSede),
                'por_estado' => $porEstado,
                'sin_sede' => $sinSede,
            ];
        });
    }

    private function corteDestino(string $periodo): Corte
    {
        $corte = Corte::query()->firstOrCreate(
            ['periodo' => $periodo],
            ['fecha_corte' => $periodo.'-01', 'estado' => EstadoCorte::Abierto],
        );

        if (! $corte->admiteEscritura()) {
            throw new \RuntimeException(
                "El corte {$periodo} está cerrado. Reabrirlo es una decisión del administrador, ".
                'porque los consolidados de ese mes dejarían de ser reproducibles.'
            );
        }

        return $corte;
    }

    private function auditoriaDeLineaBase(Sede $sede, string $periodo): Auditoria
    {
        return Auditoria::query()->firstOrCreate(
            ['sede_id' => $sede->id, 'origen' => OrigenAuditoria::LineaBase->value],
            [
                'version' => Auditoria::siguienteVersion($sede->id),
                'periodo' => $periodo,
                'estado' => 'publicada',
            ],
        );
    }

    private function registrar(Sede $sede, Auditoria $auditoria, HallazgoExtraido $extraido): Hallazgo
    {
        $huella = $extraido->huella($sede->codigo);

        // La huella deduplica dentro de la carga; el emparejamiento ENTRE
        // periodos es otro problema y usa similitud de texto.
        return Hallazgo::updateOrCreate(
            ['sede_id' => $sede->id, 'huella' => $huella],
            [
                'estandar_codigo' => $extraido->codigoEstandar,
                'auditoria_origen_id' => $auditoria->id,
                'descripcion' => $extraido->descripcion,
                'clasificacion' => $extraido->clasificacion,
                'estado' => $extraido->estado ?? EstadoHallazgo::SinDato,
                'accion_propuesta' => $extraido->accionPropuesta,
                'responsable' => $extraido->responsable,
                'evidencia' => $extraido->evidencia,
            ],
        );
    }

    private function congelar(Hallazgo $hallazgo, Corte $corte, HallazgoExtraido $extraido): void
    {
        $estado = $extraido->estado ?? EstadoHallazgo::SinDato;

        HallazgoEstadoCorte::create([
            'hallazgo_id' => $hallazgo->id,
            'corte_id' => $corte->id,
            'estado' => $estado,
            'presente_en_corte' => true,
            'meses_abierto' => $estado->esVigente() ? 1 : 0,
            'accion_propuesta' => $extraido->accionPropuesta,
            'responsable' => $extraido->responsable,
            'evidencia' => $extraido->evidencia,
            'huella_exportada' => HallazgoEstadoCorte::calcularHuella(
                $estado,
                $extraido->accionPropuesta,
                $extraido->responsable,
                $extraido->evidencia,
            ),
        ]);
    }

    /**
     * @param  list<HallazgoExtraido>  $hallazgos
     * @return array<string, list<HallazgoExtraido>>
     */
    private function agruparPorSede(array $hallazgos): array
    {
        $agrupado = [];

        foreach ($hallazgos as $hallazgo) {
            $agrupado[$hallazgo->hoja][] = $hallazgo;
        }

        return $agrupado;
    }
}
