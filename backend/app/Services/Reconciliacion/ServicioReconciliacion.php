<?php

declare(strict_types=1);

namespace App\Services\Reconciliacion;

use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\Dto\ResultadoExtraccion;
use App\Domain\Reconciliacion\Dto\HallazgoVigente;
use App\Domain\Reconciliacion\Dto\Propuesta;
use App\Domain\Reconciliacion\Emparejador;
use App\Domain\Reconciliacion\IndiceIdf;
use App\Domain\Reconciliacion\ReconciliadorVigencia;
use App\Domain\Reconciliacion\Similitud;
use App\Models\Auditoria;
use App\Models\Hallazgo;
use App\Models\Reconciliacion;
use App\Models\Sede;

/**
 * Conecta el reconciliador con la base de datos.
 *
 * Aquí es donde se garantiza el bloqueo por sede: la lista de vigentes se
 * construye SIEMPRE filtrando por la sede de la auditoría. Entre sedes
 * distintas hay decenas de hallazgos con texto idéntico, porque los auditores
 * copian el mismo párrafo de un centro de salud a otro.
 */
final class ServicioReconciliacion
{
    public function reconciliarVigencia(Auditoria $auditoria, ResultadoExtraccion $resultado): Propuesta
    {
        $sede = $auditoria->sede;

        $propuesta = $this->reconciliador($sede)->reconciliar(
            $this->vigentesDe($sede),
            $resultado->hallazgosReales(),
            $resultado->estandaresEvaluados(),
            $this->cerradosDe($sede),
        );

        $this->guardar($auditoria, $propuesta);

        return $propuesta;
    }

    /**
     * Hallazgos abiertos de ESTA sede. El filtro no es una optimización: es la
     * regla que impide cerrar un hallazgo con evidencia de otro centro.
     *
     * @return list<HallazgoVigente>
     */
    public function vigentesDe(Sede $sede): array
    {
        return Hallazgo::query()
            ->deSede($sede->id)
            ->vigentes()
            ->get(['id', 'estandar_codigo', 'descripcion'])
            ->map(fn (Hallazgo $h): HallazgoVigente => new HallazgoVigente(
                id: $h->id,
                codigoEstandar: $h->estandar_codigo,
                descripcion: $h->descripcion,
            ))
            ->all();
    }

    /**
     * Hallazgos ya cerrados de la sede, para detectar reincidencias.
     *
     * @return list<HallazgoVigente>
     */
    public function cerradosDe(Sede $sede): array
    {
        return Hallazgo::query()
            ->deSede($sede->id)
            ->where('estado', EstadoHallazgo::Cerrado->value)
            ->get(['id', 'estandar_codigo', 'descripcion'])
            ->map(fn (Hallazgo $h): HallazgoVigente => new HallazgoVigente(
                id: $h->id,
                codigoEstandar: $h->estandar_codigo,
                descripcion: $h->descripcion,
            ))
            ->all();
    }

    /**
     * El IDF se calcula sobre los hallazgos de TODO el municipio, no solo de la
     * sede: cuantos más textos, mejor distingue qué palabra es discriminante.
     * Eso no rompe el bloqueo — el corpus decide los pesos, no los candidatos.
     */
    private function reconciliador(Sede $sede): ReconciliadorVigencia
    {
        $corpus = Hallazgo::query()->pluck('descripcion')->all();

        return new ReconciliadorVigencia(new Emparejador(new Similitud(new IndiceIdf($corpus))));
    }

    private function guardar(Auditoria $auditoria, Propuesta $propuesta): void
    {
        Reconciliacion::query()
            ->where('auditoria_id', $auditoria->id)
            ->where('resuelto', false)
            ->delete();

        foreach ($propuesta->movimientos as $movimiento) {
            Reconciliacion::create([
                'flujo' => 'vigencia',
                'auditoria_id' => $auditoria->id,
                'hallazgo_id' => $movimiento->hallazgoId,
                'destino' => $movimiento->destino,
                'estandar_codigo' => $movimiento->codigoEstandar(),
                'texto_entrante' => $movimiento->entrante?->descripcion,
                'similitud' => $movimiento->similitud > 0 ? round($movimiento->similitud, 3) : null,
                'margen' => $movimiento->margen > 0 ? round($movimiento->margen, 3) : null,
                'resuelto' => false,
            ]);
        }
    }
}
