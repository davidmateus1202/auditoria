<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos base: sedes, estándares, sus alias y los servicios habilitados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('codigo', 20)->unique();
            $tabla->string('nombre', 150);
            $tabla->string('municipio', 100)->default('Villavicencio');
            $tabla->string('direccion', 255)->nullable();
            $tabla->string('responsable', 150)->nullable();
            $tabla->boolean('activa')->default(true);
            $tabla->timestamps();

            $tabla->index(['activa', 'nombre']);
        });

        Schema::create('estandares', function (Blueprint $tabla) {
            $tabla->char('codigo', 2)->primary();
            $tabla->string('nombre', 120);
            $tabla->unsignedTinyInteger('orden');
            // E0 «Servicios habilitados no prestados» no es un estándar de la
            // Resolución 3100 sino una categoría propia de la E.S.E.
            $tabla->boolean('es_normativo')->default(true);
            $tabla->timestamps();
        });

        Schema::create('estandar_alias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->char('estandar_codigo', 2);
            $tabla->string('alias_normalizado', 160)->unique();
            $tabla->enum('origen', ['semilla', 'aprendido'])->default('semilla');
            $tabla->timestamps();

            $tabla->foreign('estandar_codigo')->references('codigo')->on('estandares')->cascadeOnDelete();
        });

        Schema::create('servicios', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 150)->unique();
            $tabla->string('grupo', 120)->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
        Schema::dropIfExists('estandar_alias');
        Schema::dropIfExists('estandares');
        Schema::dropIfExists('sedes');
    }
};
