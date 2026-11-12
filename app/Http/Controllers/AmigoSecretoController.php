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
 * Cada asignación tiene su propio chat. El usuario ve dos: el suyo como
 * remitente (ahí sí ve a quién le tocó) y el que recibe (ahí el amigo secreto
 * sigue siendo anónimo). La administración puede moderar estas conversaciones.
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

            // Quien regala sabe a quién le tocó; quien recibe no sabe quién es.
            $isGiver = (int) $assignment->giver_id === (int) $user->id;
            $other = $isGiver ? $assignment->receiver : $assignment->giver;
            $online = $other?->last_seen_at !== null && $other->last_seen_at->gte(now()->subMinutes(5));

            return [
                'conversation_id' => $conversation->id,
                'title' => $isGiver
                    ? 'Amigo secreto: '.($other?->name ?? 'asignado')
                    : 'Amigo secreto',
                'label' => $isGiver ? 'Tu asignado' : 'Incógnito',
                'role' => $isGiver ? 'giver' : 'receiver',
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

        $conversation->load('users', 'assignment.receiver');

        return api_success('Conversación abierta.', [
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->titleFor($user),
            ],
        ]);
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
