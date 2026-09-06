<?php

declare(strict_types=1);

namespace App\Domain\Extraccion;

/**
 * Catálogo cerrado de estándares de habilitación — Resolución 3100 de 2019.
 *
 * Los nombres vienen de la plantilla, no los escribe nadie: sobre los dos
 * archivos completos aparecen 12 variantes que, tras normalizar, colapsan en
 * las 8 entradas de aquí abajo. Por eso la resolución es búsqueda exacta y no
 * emparejamiento aproximado: si un nombre no está, el archivo se rechaza en
 * lugar de asignarlo al estándar más parecido, que es como se contamina una
 * serie histórica sin que nadie se entere.
 *
 * La normativa NUNCA se lee del encabezado del archivo: la plantilla de
 * autoevaluación todavía dice "RES 2003 DE 2014" en ocho hojas mientras su
 * propia hoja INFORME ya cita la 3100 de 2019.
 */
final class CatalogoEstandares
{
    public const NORMATIVA = 'Resolución 3100 de 2019';

    public const E0_SERVICIOS_NO_PRESTADOS = 'E0';
    public const E1_TALENTO_HUMANO = 'E1';
    public const E2_INFRAESTRUCTURA = 'E2';
    public const E3_DOTACION = 'E3';
    public const E4_MEDICAMENTOS = 'E4';
    public const E5_PROCESOS_PRIORITARIOS = 'E5';
    public const E6_HISTORIA_CLINICA = 'E6';
    public const E7_INTERDEPENDENCIA = 'E7';

    /**
     * codigo => [nombre, orden, esNormativo]
     *
     * E0 no es un estándar de la resolución sino una categoría de hallazgo que
     * la E.S.E. usa en sus informes. Se conserva porque el consolidado la
     * reporta, marcada para que nadie la confunda con un estándar.
     */
    private const ESTANDARES = [
        self::E1_TALENTO_HUMANO => ['Talento humano', 1, true],
        self::E2_INFRAESTRUCTURA => ['Infraestructura', 2, true],
        self::E3_DOTACION => ['Dotación', 3, true],
        self::E4_MEDICAMENTOS => ['Medicamentos, dispositivos médicos e insumos', 4, true],
        self::E5_PROCESOS_PRIORITARIOS => ['Procesos prioritarios', 5, true],
        self::E6_HISTORIA_CLINICA => ['Historia clínica y registros', 6, true],
        self::E7_INTERDEPENDENCIA => ['Interdependencia', 7, true],
        self::E0_SERVICIOS_NO_PRESTADOS => ['Servicios habilitados no prestados', 8, false],
    ];

    /**
     * Alias normalizados => código. Todos verificados sobre los archivos
     * reales; no hay ninguno hipotético en esta lista.
     */
    private const ALIAS = [
        // Autoevaluación y matriz de seguimiento
        'TALENTO HUMANO' => self::E1_TALENTO_HUMANO,
        'INFRAESTRUCTURA' => self::E2_INFRAESTRUCTURA,
        'DOTACION' => self::E3_DOTACION,
        'MEDICAMENTOS DISPOSITIVOS MEDICOS E INSUMOS' => self::E4_MEDICAMENTOS,
        'MEDICAMENTOS Y DISPOSITIVOS MEDICOS E INSUMOS' => self::E4_MEDICAMENTOS,
        'PROCESOS PRIORITARIOS' => self::E5_PROCESOS_PRIORITARIOS,
        'HISTORIA CLINICA Y REGISTROS' => self::E6_HISTORIA_CLINICA,
        'INTERDEPENDENCIA' => self::E7_INTERDEPENDENCIA,
        'INTERDEPENDENCIA DE SERVICIOS' => self::E7_INTERDEPENDENCIA,
        'SERVICIOS HABILITADOS NO PRESTADOS' => self::E0_SERVICIOS_NO_PRESTADOS,

        // Encabezados abreviados de la hoja RESULTADOS
        'INTERDEP DE SERV' => self::E7_INTERDEPENDENCIA,
        'H CLINICA Y REGISTROS' => self::E6_HISTORIA_CLINICA,
        'MEDICAMENTOS DM E INSUMOS' => self::E4_MEDICAMENTOS,
    ];

    /**
     * Rótulos que aparecen en la columna de estándar pero NO son estándares de
     * habilitación: encabezan las secciones II y III de la matriz, que quedan
     * fuera del conteo. Se reconocen para poder ignorarlos sin rechazar el
     * archivo — que es distinto de no conocerlos.
     */
    private const FUERA_DE_ALCANCE = [
        'CONDICIONES TECNICO ADMINISTRATIVAS',
        'CAPACIDAD DE SUFICIENCIA PATRIMONIAL FINANCIERA',
        'CAPACIDAD DE SUFICIENCIA PATRIMONIAL Y FINANCIERA',
        'ESTANDAR',
    ];

    /** Devuelve el código canónico, o null si el texto no está en el catálogo. */
    public static function resolver(?string $texto): ?string
    {
        $clave = TextoNormalizador::canonica($texto);

        if ($clave === '') {
            return null;
        }

        if (isset(self::ALIAS[$clave])) {
            return self::ALIAS[$clave];
        }

        // Los encabezados de la hoja RESULTADOS llevan el ordinal delante
        // («1.Talento Humano», «3. Dotación»). Quitarlo es normalización, no
        // emparejamiento aproximado: la búsqueda que sigue es igual de exacta.
        $sinOrdinal = preg_replace('/^\d{1,2} /', '', $clave) ?? $clave;

        return self::ALIAS[$sinOrdinal] ?? null;
    }

    /** ¿Es un rótulo conocido que debe ignorarse en vez de rechazarse? */
    public static function estaFueraDeAlcance(?string $texto): bool
    {
        return in_array(TextoNormalizador::canonica($texto), self::FUERA_DE_ALCANCE, true);
    }

    /** ¿El texto es reconocible de algún modo, sea estándar o rótulo excluido? */
    public static function esConocido(?string $texto): bool
    {
        return self::resolver($texto) !== null || self::estaFueraDeAlcance($texto);
    }

    public static function nombre(string $codigo): string
    {
        return self::ESTANDARES[$codigo][0]
            ?? throw new \InvalidArgumentException("Estándar desconocido: {$codigo}");
    }

    public static function orden(string $codigo): int
    {
        return self::ESTANDARES[$codigo][1] ?? 99;
    }

    public static function esNormativo(string $codigo): bool
    {
        return self::ESTANDARES[$codigo][2] ?? false;
    }

    /** @return array<string, array{0:string,1:int,2:bool}> */
    public static function todos(): array
    {
        return self::ESTANDARES;
    }

    /** @return array<string, string> alias normalizado => código */
    public static function alias(): array
    {
        return self::ALIAS;
    }

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_keys(self::ESTANDARES);
    }
}
