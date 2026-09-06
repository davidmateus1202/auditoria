<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Dto;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Enums\TipoFormato;
use App\Domain\Extraccion\CatalogoEstandares;

/**
 * Todo lo que se pudo leer de un archivo, con el detalle suficiente para que
 * la pantalla de revisión explique qué se detectó y qué quedó dudoso.
 */
final class ResultadoExtraccion
{
    /** @var list<HallazgoExtraido> */
    public array $hallazgos = [];

    /** @var list<CriterioExtraido> */
    public array $criterios = [];

    /** @var list<array{hoja:string,fila:int,motivo:string,texto:string}> */
    public array $descartes = [];

    /** @var list<string> */
    public array $advertencias = [];

    public int $filasLeidas = 0;

    public function __construct(
        public readonly TipoFormato $formato,
        public readonly ?string $sede = null,
        public readonly ?string $auditor = null,
        public readonly ?string $responsable = null,
        public readonly ?string $fechaAuditoria = null,
        public readonly ?string $normativaDeclarada = null,
    ) {}

    public function agregarHallazgo(HallazgoExtraido $h): void
    {
        $this->hallazgos[] = $h;
    }

    public function agregarCriterio(CriterioExtraido $c): void
    {
        $this->criterios[] = $c;
    }

    public function descartar(string $hoja, int $fila, string $motivo, string $texto = ''): void
    {
        $this->descartes[] = [
            'hoja' => $hoja,
            'fila' => $fila,
            'motivo' => $motivo,
            'texto' => mb_substr($texto, 0, 160, 'UTF-8'),
        ];
    }

    public function advertir(string $mensaje): void
    {
        if (! in_array($mensaje, $this->advertencias, true)) {
            $this->advertencias[] = $mensaje;
        }
    }

    /** Hallazgos que cuentan: el criterio corregido, sin las filas «Cumple». */
    public function hallazgosReales(): array
    {
        return array_values(array_filter($this->hallazgos, static fn (HallazgoExtraido $h) => $h->cuenta()));
    }

    public function totalHallazgos(): int
    {
        return count($this->hallazgosReales());
    }

    public function dudosos(): array
    {
        return array_values(array_filter($this->hallazgos, static fn (HallazgoExtraido $h) => $h->requiereRevision()));
    }

    /** @return array<string,int> código de estándar => hallazgos reales */
    public function porEstandar(): array
    {
        $conteo = array_fill_keys(CatalogoEstandares::codigos(), 0);

        foreach ($this->hallazgosReales() as $h) {
            $conteo[$h->codigoEstandar] = ($conteo[$h->codigoEstandar] ?? 0) + 1;
        }

        return $conteo;
    }

    /** @return array<string,int> valor del estado => hallazgos reales */
    public function porEstado(): array
    {
        $conteo = [];

        foreach ($this->hallazgosReales() as $h) {
            $clave = ($h->estado ?? EstadoHallazgo::SinDato)->value;
            $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
        }

        return $conteo;
    }

    /** @return array<string,int> clasificación => cuántas filas */
    public function porClasificacion(): array
    {
        $conteo = [];

        foreach (ClasificacionHallazgo::cases() as $caso) {
            $conteo[$caso->value] = 0;
        }

        foreach ($this->hallazgos as $h) {
            $conteo[$h->clasificacion->value]++;
        }

        return $conteo;
    }

    /** @return array<string, ResumenCumplimiento> clave "servicio|estandar" */
    public function cumplimiento(): array
    {
        $resumenes = [];

        foreach ($this->criterios as $c) {
            $clave = $c->servicio.'|'.$c->codigoEstandar;
            $r = $resumenes[$clave] ??= new ResumenCumplimiento($c->servicio, $c->codigoEstandar);

            match ($c->marca) {
                \App\Domain\Enums\MarcaCriterio::Cumple => $r->cumple++,
                \App\Domain\Enums\MarcaCriterio::NoCumple => $r->noCumple++,
                \App\Domain\Enums\MarcaCriterio::NoAplica => $r->noAplica++,
                \App\Domain\Enums\MarcaCriterio::SinMarcar => $r->sinMarcar++,
            };
        }

        return $resumenes;
    }

    /**
     * Estándares que esta auditoría sí revisó.
     *
     * Es la pieza que sostiene la regla de seguridad del cierre: un hallazgo
     * solo se propone para cerrar si su estándar aparece aquí. Un estándar que
     * volvió como «No verificado» no cuenta — eso es una alerta de cobertura,
     * no la prueba de que el problema se resolvió. Y un estándar que no aparece
     * en el informe tampoco: en este formato cada estándar lleva su fila, con
     * «Cumple» cuando salió limpio, así que la ausencia significa que nadie lo
     * miró.
     *
     * @return list<string>
     */
    public function estandaresEvaluados(): array
    {
        $evaluados = [];

        foreach ($this->hallazgos as $hallazgo) {
            if ($hallazgo->clasificacion === ClasificacionHallazgo::Hallazgo
                || $hallazgo->clasificacion === ClasificacionHallazgo::Cumple) {
                $evaluados[$hallazgo->codigoEstandar] = true;
            }
        }

        return array_keys($evaluados);
    }

    /** @return array<string, ResumenServicio> nombre del servicio => resumen */
    public function porServicio(): array
    {
        $agrupado = [];

        foreach ($this->cumplimiento() as $resumen) {
            $agrupado[$resumen->servicio][$resumen->codigoEstandar] = $resumen;
        }

        $servicios = [];

        foreach ($agrupado as $nombre => $porEstandar) {
            $servicios[$nombre] = new ResumenServicio((string) $nombre, $porEstandar);
        }

        return $servicios;
    }

    /** Lo que ve el usuario al terminar la carga, antes de confirmar. */
    public function resumen(): array
    {
        return [
            'formato' => $this->formato->value,
            'sede' => $this->sede,
            'auditor' => $this->auditor,
            'fecha_auditoria' => $this->fechaAuditoria,
            'filas_leidas' => $this->filasLeidas,
            'hallazgos' => $this->totalHallazgos(),
            'dudosos' => count($this->dudosos()),
            'criterios' => count($this->criterios),
            'descartes' => count($this->descartes),
            'por_estandar' => array_filter($this->porEstandar()),
            'por_estado' => $this->porEstado(),
            'por_clasificacion' => $this->porClasificacion(),
            'advertencias' => $this->advertencias,
        ];
    }
}
