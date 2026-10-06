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
    /**
     * @param  list<array{tipo:string, hoja:string, celda:string, fila:int, encontrado:string, sugerencia:?string}>  $problemas
     */
    public function __construct(
        string $mensaje,
        public readonly ?string $hoja = null,
        public readonly ?int $fila = null,
        public readonly ?string $encontrado = null,
        public readonly ?string $esperado = null,
        public readonly array $problemas = [],
    ) {
        parent::__construct($mensaje);
    }

    /**
     * Todas las celdas que no coinciden con el catálogo, de una vez: rechazar
     * por la primera obligaba a corregir, subir, fallar en la siguiente, y
     * así una por una.
     *
     * @param  list<array{tipo:string, hoja:string, celda:string, fila:int, encontrado:string, sugerencia:?string}>  $problemas
     */
    public static function celdasNoReconocidas(array $problemas): self
    {
        $primero = $problemas[0];
        $total = count($problemas);

        $mensaje = $total === 1
            ? "Estándar no reconocido en «{$primero['hoja']}», celda {$primero['celda']}: «{$primero['encontrado']}»."
            : "Hay {$total} celdas con un estándar que no coincide con el catálogo.";

        return new self(
            $mensaje.' Corríjalas en la vista previa del archivo y vuelva a cargarlo.',
            hoja: $primero['hoja'],
            fila: $primero['fila'],
            encontrado: $primero['encontrado'],
            problemas: $problemas,
        );
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
            // Todo error de estructura se puede revisar —y casi siempre
            // corregir— desde la vista previa, sin salir del sistema.
            'tipo' => $this->problemas === [] ? 'estructura_invalida' : 'celdas_no_reconocidas',
            'hoja' => $this->hoja,
            'fila' => $this->fila,
            'encontrado' => $this->encontrado,
            'esperado' => $this->esperado,
            'problemas' => $this->problemas === [] ? null : $this->problemas,
        ], static fn ($v) => $v !== null);
    }
}
