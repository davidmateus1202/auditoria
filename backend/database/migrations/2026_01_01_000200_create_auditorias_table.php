<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditorías, archivos cargados y las evaluaciones de criterio.
 *
 * Cada carga crea una versión nueva y conserva la anterior; solo se elimina si
 * el usuario lo pide de forma explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $tabla->unsignedInteger('version')->default(1);
            $tabla->string('periodo', 7)->nullable();          // 2026-04
            $tabla->date('fecha_auditoria')->nullable();
            $tabla->date('fecha_informe')->nullable();
            $tabla->string('auditor', 200)->nullable();
            $tabla->string('responsable', 200)->nullable();
            $tabla->enum('origen', ['autoevaluacion', 'linea_base'])->default('autoevaluacion');
            $tabla->enum('estado', ['procesando', 'por_confirmar', 'publicada', 'fallida'])->default('procesando');
            // La normativa que declara la plantilla se guarda solo como aviso:
            // el catálogo aplicado es siempre el vigente.
            $tabla->string('normativa_declarada', 60)->nullable();
            $tabla->timestamps();

            $tabla->unique(['sede_id', 'version']);
            $tabla->index(['sede_id', 'periodo']);
        });

        Schema::create('archivos_cargados', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('auditoria_id')->nullable()->constrained('auditorias')->nullOnDelete();
            $tabla->string('nombre_original', 255);
            $tabla->string('ruta', 255);
            $tabla->char('hash_sha256', 64)->unique();   // impide subir dos veces lo mismo
            $tabla->enum('formato', ['autoevaluacion', 'seguimiento']);
            $tabla->unsignedInteger('filas_leidas')->default(0);
            $tabla->json('resumen')->nullable();
            $tabla->timestamp('procesado_en')->nullable();
            $tabla->timestamps();
        });

        Schema::create('evaluaciones_criterio', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('auditoria_id')->constrained('auditorias')->cascadeOnDelete();
            $tabla->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $tabla->char('estandar_codigo', 2);
            $tabla->text('criterio');
            $tabla->enum('marca', ['C', 'NC', 'NA', ''])->default('');
            $tabla->text('observacion')->nullable();
            $tabla->string('hoja', 120)->nullable();
            $tabla->unsignedInteger('fila')->nullable();
            $tabla->timestamps();

            $tabla->foreign('estandar_codigo')->references('codigo')->on('estandares');
            $tabla->index(['auditoria_id', 'servicio_id', 'estandar_codigo'], 'idx_criterio_auditoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones_criterio');
        Schema::dropIfExists('archivos_cargados');
        Schema::dropIfExists('auditorias');
    }
};
