<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Vuelve al mensaje fijado único por conversación. Si había varios, se
     * conserva el que apuntaba `pinned_message_id` (o el más reciente).
     */
    public function up(): void
    {
        DB::table('chat_conversations')
            ->orderBy('id')
            ->each(function ($conversation) {
                $keepId = $conversation->pinned_message_id;

                $keepIsValid = $keepId !== null && DB::table('chat_messages')
                    ->where('id', $keepId)
                    ->where('conversation_id', $conversation->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $keepIsValid) {
                    $keepId = DB::table('chat_messages')
                        ->where('conversation_id', $conversation->id)
                        ->whereNotNull('pinned_at')
                        ->whereNull('deleted_at')
                        ->orderByDesc('pinned_at')
                        ->orderByDesc('id')
                        ->value('id');
                }

                if ($keepId === null) {
                    return;
                }

                DB::table('chat_messages')
                    ->where('conversation_id', $conversation->id)
                    ->where('id', '!=', $keepId)
                    ->whereNotNull('pinned_at')
                    ->update(['pinned_at' => null, 'pinned_by' => null]);

                DB::table('chat_conversations')
                    ->where('id', $conversation->id)
                    ->update(['pinned_message_id' => $keepId]);
            });
    }

    public function down(): void
    {
        // No se reconstruyen los fijados descartados.
    }
};
