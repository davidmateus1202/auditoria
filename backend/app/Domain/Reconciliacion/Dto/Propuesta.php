<?php

declare(strict_types=1);

namespace App\Domain\Reconciliacion\Dto;

use App\Domain\Enums\DestinoReconciliacion;

/**
 * Lo que el reconciliador propone, agrupado por destino.
 *
 * Es una propuesta, no una aplicación: los cierres y las parejas dudosas no se
 * ejecutan sin que alguien las confirme.
 */
final class Propuesta
{
    /** @var list<Movimiento> */
    public array $movimientos = [];

    public function agregar(Movimiento $movimiento): void
    {
        $this->movimientos[] = $movimiento;
    }

    /** @return list<Movimiento> */
    public function de(DestinoReconciliacion $destino): array
    {
        return array_values(array_filter(
            $this->movimientos,
            static fn (Movimiento $m): bool => $m->destino === $destino
        ));
    }

    /** @return array<string, int> */
    public function conteo(): array
    {
        $conteo = [];

        foreach (DestinoReconciliacion::cases() as $destino) {
            $conteo[$destino->value] = 0;
        }

        foreach ($this->movimientos as $movimiento) {
            $conteo[$movimiento->destino->value]++;
        }

        return array_filter($conteo);
    }

    /** Cuántas decisiones humanas quedan pendientes antes de poder confirmar. */
    public function pendientes(): int
    {
        return count(array_filter(
            $this->movimientos,
            static fn (Movimiento $m): bool => $m->destino->exigeConfirmacion()
        ));
    }

    public function resumen(): array
    {
        return [
            'movimientos' => count($this->movimientos),
            'pendientes_de_decision' => $this->pendientes(),
            'por_destino' => $this->conteo(),
        ];
    }
}
