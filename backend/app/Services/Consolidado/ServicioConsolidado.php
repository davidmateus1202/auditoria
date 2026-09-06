<?php

declare(strict_types=1);

namespace App\Services\Consolidado;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Corte;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * El consolidado, calculado siempre A UNA FECHA.
 *
 * Se lee de la foto congelada del corte, no del estado actual del hallazgo: por
 * eso el consolidado de marzo sigue dando lo mismo en diciembre aunque en el
 * camino se hayan cerrado veinte hallazgos. El archivo que usan hoy no tiene
 * fecha, y por eso no se puede auditar hacia atrás.
 *
 * Los tres cortes que pidió el usuario —por sede, por estándar, y sede ×
 * estándar— son la misma consulta con distinta agrupación, así que la pantalla
 * y el Excel exportado salen siempre del mismo cálculo.
 */
final class ServicioConsolidado
{
    /** @param list<int> $sedeIds vacío = todas las sedes */
    public function generar(string $periodo, array $sedeIds = []): Consolidado
    {
        $corte = Corte::query()->where('periodo', $periodo)->first()
            ?? throw new RuntimeException("No existe el corte {$periodo}.");

        $filas = $this->filas($corte, $sedeIds);

        return new Consolidado(
            periodo: $corte->periodo,
            corteCerrado: ! $corte->admiteEscritura(),
            generales: $this->generales($filas),
            porSede: $this->porSede($filas),
            porEstandar: $this->porEstandar($filas),
            sedePorEstandar: $this->sedePorEstandar($filas),
        );
    }

    /** Serie mensual: lo que hoy nadie puede armar sin abrir doce archivos. */
    public function serie(array $sedeIds = []): array
    {
        $serie = [];

        foreach (Corte::query()->orderBy('periodo')->get() as $corte) {
            $filas = $this->filas($corte, $sedeIds);

            if ($filas->isEmpty()) {
                continue;
            }

            $generales = $this->generales($filas);

            $serie[] = [
                'periodo' => $corte->periodo,
                'cerrado' => ! $corte->admiteEscritura(),
                ...$generales,
            ];
        }

        return $serie;
    }

    /**
     * Una fila por hallazgo vigente en ese corte, con lo justo para agregar.
     *
     * @return Collection<int, object>
     */
    private function filas(Corte $corte, array $sedeIds): Collection
    {
        return HallazgoEstadoCorte::query()
            ->join('hallazgos', 'hallazgos.id', '=', 'hallazgo_estado_corte.hallazgo_id')
            ->join('sedes', 'sedes.id', '=', 'hallazgos.sede_id')
            ->where('hallazgo_estado_corte.corte_id', $corte->id)
            ->when($sedeIds !== [], fn ($q) => $q->whereIn('hallazgos.sede_id', $sedeIds))
            ->select([
                'hallazgo_estado_corte.estado',
                'hallazgo_estado_corte.presente_en_corte',
                'hallazgo_estado_corte.meses_abierto',
                'hallazgos.estandar_codigo',
                'sedes.id as sede_id',
                'sedes.codigo as sede_codigo',
                'sedes.nombre as sede_nombre',
            ])
            ->get();
    }

    private function generales(Collection $filas): array
    {
        $total = $filas->count();
        $cerrados = $filas->where('estado', EstadoHallazgo::Cerrado->value)->count();
        $conEvidencia = $filas->where('estado', EstadoHallazgo::AbiertoConEvidencia->value)->count();
        $abiertos = $filas->where('estado', EstadoHallazgo::Abierto->value)->count();
        $sinDato = $filas->where('estado', EstadoHallazgo::SinDato->value)->count();

        return [
            'hallazgos' => $total,
            'cerrados' => $cerrados,
            'abiertos_con_evidencia' => $conEvidencia,
            'abiertos' => $abiertos,
            'sin_dato' => $sinDato,
            'sin_reportar' => $filas->where('presente_en_corte', false)->count(),
            'pct_avance' => $total === 0 ? null : $cerrados / $total,
            'pct_con_gestion' => $total === 0 ? null : ($cerrados + $conEvidencia) / $total,
        ];
    }

    private function porSede(Collection $filas): array
    {
        $resultado = [];

        foreach ($filas->groupBy('sede_codigo') as $codigo => $grupo) {
            $resultado[] = [
                'sede' => (string) $codigo,
                'nombre' => $grupo->first()->sede_nombre,
                ...$this->generales($grupo),
                'mas_antiguo_meses' => (int) $grupo->max('meses_abierto'),
            ];
        }

        usort($resultado, static fn ($a, $b) => $b['hallazgos'] <=> $a['hallazgos']);

        return $resultado;
    }

    private function porEstandar(Collection $filas): array
    {
        $total = max(1, $filas->count());
        $resultado = [];

        foreach ($filas->groupBy('estandar_codigo') as $codigo => $grupo) {
            $resultado[] = [
                'estandar' => (string) $codigo,
                'nombre' => CatalogoEstandares::nombre((string) $codigo),
                ...$this->generales($grupo),
                'pct_del_total' => $grupo->count() / $total,
            ];
        }

        usort($resultado, static fn ($a, $b) => $b['hallazgos'] <=> $a['hallazgos']);

        return $resultado;
    }

    /** La matriz sede × estándar, con totales marginales y el estándar dominante. */
    private function sedePorEstandar(Collection $filas): array
    {
        $codigos = CatalogoEstandares::codigos();
        $matriz = [];

        foreach ($filas->groupBy('sede_codigo') as $codigo => $grupo) {
            $celdas = array_fill_keys($codigos, 0);

            foreach ($grupo->groupBy('estandar_codigo') as $estandar => $delEstandar) {
                $celdas[(string) $estandar] = $delEstandar->count();
            }

            $totalSede = $grupo->count();
            $dominante = array_search(max($celdas), $celdas, true);

            $matriz[] = [
                'sede' => (string) $codigo,
                'nombre' => $grupo->first()->sede_nombre,
                'estandares' => $celdas,
                'total' => $totalSede,
                'estandar_dominante' => $dominante,
                'pct_dominante' => $totalSede === 0 ? null : max($celdas) / $totalSede,
            ];
        }

        usort($matriz, static fn ($a, $b) => $b['total'] <=> $a['total']);

        return $matriz;
    }
}
