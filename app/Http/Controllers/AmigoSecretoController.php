<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Conversation;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat privado del amigo secreto.
 *
 * El nombre del asignado nunca se expone: la conversación se titula
 * "Amigo secreto" y solo se muestra si está en línea. La administración
 * puede moderar todas las conversaciones.
 */
class AmigoSecretoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $event = $this->activeEvent();

        if ($event === null || $event->status->isFinished()) {
            return api_success('Sin evento activo.', ['items' => [], 'active' => false]);
        }

        $assignments = Assignment::query()
            ->where('event_id', $event->id)
            ->where(function ($query) use ($user) {
                $query->where('giver_id', $user->id)->orWhere('receiver_id', $user->id);
            })
            ->with(['giver', 'receiver'])
            ->get();

        $items = $assignments->map(function (Assignment $assignment) use ($user, $event) {
            $conversation = Conversation::query()
                ->where('assignment_id', $assignment->id)
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::create([
                    'type' => 'secret',
                    'created_by' => $user->id,
                    'event_id' => $event->id,
                    'assignment_id' => $assignment->id,
                    'is_moderated' => true,
                ]);

                $conversation->users()->attach([
                    $assignment->giver_id,
                    $assignment->receiver_id,
                ]);
            }

            $other = $assignment->giver_id === $user->id ? $assignment->receiver : $assignment->giver;
            $online = $other?->last_seen_at !== null && $other->last_seen_at->gte(now()->subMinutes(5));

            return [
                'conversation_id' => $conversation->id,
                'title' => 'Amigo secreto',
                'online' => $online,
                'unread' => 0,
            ];
        });

        return api_success('Amigo secreto.', ['items' => $items]);
    }

    public function abrir(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();

        if ($assignment->giver_id !== $user->id && $assignment->receiver_id !== $user->id) {
            abort(403);
        }

        $event = Event::query()->find($assignment->event_id);

        if ($event === null || $event->status->isFinished()) {
            return api_error('El evento ya se completó; este chat está desactivado.', 409);
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['assignment_id' => $assignment->id],
            [
                'type' => 'secret',
                'created_by' => $user->id,
                'event_id' => $assignment->event_id,
                'is_moderated' => true,
            ]
        );

        $conversation->users()->syncWithoutDetaching([$assignment->giver_id, $assignment->receiver_id]);

        return api_success('Conversación abierta.', ['conversation' => ['id' => $conversation->id, 'title' => 'Amigo secreto']]);
    }

    private function activeEvent(): ?Event
    {
        return Event::query()
            ->where('year', now()->year)
            ->where('status', '<>', 'cancelled')
            ->latest('id')
            ->first();
    }
}
