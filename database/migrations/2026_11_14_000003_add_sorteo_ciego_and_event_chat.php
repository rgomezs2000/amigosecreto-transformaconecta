<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Piezas del sorteo ciego:
     *
     * - `chat_conversations.is_read_only`: marca el chat del evento, donde solo
     *   se publican avisos y nadie escribe, ni reacciona, ni reporta.
     * - `events.draw_closed_at`: cuándo se cerró el sorteo por haber elegido
     *   todos los participantes (queda como historial del avance de fase).
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->boolean('is_read_only')->default(false)->after('is_moderated');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('draw_closed_at')->nullable()->after('exchange_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('is_read_only');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('draw_closed_at');
        });
    }
};
