<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de lectura de la moderación.
 *
 * El administrador puede entrar a conversaciones en las que no participa,
 * así que no tiene una fila en `chat_conversation_user` donde guardar hasta
 * dónde leyó. Esta tabla guarda esa marca por administrador y conversación,
 * para poder contar los mensajes nuevos sin sumarlos al contador propio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_moderation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_moderation_reads');
    }
};
