<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('product', 150);
            $table->text('description')->nullable();
            $table->decimal('value', 10, 2);
            $table->dateTime('registered_at');
            $table->timestamps();

            // Un solo regalo de intercambio por pareja: lo garantiza la base,
            // no solo el código (doble clic, reintento, petición duplicada).
            $table->unique('assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_gifts');
    }
};
