<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas del centro de ayuda y parámetros generales del sistema.
     *
     * El contenido de Acerca de, la Guía y las FAQ es estático, pero se guarda
     * en la base de datos: las vistas solo lo dibujan. Así se puede corregir o
     * ampliar sin tocar el código, y la versión del sistema queda en un único
     * lugar.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('slug', 120);
            $table->string('title', 200);
            $table->string('kind', 20)->default('section');
            $table->string('topic', 140)->nullable();
            $table->string('icon', 60)->nullable();
            // Lista de bloques: párrafo, lista, pasos, nota, datos o versión.
            $table->json('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['module_id', 'slug']);
            $table->index(['module_id', 'kind', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('app_settings');
    }
};
