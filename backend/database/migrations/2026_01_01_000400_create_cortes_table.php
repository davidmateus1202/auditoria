<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cortes mensuales y la foto congelada de cada mes.
 *
 * El seguimiento a las sedes es mensual, así que el estado no vive en el
 * hallazgo sino en el mes. Al cerrar un corte se congela una fila por hallazgo
 * vigente: de ahí salen el consolidado fechado —el de marzo sigue dando lo
 * mismo en diciembre—, la serie mensual y la antigüedad de cada hallazgo.
 *
 * Volumen: con 10 sedes y ~250 hallazgos vigentes son unas 250 filas al mes;
 * doce años caben en 36 000. No hay que particionar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cortes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('periodo', 7)->unique();      // 2026-03
            $tabla->date('fecha_corte');
            $tabla->enum('estado', ['abierto', 'cerrado'])->default('abierto');
            $tabla->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('cerrado_en')->nullable();
            $tabla->string('motivo_reapertura', 255)->nullable();
            $tabla->timestamps();
        });

        Schema::create('hallazgo_estado_corte', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('hallazgo_id')->constrained('hallazgos')->cascadeOnDelete();
            $tabla->foreignId('corte_id')->constrained('cortes')->cascadeOnDelete();
            $tabla->enum('estado', ['abierto', 'abierto_evidencia', 'cerrado', 'sin_dato']);

            // Un hallazgo que desaparece de la matriz del mes NO se cierra:
            // conserva su estado y queda marcado para que alguien complete el dato.
            $tabla->boolean('presente_en_corte')->default(true);
            $tabla->unsignedInteger('meses_abierto')->default(0);
            $tabla->text('evidencia')->nullable();
            $tabla->text('accion_propuesta')->nullable();
            $tabla->string('responsable', 200)->nullable();

            // Huella de los campos editables al exportar: es lo que permite
            // detectar que el mismo hallazgo se tocó en la app y en el Excel.
            $tabla->char('huella_exportada', 40)->nullable();
            $tabla->timestamps();

            $tabla->unique(['hallazgo_id', 'corte_id']);
            $tabla->index(['corte_id', 'estado'], 'idx_corte_estado');
        });

        Schema::create('reconciliaciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->enum('flujo', ['vigencia', 'corte']);
            $tabla->foreignId('auditoria_id')->nullable()->constrained('auditorias')->cascadeOnDelete();
            $tabla->foreignId('corte_id')->nullable()->constrained('cortes')->cascadeOnDelete();
            $tabla->foreignId('hallazgo_id')->nullable()->constrained('hallazgos')->nullOnDelete();

            $tabla->enum('destino', [
                'persiste', 'nuevo', 'candidato_cierre',
                'no_verificado', 'ausente_corte', 'revision', 'conflicto',
            ]);
            $tabla->text('texto_entrante')->nullable();
            $tabla->decimal('similitud', 4, 3)->nullable();
            $tabla->decimal('margen', 4, 3)->nullable();
            $tabla->boolean('resuelto')->default(false);
            $tabla->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('resuelto_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['auditoria_id', 'destino']);
            $tabla->index(['corte_id', 'destino']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliaciones');
        Schema::dropIfExists('hallazgo_estado_corte');
        Schema::dropIfExists('cortes');
    }
};
