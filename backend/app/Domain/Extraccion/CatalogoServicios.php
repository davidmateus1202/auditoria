<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

/**
 * Servicios habilitados y su grupo, según la hoja RESULTADOS de la plantilla.
 *
 * El nombre del servicio no se puede leer de la hoja: solo algunas traen una
 * fila de título encima del bloque de criterios. En «Hospit Baja» arriba del
 * encabezado hay un enlace «Índice» y la descripción del servicio, y en
 * «Laboratorio» hay un punto suelto. Como el nombre de la hoja sí es estable,
 * se usa como clave de un catálogo, igual que con los estándares.
 */
final class CatalogoServicios
{
    /** alias normalizado de la hoja => [nombre oficial, grupo] */
    private const SERVICIOS = [
        'TODOS' => ['Todos los servicios', 'Todos los servicios'],
        'TODOS LOS SERVICIOS' => ['Todos los servicios', 'Todos los servicios'],

        'C EXT' => ['Consulta externa general', 'Consulta externa'],
        'CONSULTA EXTERNA GENERAL' => ['Consulta externa general', 'Consulta externa'],

        'URG BAJA' => ['Urgencias de baja complejidad', 'Urgencias'],
        'URGENCIAS BAJA COMPLEJIDAD' => ['Urgencias de baja complejidad', 'Urgencias'],

        'HOSPIT BAJA' => ['Hospitalización de baja complejidad', 'Internación'],
        'HOSPITALIZACION BAJA COMPLEJIDAD' => ['Hospitalización de baja complejidad', 'Internación'],

        'PYP' => ['Protección específica y detección temprana', 'Protección específica'],
        'PROTECCION ESPECIFICA Y DETECCION TEMPRANA' => ['Protección específica y detección temprana', 'Protección específica'],

        'ODONTOLOGIA' => ['Consulta odontológica general y especializada', 'Consulta externa'],
        'CONSULTA ODONTOLOGICA GENERAL Y ESPECIALIZADA' => ['Consulta odontológica general y especializada', 'Consulta externa'],

        'TM LAB' => ['Toma de muestras de laboratorio clínico', 'Apoyo diagnóstico y complementación terapéutica'],
        'TOMA DE MUESTRAS DE LABORATORIO CLINICO' => ['Toma de muestras de laboratorio clínico', 'Apoyo diagnóstico y complementación terapéutica'],

        'LABORATORIO' => ['Laboratorio clínico', 'Apoyo diagnóstico y complementación terapéutica'],
        'LABORATORIO CLINICO' => ['Laboratorio clínico', 'Apoyo diagnóstico y complementación terapéutica'],

        'TAMIZACION CA' => ['Tamización de cáncer de cuello uterino', 'Apoyo diagnóstico y complementación terapéutica'],
        'TAMIZACION DE CANCER DE CUELLO UTERINO' => ['Tamización de cáncer de cuello uterino', 'Apoyo diagnóstico y complementación terapéutica'],

        'S FARMA BAJA' => ['Servicio farmacéutico de baja complejidad', 'Apoyo diagnóstico y complementación terapéutica'],
        'SERVICIO FARMACEUTICO BAJA COMPLEJIDAD' => ['Servicio farmacéutico de baja complejidad', 'Apoyo diagnóstico y complementación terapéutica'],

        'ESTERILIZACION' => ['Esterilización', 'Esterilización'],

        'BRIG EXTRAMU' => ['Brigadas extramurales', 'Extramural'],
    ];

    /**
     * Resuelve primero por el nombre de la hoja y, si no está, por el título
     * que aparezca dentro. Un servicio nuevo no invalida el archivo — una sede
     * puede habilitar un servicio en cualquier momento — así que se conserva el
     * nombre encontrado y se deja constancia.
     *
     * @return array{nombre:string, grupo:?string, enCatalogo:bool}
     */
    public static function resolver(string $nombreHoja, ?string $tituloInterno = null): array
    {
        foreach ([$nombreHoja, $tituloInterno] as $candidato) {
            $clave = TextoNormalizador::canonica($candidato);

            if ($clave !== '' && isset(self::SERVICIOS[$clave])) {
                return [
                    'nombre' => self::SERVICIOS[$clave][0],
                    'grupo' => self::SERVICIOS[$clave][1],
                    'enCatalogo' => true,
                ];
            }
        }

        $respaldo = TextoNormalizador::visible($tituloInterno ?: $nombreHoja);

        return [
            'nombre' => $respaldo !== '' ? $respaldo : trim($nombreHoja),
            'grupo' => null,
            'enCatalogo' => false,
        ];
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function todos(): array
    {
        return self::SERVICIOS;
    }

    /** Nombres oficiales sin repetir, para sembrar la tabla de servicios. */
    public static function oficiales(): array
    {
        $vistos = [];

        foreach (self::SERVICIOS as [$nombre, $grupo]) {
            $vistos[$nombre] = ['nombre' => $nombre, 'grupo' => $grupo];
        }

        return array_values($vistos);
    }
}
