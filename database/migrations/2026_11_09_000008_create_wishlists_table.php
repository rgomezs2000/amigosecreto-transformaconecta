<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();

            // "Lo que sí me gusta / Deseo"
            $table->text('likes')->nullable();

            // Restricciones sensoriales y alérgenos. Dato sensible: solo lo ve
            // quien regala y el administrador.
            $table->text('avoid')->nullable();

            // Categorías rápidas: café, chocolates, libros, ropa...
            $table->json('categories')->nullable();

            $table->timestamps();

            $table->unique('event_participant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
