<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\Excepciones\EstructuraInvalida;
use App\Services\Extraccion\ExtractorExcel;
use Illuminate\Console\Command;

/**
 * Corre el extractor sobre un archivo sin tocar la base de datos.
 *
 * Sirve para comprobar un archivo nuevo antes de cargarlo: si la plantilla
 * cambió, aquí sale el error con la hoja y la fila exactas.
 */
class ExtraerArchivo extends Command
{
    protected $signature = 'suh:extraer
                            {archivo : Ruta del .xlsx o .xls a leer}
                            {--detalle : Lista cada hallazgo extraído}
                            {--cumplimiento : Muestra el cumplimiento por servicio}';

    protected $description = 'Lee una autoevaluación o una matriz de seguimiento y muestra lo que se extrajo';

    public function handle(ExtractorExcel $extractor): int
    {
        $archivo = (string) $this->argument('archivo');

        try {
            $inicio = microtime(true);
            $resultado = $extractor->extraer($archivo);
            $segundos = microtime(true) - $inicio;
        } catch (EstructuraInvalida $e) {
            $this->components->error('El archivo no tiene la estructura esperada.');

            foreach ($e->contexto() as $clave => $valor) {
                $this->line(sprintf('  <fg=gray>%-11s</> %s', $clave.':', $valor));
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s · %s · %.1fs',
            basename($archivo),
            $resultado->formato->etiqueta(),
            $segundos
        ));

        $this->components->twoColumnDetail('<fg=gray>Sede</>', $resultado->sede ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Auditor</>', $resultado->auditor ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Fecha</>', $resultado->fechaAuditoria ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Filas leídas</>', (string) $resultado->filasLeidas);
        $this->components->twoColumnDetail('<fg=green>Hallazgos reales</>', (string) $resultado->totalHallazgos());

        $clasificacion = $resultado->porClasificacion();
        $noCuentan = $clasificacion['cumple'] + $clasificacion['sin_dato'];

        if ($noCuentan > 0) {
            $this->components->twoColumnDetail(
                '<fg=gray>Filas que no son hallazgo</>',
                sprintf('%d  (%d «cumple», %d sin dato)', $noCuentan, $clasificacion['cumple'], $clasificacion['sin_dato'])
            );
        }

        if ($clasificacion['dudoso'] > 0) {
            $this->components->twoColumnDetail('<fg=yellow>Dudosos, van a revisión</>', (string) $clasificacion['dudoso']);
        }

        $this->tablaPorEstandar($resultado);

        if ($this->option('cumplimiento')) {
            $this->tablaCumplimiento($resultado);
        }

        if ($this->option('detalle')) {
            $this->detalle($resultado);
        }

        foreach ($resultado->advertencias as $advertencia) {
            $this->components->warn($advertencia);
        }

        foreach ($resultado->descartes as $descarte) {
            $this->line(sprintf(
                '  <fg=gray>descartado</> %s R%d — %s',
                $descarte['hoja'],
                $descarte['fila'],
                $descarte['motivo']
            ));
        }

        return self::SUCCESS;
    }

    private function tablaPorEstandar($resultado): void
    {
        $total = max(1, $resultado->totalHallazgos());
        $filas = [];

        foreach ($resultado->porEstandar() as $codigo => $cantidad) {
            if ($cantidad === 0) {
                continue;
            }

            $filas[] = [
                $codigo,
                CatalogoEstandares::nombre($codigo),
                $cantidad,
                sprintf('%5.1f %%', $cantidad / $total * 100),
            ];
        }

        if ($filas !== []) {
            $this->newLine();
            $this->table(['Cód.', 'Estándar', 'Hallazgos', 'Del total'], $filas);
        }

        // El estado solo existe en la matriz de seguimiento: la autoevaluación
        // dice qué hallazgos hay, no cómo va su gestión.
        if ($resultado->formato !== \App\Domain\Enums\TipoFormato::Seguimiento) {
            return;
        }

        $porEstado = array_filter($resultado->porEstado());

        if ($porEstado !== []) {
            $this->table(
                ['Estado', 'Hallazgos'],
                array_map(null, array_keys($porEstado), array_values($porEstado))
            );
        }
    }

    private function tablaCumplimiento($resultado): void
    {
        $filas = [];

        foreach ($resultado->porServicio() as $servicio) {
            $filas[] = [
                mb_strimwidth($servicio->servicio, 0, 44, '…'),
                $this->porcentaje($servicio->totalOficial()),
                $this->porcentaje($servicio->totalPonderado()),
                $this->porcentaje($servicio->totalEstricto()),
            ];
        }

        if ($filas === []) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=gray>Oficial: promedio de los 6 primeros estándares, como la hoja RESULTADOS.</>');
        $this->line('  <fg=gray>Estricto: sin contar «No aplica» como cumplimiento.</>');
        $this->table(['Servicio', 'Oficial', 'Ponderado', 'Estricto'], $filas);
    }

    private function detalle($resultado): void
    {
        $this->newLine();

        foreach ($resultado->hallazgos as $hallazgo) {
            $color = $hallazgo->cuenta() ? 'default' : 'gray';

            $this->line(sprintf(
                '  <fg=%s>%-3s %-9s %s R%-4d %s</>',
                $color,
                $hallazgo->codigoEstandar,
                $hallazgo->clasificacion->value,
                mb_strimwidth($hallazgo->hoja, 0, 12, ''),
                $hallazgo->fila,
                mb_strimwidth($hallazgo->descripcion, 0, 76, '…')
            ));
        }
    }

    private function porcentaje(?float $valor): string
    {
        return $valor === null ? '—' : sprintf('%5.1f %%', $valor * 100);
    }
}
