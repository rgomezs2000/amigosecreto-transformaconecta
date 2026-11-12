<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Event;

/**
 * Chat fijo del evento: un canal de solo lectura para todos los inscritos.
 *
 * Aquí solo publica el sistema (avisos de la organización). Nadie escribe,
 * nadie adjunta, nadie responde: sirve para que el evento tenga un lugar
 * común donde enterarse de en qué etapa está.
 */
class EventoChat
{
    public function abrir(Event $event): Conversation
    {
        $nombre = 'Evento: '.$event->name;

        $conversation = Conversation::query()->firstOrCreate(
            ['event_id' => $event->id, 'type' => 'event'],
            [
                'name' => $nombre,
                'is_moderated' => false,
                'is_read_only' => true,
            ]
        );

        $cambios = [];

        if (! $conversation->is_read_only) {
            $cambios['is_read_only'] = true;
        }

        if ($conversation->name !== $nombre) {
            $cambios['name'] = $nombre;
        }

        if ($cambios !== []) {
            $conversation->forceFill($cambios)->save();
        }

        $ids = $event->activeParticipants()->pluck('users.id')->all();

        if ($ids !== []) {
            $conversation->users()->syncWithoutDetaching($ids);
        }

        return $conversation;
    }

    /**
     * Publica un aviso. No se duplica: si el mismo texto ya está, lo devuelve.
     */
    public function anunciar(Event $event, string $body): ?ChatMessage
    {
        $conversation = $this->abrir($event);

        $existente = $conversation->messages()->where('body', $body)->first();

        if ($existente !== null) {
            return $existente;
        }

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'body' => $body,
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        // El último aviso queda fijado en el cintillo del chat.
        $conversation->forceFill(['pinned_message_id' => $message->id])->save();

        return $message;
    }
}
