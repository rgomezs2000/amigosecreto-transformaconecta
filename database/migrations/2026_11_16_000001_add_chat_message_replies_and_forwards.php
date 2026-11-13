<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas y reenvíos del chat.
 *
 * - reply_to_id:      mensaje al que responde esta burbuja. La cita se pinta
 *                     dentro de la burbuja que envía la respuesta.
 * - forwarded_from_id: mensaje original que se reenvió. Sirve para marcar la
 *                     burbuja como «Reenviado» aunque se reenvíe a varias
 *                     conversaciones a la vez.
 *
 * Ambas son referencias al propio historial: si el mensaje original se borra
 * de verdad (por ejemplo al eliminar la conversación), la referencia queda en
 * null y la burbuja sigue siendo válida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('user_id')
                ->constrained('chat_messages')
                ->nullOnDelete();

            $table->foreignId('forwarded_from_id')
                ->nullable()
                ->after('reply_to_id')
                ->constrained('chat_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_from_id');
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
