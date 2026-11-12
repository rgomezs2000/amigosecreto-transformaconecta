<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Cierre del amigo secreto.
 *
 * Cuando el evento termina (cerrado o revelado), los chats de amigo secreto
 * de ese evento pasan a la pestaña Archivadas para siempre: cada asignación
 * tiene su propio chat y ya no se vuelve a usar.
 */
class EventObserver
{
    public function updated(Event $event): void
    {
        if (! $event->wasChanged('status') || ! $event->status->isFinished()) {
            return;
        }

        $conversationIds = Conversation::query()
            ->where('event_id', $event->id)
            ->where('type', 'secret')
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return;
        }

        DB::table('chat_conversation_user')
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('archived_at')
            ->update([
                'archived_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
