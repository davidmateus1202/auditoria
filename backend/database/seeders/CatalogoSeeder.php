<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Extraccion\CatalogoEstandares;
use App\Domain\Extraccion\CatalogoServicios;
use App\Models\Estandar;
use App\Models\EstandarAlias;
use App\Models\Sede;
use App\Models\Servicio;
use Illuminate\Database\Seeder;

/**
 * Siembra los catálogos cerrados: estándares con sus alias, servicios y las
 * diez sedes de la E.S.E. municipal de Villavicencio.
 *
 * El catálogo de estándares está anclado a la Resolución 3100 de 2019. La
 * plantilla de autoevaluación todavía dice «RES 2003 DE 2014» en varias hojas,
 * pero esa etiqueta no se usa para nada operativo.
 */
class CatalogoSeeder extends Seeder
{
    /** Las diez sedes que aparecen en la matriz de seguimiento. */
    private const SEDES = [
        ['RECREO', 'Centro de Salud El Recreo'],
        ['ESPERANZA', 'Centro de Salud La Esperanza'],
        ['CEMI', 'CEMI'],
        ['MORICHAL', 'Centro de Salud Morichal'],
        ['KIRPAS', 'Centro de Salud Kirpas'],
        ['RELIQUIA', 'Centro de Salud La Reliquia'],
        ['BARZAL', 'Centro de Salud Barzal'],
        ['PORVENIR', 'Centro de Salud El Porvenir'],
        ['PORFIA', 'Centro de Salud Porfía'],
        ['POPULAR', 'Centro de Salud Popular'],
    ];

    public function run(): void
    {
        foreach (CatalogoEstandares::todos() as $codigo => [$nombre, $orden, $esNormativo]) {
            Estandar::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'orden' => $orden, 'es_normativo' => $esNormativo],
            );
        }

        foreach (CatalogoEstandares::alias() as $alias => $codigo) {
            EstandarAlias::updateOrCreate(
                ['alias_normalizado' => $alias],
                ['estandar_codigo' => $codigo, 'origen' => 'semilla'],
            );
        }

        foreach (CatalogoServicios::oficiales() as $servicio) {
            Servicio::updateOrCreate(['nombre' => $servicio['nombre']], ['grupo' => $servicio['grupo']]);
        }

        foreach (self::SEDES as [$codigo, $nombre]) {
            Sede::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'municipio' => 'Villavicencio', 'activa' => true],
            );
        }
    }
}
