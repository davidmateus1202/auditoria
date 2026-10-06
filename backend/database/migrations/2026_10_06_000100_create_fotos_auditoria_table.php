<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de evidencia que traen las autoevaluaciones en sus hojas de registro
 * fotográfico (FOTOS, EVIDENCIAS, FOTOGRAFIAS…).
 *
 * Se guardan aparte de la tabla `evidencias`, que es la constancia de un
 * hallazgo concreto: estas fotos son de la visita a la sede y el archivo no
 * dice a qué hallazgo corresponde cada una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos_auditoria', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('auditoria_id')->constrained('auditorias')->cascadeOnDelete();
            $tabla->string('hoja', 100);
            $tabla->string('celda', 12)->nullable();
            $tabla->unsignedSmallInteger('orden');
            $tabla->string('ruta', 255);
            $tabla->string('ruta_miniatura', 255);
            $tabla->string('mime', 50);
            $tabla->unsignedInteger('bytes');
            $tabla->unsignedSmallInteger('ancho')->nullable();
            $tabla->unsignedSmallInteger('alto')->nullable();
            // La misma imagen pegada dos veces en la hoja es una sola foto.
            $tabla->char('hash_sha256', 64);
            $tabla->timestamps();

            $tabla->unique(['auditoria_id', 'hash_sha256']);
            $tabla->index(['auditoria_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_auditoria');
    }
};
