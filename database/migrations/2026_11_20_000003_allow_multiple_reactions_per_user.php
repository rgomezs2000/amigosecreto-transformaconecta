<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un usuario puede dejar varias reacciones distintas en el mismo mensaje
     * (una por emoji). Antes solo se guardaba una y la nueva reemplazaba a la
     * anterior.
     */
    public function up(): void
    {
        // Primero el índice nuevo: deja `message_id` a la izquierda, que es lo
        // que necesita la clave foránea, y así se puede quitar el anterior.
        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->unique(['message_id', 'user_id']);
        });

        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'user_id', 'emoji']);
        });
    }
};
