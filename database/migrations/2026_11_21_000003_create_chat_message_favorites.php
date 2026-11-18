<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensajes favoritos por usuario. Es una marca personal: cada persona ve
     * sus propios favoritos, aunque el mensaje sea de un grupo.
     */
    public function up(): void
    {
        Schema::create('chat_message_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['message_id', 'user_id'], 'chat_message_favorites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_favorites');
    }
};
