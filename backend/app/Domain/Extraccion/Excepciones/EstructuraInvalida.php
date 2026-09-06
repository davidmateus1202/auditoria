<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Excepciones;

use RuntimeException;

/**
 * El archivo no tiene la estructura esperada.
 *
 * La plantilla de la E.S.E. es fija, así que el extractor no se adapta: verifica
 * y rechaza. Un archivo mal leído en silencio produce cifras equivocadas que
 * nadie detecta; uno rechazado produce una llamada de dos minutos.
 */
final class EstructuraInvalida extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly ?string $hoja = null,
        public readonly ?int $fila = null,
        public readonly ?string $encontrado = null,
        public readonly ?string $esperado = null,
    ) {
        parent::__construct($mensaje);
    }

    public static function encabezadoNoEncontrado(string $hoja, string $esperado): self
    {
        return new self(
            "No se encontró la fila de encabezados en la hoja «{$hoja}». ".
            "Se esperaban las columnas: {$esperado}.",
            hoja: $hoja,
            esperado: $esperado,
        );
    }

    public static function estandarDesconocido(string $hoja, int $fila, string $encontrado): self
    {
        return new self(
            "Estándar no reconocido en «{$hoja}», fila {$fila}: «{$encontrado}». ".
            'Si es un estándar nuevo debe agregarse al catálogo antes de cargar el archivo.',
            hoja: $hoja,
            fila: $fila,
            encontrado: $encontrado,
        );
    }

    public static function hojaAusente(string $hoja): self
    {
        return new self("El archivo no contiene la hoja «{$hoja}».", hoja: $hoja);
    }

    public static function formatoNoReconocido(string $detalle): self
    {
        return new self("No se pudo determinar el tipo de archivo. {$detalle}");
    }

    /** Para devolverlo como cuerpo de un 422 sin perder el contexto. */
    public function contexto(): array
    {
        return array_filter([
            'mensaje' => $this->getMessage(),
            'hoja' => $this->hoja,
            'fila' => $this->fila,
            'encontrado' => $this->encontrado,
            'esperado' => $this->esperado,
        ], static fn ($v) => $v !== null);
    }
}
