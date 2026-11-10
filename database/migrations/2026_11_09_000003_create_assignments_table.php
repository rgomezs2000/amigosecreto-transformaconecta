<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('giver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('drawn_at');
            $table->dateTime('revealed_at')->nullable();
            $table->text('reveal_note')->nullable();
            $table->timestamps();

            // Nadie entrega dos veces y nadie recibe dos veces.
            $table->unique(['event_id', 'giver_id']);
            $table->unique(['event_id', 'receiver_id']);
        });

        // Nadie se saca a sí mismo. MySQL >= 8.0.16 y MariaDB >= 10.2 lo validan;
        // MySQL 5.7 lo ignora, por eso la garantía real está en la aplicación.
        DB::statement(
            'ALTER TABLE assignments ADD CONSTRAINT chk_assignments_not_self CHECK (giver_id <> receiver_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
