<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Services\Evidencias\ServicioFotos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extrae las fotos de evidencia de auditorías que ya estaban cargadas.
 *
 * Las cargas nuevas lo hacen solas; esto es para las anteriores al módulo de
 * evidencias, o para reprocesar una si cambia la forma de extraerlas. Lee el
 * archivo original guardado, así que no hay que volver a subir nada.
 */
class ExtraerFotos extends Command
{
    protected $signature = 'suh:extraer-fotos
                            {auditoria?* : Ids de auditoría; sin ninguno, todas las que tienen archivo}';

    protected $description = 'Extrae las fotos de las hojas FOTOS / EVIDENCIAS de los archivos ya cargados';

    public function handle(ServicioFotos $fotos): int
    {
        $ids = array_map('intval', (array) $this->argument('auditoria'));

        $auditorias = Auditoria::query()
            ->with(['sede:id,nombre', 'archivos'])
            ->whereHas('archivos')
            ->when($ids !== [], fn ($q) => $q->whereKey($ids))
            ->orderBy('id')
            ->get();

        $total = 0;
        $fallas = 0;

        foreach ($auditorias as $auditoria) {
            $archivo = $auditoria->archivos->sortByDesc('id')->first();
            $etiqueta = "#{$auditoria->id} {$auditoria->sede?->nombre} v{$auditoria->version}";

            if (! Storage::exists($archivo->ruta)) {
                $this->components->warn("{$etiqueta}: el archivo original ya no está guardado.");

                continue;
            }

            try {
                $cantidad = $fotos->procesar($auditoria, Storage::path($archivo->ruta));
                $total += $cantidad;
                $this->components->twoColumnDetail($etiqueta, "{$cantidad} fotos");
            } catch (Throwable $e) {
                $fallas++;
                $this->components->error("{$etiqueta}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->components->info("{$total} fotos extraídas de {$auditorias->count()} auditorías.");

        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
