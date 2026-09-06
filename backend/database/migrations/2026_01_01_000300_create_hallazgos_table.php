<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El hallazgo y su linaje.
 *
 * `hallazgos` cuelga de SEDE, no de auditoría: es lo que permite que un
 * problema sobreviva a varias auditorías y que cerrarlo tenga sentido.
 * `auditoria_origen_id` recuerda dónde nació.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hallazgos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $tabla->char('estandar_codigo', 2);
            $tabla->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $tabla->foreignId('auditoria_origen_id')->nullable()->constrained('auditorias')->nullOnDelete();

            $tabla->text('descripcion');
            $tabla->char('huella', 40);
            $tabla->enum('clasificacion', ['hallazgo', 'cumple', 'sin_dato', 'dudoso'])->default('hallazgo');

            // Caché del último corte cerrado. El estado real por mes vive en
            // hallazgo_estado_corte; esta columna solo evita consultar el
            // histórico en cada listado.
            $tabla->enum('estado', ['abierto', 'abierto_evidencia', 'cerrado', 'sin_dato'])->default('abierto');

            $tabla->text('accion_propuesta')->nullable();
            $tabla->string('responsable', 200)->nullable();
            $tabla->date('fecha_compromiso')->nullable();
            $tabla->text('evidencia')->nullable();

            $tabla->unsignedInteger('veces_reportado')->default(1);
            $tabla->boolean('requiere_revision')->default(false);
            $tabla->date('cerrado_en')->nullable();
            $tabla->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();

            $tabla->foreign('estandar_codigo')->references('codigo')->on('estandares');

            // Alimenta el bloqueo por sede y estándar de la reconciliación y el
            // cruce sede × estándar sin escaneo completo.
            $tabla->index(['sede_id', 'estandar_codigo', 'estado'], 'idx_hallazgo_bloqueo');
            $tabla->unique(['sede_id', 'huella'], 'uk_hallazgo_huella');
        });

        Schema::create('hallazgo_apariciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('hallazgo_id')->constrained('hallazgos')->cascadeOnDelete();
            $tabla->foreignId('auditoria_id')->constrained('auditorias')->cascadeOnDelete();
            $tabla->text('texto_reportado');
            $tabla->decimal('similitud', 4, 3)->nullable();
            $tabla->enum('vinculo', ['auto', 'manual', 'referencia'])->default('auto');
            $tabla->timestamps();

            $tabla->unique(['hallazgo_id', 'auditoria_id']);
        });

        Schema::create('seguimientos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('hallazgo_id')->constrained('hallazgos')->cascadeOnDelete();
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->enum('estado_anterior', ['abierto', 'abierto_evidencia', 'cerrado', 'sin_dato'])->nullable();
            $tabla->enum('estado_nuevo', ['abierto', 'abierto_evidencia', 'cerrado', 'sin_dato']);
            $tabla->text('accion_propuesta')->nullable();
            $tabla->string('responsable', 200)->nullable();
            $tabla->text('evidencia')->nullable();
            $tabla->string('motivo', 255)->nullable();
            $tabla->timestamps();

            $tabla->index(['hallazgo_id', 'created_at']);
        });

        Schema::create('evidencias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('hallazgo_id')->constrained('hallazgos')->cascadeOnDelete();
            $tabla->string('ruta', 255);
            $tabla->enum('tipo', ['foto', 'documento'])->default('foto');
            $tabla->string('descripcion', 255)->nullable();
            $tabla->timestamp('tomada_en')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('seguimientos');
        Schema::dropIfExists('hallazgo_apariciones');
        Schema::dropIfExists('hallazgos');
    }
};
