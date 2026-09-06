<?php

declare(strict_types=1);

namespace App\Domain\Extraccion\Dto;

use App\Domain\Enums\ClasificacionHallazgo;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\TextoNormalizador;

/**
 * Un hallazgo tal como salió del Excel, antes de tocar la base de datos.
 */
final readonly class HallazgoExtraido
{
    public function __construct(
        public string $codigoEstandar,
        public string $descripcion,
        public ClasificacionHallazgo $clasificacion,
        public string $hoja,
        public int $fila,
        public ?EstadoHallazgo $estado = null,
        public ?string $accionPropuesta = null,
        public ?string $responsable = null,
        public ?string $evidencia = null,
        public ?string $referencia = null,
        public ?string $servicio = null,
    ) {}

    public function cuenta(): bool
    {
        return $this->clasificacion->cuentaComoHallazgo();
    }

    public function requiereRevision(): bool
    {
        return $this->clasificacion->requiereRevision();
    }

    /**
     * Huella estable para deduplicar dentro de una misma carga.
     * El emparejamiento ENTRE periodos es otro problema y usa similitud de texto.
     */
    public function huella(string $codigoSede): string
    {
        return sha1(implode('|', [
            TextoNormalizador::canonica($codigoSede),
            $this->codigoEstandar,
            TextoNormalizador::canonica($this->descripcion),
        ]));
    }

    public function con(EstadoHallazgo $estado): self
    {
        return new self(
            $this->codigoEstandar, $this->descripcion, $this->clasificacion,
            $this->hoja, $this->fila, $estado, $this->accionPropuesta,
            $this->responsable, $this->evidencia, $this->referencia, $this->servicio,
        );
    }
}
