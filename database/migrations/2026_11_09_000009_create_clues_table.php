<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();

            // Plantilla usada: color, gusto, adivinanza.
            $table->string('template_key', 30)->nullable();
            $table->string('body', 280);

            $table->dateTime('sent_at');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['assignment_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clues');
    }
};
