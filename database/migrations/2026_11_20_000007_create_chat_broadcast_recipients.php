<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destinatarios de una sala de difusión. No son miembros del chat: solo
     * reciben copia de los mensajes en su conversación directa con el dueño.
     */
    public function up(): void
    {
        Schema::create('chat_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'chat_broadcast_recipients_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_broadcast_recipients');
    }
};
