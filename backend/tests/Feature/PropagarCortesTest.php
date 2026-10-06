<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\EstadoCorte;
use App\Domain\Enums\EstadoHallazgo;
use App\Domain\Extraccion\CatalogoEstandares;
use App\Models\Corte;
use App\Models\Hallazgo;
use App\Models\HallazgoEstadoCorte;
use App\Models\Sede;
use App\Services\Consolidado\ServicioConsolidado;
use App\Services\Cortes\ServicioCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Una línea base que entra en un mes anterior a otro ya abierto: el caso que
 * dejó septiembre con los estados de antes de cargar enero.
 */
class PropagarCortesTest extends TestCase
{
    use RefreshDatabase;

    private ServicioCorte $cortes;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogoSeeder::class);
        $this->cortes = new ServicioCorte();
        $this->sede = Sede::query()->firstOrFail();
    }

    private function hallazgo(string $nombre): Hallazgo
    {
        return Hallazgo::create([
            'sede_id' => $this->sede->id,
            'estandar_codigo' => CatalogoEstandares::E2_INFRAESTRUCTURA,
            'descripcion' => $nombre,
            'huella' => sha1($nombre),
            'estado' => EstadoHallazgo::Abierto,
        ]);
    }

    /** Lo que deja ImportadorLineaBase: foto en el corte y caché del hallazgo. */
    private function lineaBase(Corte $corte, Hallazgo $hallazgo, EstadoHallazgo $estado, ?string $accion = null): void
    {
        HallazgoEstadoCorte::create([
            'hallazgo_id' => $hallazgo->id,
            'corte_id' => $corte->id,
            'estado' => $estado,
            'presente_en_corte' => true,
            'meses_abierto' => $estado->esVigente() ? 1 : 0,
            'accion_propuesta' => $accion,
        ]);
        $hallazgo->update(['estado' => $estado, 'accion_propuesta' => $accion]);
    }

    private function fotoDe(Corte $corte, Hallazgo $hallazgo): ?HallazgoEstadoCorte
    {
        return $corte->estados()->where('hallazgo_id', $hallazgo->id)->first();
    }

    #[Test]
    public function la_linea_base_anterior_rehace_lo_no_reportado_del_mes_abierto(): void
    {
        [$cerrado, $conEvidencia, $sigue, $reportado] = array_map(
            fn ($n) => $this->hallazgo($n),
            ['cerrado', 'con evidencia', 'sigue', 'reportado'],
        );

        // Septiembre se abre primero y alguien reporta uno de los hallazgos.
        $septiembre = $this->cortes->abrir('2026-09');
        $this->fotoDe($septiembre, $reportado)->update([
            'estado' => EstadoHallazgo::AbiertoConEvidencia,
            'presente_en_corte' => true,
        ]);

        // Después entra la línea base en enero, con un hallazgo que septiembre no conocía.
        $enero = Corte::create(['periodo' => '2026-01', 'fecha_corte' => '2026-01-01', 'estado' => EstadoCorte::Abierto]);
        $soloEnero = $this->hallazgo('solo enero');
        $this->lineaBase($enero, $cerrado, EstadoHallazgo::Cerrado);
        $this->lineaBase($enero, $conEvidencia, EstadoHallazgo::AbiertoConEvidencia, 'Cambiar la puerta');
        $this->lineaBase($enero, $sigue, EstadoHallazgo::Abierto);
        $this->lineaBase($enero, $reportado, EstadoHallazgo::Cerrado);
        $this->lineaBase($enero, $soloEnero, EstadoHallazgo::Abierto);

        $this->assertSame(['2026-09'], $this->cortes->propagarAPosteriores($enero));

        // Lo cerrado en enero sale del arrastre; lo demás toma el estado de enero.
        $this->assertNull($this->fotoDe($septiembre, $cerrado));
        $foto = $this->fotoDe($septiembre, $conEvidencia);
        $this->assertSame(EstadoHallazgo::AbiertoConEvidencia, $foto->estado);
        $this->assertSame('Cambiar la puerta', $foto->accion_propuesta);
        $this->assertSame(2, $foto->meses_abierto);
        $this->assertFalse($foto->presente_en_corte);
        $this->assertSame(2, $this->fotoDe($septiembre, $soloEnero)->meses_abierto);

        // Lo que alguien ya reportó en septiembre no se pisa.
        $foto = $this->fotoDe($septiembre, $reportado);
        $this->assertSame(EstadoHallazgo::AbiertoConEvidencia, $foto->estado);
        $this->assertTrue($foto->presente_en_corte);

        // El consolidado de septiembre cuenta el cierre de enero.
        $generales = (new ServicioConsolidado())->generar('2026-09')->generales;
        $this->assertSame(5, $generales['hallazgos']);
        $this->assertSame(1, $generales['cerrados']);
    }

    #[Test]
    public function un_mes_cerrado_posterior_no_se_toca(): void
    {
        $hallazgo = $this->hallazgo('uno');
        $this->cortes->abrir('2026-09');
        $this->cortes->cerrar('2026-09');
        $septiembre = Corte::where('periodo', '2026-09')->firstOrFail();

        $enero = Corte::create(['periodo' => '2026-01', 'fecha_corte' => '2026-01-01', 'estado' => EstadoCorte::Abierto]);
        $this->lineaBase($enero, $hallazgo, EstadoHallazgo::Cerrado);

        $this->assertSame([], $this->cortes->propagarAPosteriores($enero));
        $this->assertSame(EstadoHallazgo::Abierto, $this->fotoDe($septiembre, $hallazgo)->estado);
    }
}
