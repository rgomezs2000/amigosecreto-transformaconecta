<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de cada conversación desde el punto de vista de cada usuario.
 *
 * - hidden_at:   «Eliminar». La conversación sale de mi lista; al volver a
 *                abrirla con la persona se reinicia mi vista (sin duplicar).
 * - archived_at: «Archivar». Se guarda en la pestaña Archivadas y, mientras
 *                esté archivada, queda fuera del alcance de la moderación.
 * - favorite_at: «Favorito». Solo destaca para mí; sigue el orden por mensaje.
 * - pinned_at:   «Fijar». Queda primero en la lista y no se mueve.
 *
 * También se guarda el mensaje fijado dentro de la conversación, que se
 * muestra en un cintillo bajo el título.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversation_user', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('favorite_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreignId('pinned_message_id')
                ->nullable()
                ->after('id')
                ->constrained('chat_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_message_id');
        });

        Schema::table('chat_conversation_user', function (Blueprint $table) {
            $table->dropColumn(['hidden_at', 'archived_at', 'favorite_at', 'pinned_at']);
        });
    }
};
