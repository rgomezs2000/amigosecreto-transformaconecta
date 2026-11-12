<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Etiquetas del chat: @usuario, @Administrador, @everyone, @here y el
 * seudónimo del amigo secreto.
 *
 * La notificación de una etiqueta se crea aunque la conversación esté
 * silenciada; el silencio solo frena las notificaciones normales.
 */
class ChatMentions
{
    public const SECRET_ALIAS = 'AmigoSecreto';

    public static function handle(ChatMessage $message, Conversation $conversation, User $sender): void
    {
        $body = strip_tags((string) $message->body);

        if (! preg_match_all('/@([\p{L}\p{N}_.-]+)/u', $body, $matches)) {
            return;
        }

        $tokens = array_values(array_unique(array_map(
            fn (string $token) => mb_strtolower($token),
            $matches[1]
        )));

        $recipients = collect();

        foreach ($tokens as $token) {
            if (in_array($token, ['everyone', 'here'], true)) {
                if ($conversation->type === 'group') {
                    $recipients = $recipients->merge(
                        $conversation->users->where('id', '!=', $sender->id)
                    );

                    self::checkSpam($message, $conversation, $sender);
                }

                continue;
            }

            if ($token === 'administrador') {
                $recipients = $recipients->merge(
                    User::query()->where('role', 'admin')->where('is_active', true)->get()
                );

                continue;
            }

            if ($conversation->type === 'secret') {
                if (mb_strtolower(self::SECRET_ALIAS) === $token) {
                    $recipients = $recipients->merge(
                        $conversation->users->where('id', '!=', $sender->id)
                    );
                }

                continue;
            }

            $user = User::query()->where('username', $token)->first();

            if ($user !== null && $conversation->users->contains('id', $user->id)) {
                $recipients->push($user);
            }
        }

        $recipients
            ->filter()
            ->unique('id')
            ->where('id', '!=', $sender->id)
            ->each(function (User $recipient) use ($message, $conversation, $sender) {
                // En el amigo secreto no se revela quién etiqueta: el aviso
                // mantiene el anonimato de la asignación.
                $title = $conversation->type === 'secret'
                    ? 'Tu amigo secreto te etiquetó en el chat'
                    : $sender->name . ' te etiquetó en el chat';

                $recipient->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'chat.mention',
                    'data' => [
                        'title' => $title,
                        'body' => Str::limit(strip_tags((string) $message->body), 120),
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                    ],
                    'sound' => true,
                    'visual' => true,
                ]);
            });
    }

    private static function checkSpam(ChatMessage $message, Conversation $conversation, User $sender): void
    {
        $recent = $conversation->messages()
            ->where('user_id', $sender->id)
            ->where('id', '>=', $message->id - 20)
            ->where(function ($query) {
                $query->where('body', 'like', '%@everyone%')
                    ->orWhere('body', 'like', '%@here%');
            })
            ->count();

        if ($recent <= 10) {
            return;
        }

        ChatReport::query()->firstOrCreate(
            ['message_id' => $message->id, 'reporter_id' => $sender->id],
            ['reason' => 'Posible spam: más de 10 mensajes con @everyone o @here.']
        );

        User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get()
            ->each(function (User $admin) use ($message, $conversation) {
                $admin->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'chat.spam',
                    'data' => [
                        'title' => 'Posible spam en el chat',
                        'body' => 'Se detectaron más de 10 etiquetas @everyone o @here seguidas.',
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                    ],
                    'sound' => true,
                    'visual' => true,
                ]);
            });
    }
}
