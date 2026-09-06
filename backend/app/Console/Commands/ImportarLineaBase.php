<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliacion\ImportadorLineaBase;
use Illuminate\Console\Command;

/**
 * Carga la matriz de seguimiento histórica como primer corte del sistema.
 */
class ImportarLineaBase extends Command
{
    protected $signature = 'suh:importar-linea-base
                            {archivo : Matriz de seguimiento (.xls)}
                            {--periodo= : Mes del corte en formato AAAA-MM}';

    protected $description = 'Importa la matriz de seguimiento como línea base y abre el primer corte';

    public function handle(ImportadorLineaBase $importador): int
    {
        $periodo = (string) ($this->option('periodo') ?: now()->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            $this->components->error("El periodo «{$periodo}» no tiene el formato AAAA-MM.");

            return self::FAILURE;
        }

        try {
            $resumen = $importador->importar((string) $this->argument('archivo'), $periodo);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Línea base cargada en el corte {$resumen['corte']}");
        $this->components->twoColumnDetail('<fg=gray>Sedes</>', (string) $resumen['sedes']);
        $this->components->twoColumnDetail('<fg=green>Hallazgos</>', (string) $resumen['hallazgos']);

        foreach ($resumen['por_estado'] as $estado => $cantidad) {
            $this->components->twoColumnDetail("  <fg=gray>{$estado}</>", (string) $cantidad);
        }

        foreach ($resumen['sin_sede'] as $hoja) {
            $this->components->warn("La hoja «{$hoja}» no corresponde a ninguna sede registrada y se omitió.");
        }

        $this->newLine();
        $this->line('  <fg=gray>El corte queda ABIERTO: se puede volver a importar hasta darlo por bueno.</>');
        $this->line('  <fg=gray>Revisar sede por sede antes de cerrarlo — los errores de aquí se</>');
        $this->line('  <fg=gray>arrastran a todos los cierres y a la antigüedad de cada hallazgo.</>');

        return self::SUCCESS;
    }
}
