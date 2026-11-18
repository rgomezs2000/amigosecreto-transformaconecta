<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salas cerradas, varios mensajes fijados y marca de difusión.
     *
     * Las salas son grupos con `is_closed`: se crean con usuarios elegidos, no
     * tienen enlace de invitación ni vetos y no se pueden descubrir.
     *
     * Los mensajes fijados pasan de un único `pinned_message_id` a `pinned_at`
     * en cada mensaje; la columna antigua se conserva como «último fijado»
     * para no romper el cintillo ni los clientes anteriores.
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->boolean('is_closed')->default(false)->after('is_read_only');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('edited_at');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_broadcast')->default(false)->after('type');
        });

        // El fijado que existía se convierte en el primero de la lista nueva.
        DB::table('chat_conversations')
            ->whereNotNull('pinned_message_id')
            ->orderBy('id')
            ->each(function ($conversation) {
                DB::table('chat_messages')
                    ->where('id', $conversation->pinned_message_id)
                    ->update(['pinned_at' => now()]);
            });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['pinned_by']);
            $table->dropColumn(['pinned_at', 'pinned_by', 'is_broadcast']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('is_closed');
        });
    }
};
