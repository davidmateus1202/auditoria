<?php

declare(strict_types=1);

namespace App\Services\Auditorias\Excepciones;

use RuntimeException;

/**
 * El archivo no dice ninguna sede que el catálogo reconozca.
 *
 * Pasa con plantillas nuevas o con sedes que todavía no se han registrado.
 * Se distingue de un archivo mal formado (EstructuraInvalida): aquí el
 * archivo se leyó bien, solo falta decidir a qué sede pertenece, y esa
 * decisión la puede tomar quien sube el archivo sin tocar el Excel.
 */
final class SedeNoIdentificada extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly ?string $textoEncontrado = null,
    ) {
        parent::__construct($mensaje);
    }

    /** Para devolverlo como cuerpo de un 422 sin perder el contexto. */
    public function contexto(): array
    {
        return array_filter([
            'mensaje' => $this->getMessage(),
            'tipo' => 'sede_no_identificada',
            'texto_encontrado' => $this->textoEncontrado,
        ], static fn ($v) => $v !== null);
    }
}
