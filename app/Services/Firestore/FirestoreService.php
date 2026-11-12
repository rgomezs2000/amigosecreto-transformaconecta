<?php

namespace App\Services\Firestore;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Espejo NoSQL de la mensajería del sistema.
 *
 * Guarda en Firestore exactamente lo que viaja por push, correo y chat:
 *
 *  - notificaciones_push : cada alerta push/visual de un usuario.
 *  - correos             : cada correo enviado, fallido u omitido.
 *  - conversaciones      : conversaciones directas, grupos y moderación.
 *  - mensajes_chat       : cada mensaje del chat.
 *  - reportes_chat       : cada reporte hecho sobre un mensaje.
 *
 * Nada más se escribe en Firestore. Si Firebase falla, se registra el error y
 * la operación SQL continúa con normalidad.
 */
class FirestoreService
{
    public const PUSH = 'notificaciones_push';

    public const EMAILS = 'correos';

    public const CONVERSATIONS = 'conversaciones';

    public const MESSAGES = 'mensajes_chat';

    public const REPORTS = 'reportes_chat';

    public function __construct(private readonly FirestoreGateway $gateway) {}

    public function gateway(): FirestoreGateway
    {
        return $this->gateway;
    }

    public function enabled(): bool
    {
        return $this->gateway->enabled();
    }

    /**
     * @return array{enabled:bool,driver:string,project:?string,last_error:?string}
     */
    public function status(): array
    {
        return [
            'enabled' => $this->gateway->enabled(),
            'driver' => $this->gateway->driver(),
            'project' => $this->gateway->project(),
            'last_error' => $this->gateway->lastError(),
        ];
    }

    /**
     * @param  array<int, array{notification: Notification, user: User}>  $items
     */
    public function recordPushes(array $items): bool
    {
        if ($items === []) {
            return true;
        }

        $documents = [];

        foreach ($items as $item) {
            $notification = $item['notification'];
            $user = $item['user'];

            $documents[] = [
                'collection' => self::PUSH,
                'id' => (string) $notification->id,
                'data' => $this->pushPayload($notification, $user),
            ];
        }

        return $this->writeMany($documents);
    }

    public function markPushRead(Notification $notification): bool
    {
        return $this->write('update', self::PUSH, (string) $notification->id, [
            'read_at' => $notification->read_at?->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Marca varias notificaciones como leídas en un solo lote.
     *
     * @param  iterable<int, Notification>  $notifications
     */
    public function markPushesRead(iterable $notifications): bool
    {
        $readAt = now()->toIso8601String();
        $documents = [];

        foreach ($notifications as $notification) {
            $documents[] = [
                'collection' => self::PUSH,
                'id' => (string) $notification->id,
                'data' => ['read_at' => $readAt, 'updated_at' => $readAt],
            ];
        }

        if ($documents === []) {
            return true;
        }

        if (! $this->gateway->enabled()) {
            return false;
        }

        try {
            return $this->gateway->updateMany($documents);
        } catch (Throwable $exception) {
            Log::warning('Firestore: no se pudo marcar las notificaciones como leídas', [
                'documents' => count($documents),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    public function recordEmails(array $payloads): bool
    {
        if ($payloads === []) {
            return true;
        }

        $documents = [];

        foreach ($payloads as $payload) {
            $id = (string) ($payload['id'] ?? '');

            if ($id === '') {
                continue;
            }

            unset($payload['id']);

            $documents[] = [
                'collection' => self::EMAILS,
                'id' => $id,
                'data' => array_merge($payload, [
                    'synced_at' => now()->toIso8601String(),
                ]),
            ];
        }

        return $this->writeMany($documents);
    }

    public function recordConversation(Conversation $conversation): bool
    {
        return $this->write('set', self::CONVERSATIONS, (string) $conversation->id, $this->conversationPayload($conversation));
    }

    /**
     * Sincroniza varias conversaciones (carga inicial del historial).
     *
     * @param  iterable<int, Conversation>  $conversations
     */
    public function recordConversations(iterable $conversations): int
    {
        $documents = [];

        foreach ($conversations as $conversation) {
            $documents[] = [
                'collection' => self::CONVERSATIONS,
                'id' => (string) $conversation->id,
                'data' => $this->conversationPayload($conversation),
            ];
        }

        return $this->writeMany($documents) ? count($documents) : 0;
    }

    public function recordConversationActivity(Conversation $conversation, ChatMessage $message, ?string $sender): bool
    {
        return $this->write('update', self::CONVERSATIONS, (string) $conversation->id, [
            'last_message' => [
                'id' => (int) $message->id,
                'body' => $this->preview($message),
                'sender' => $sender,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ]);
    }

    public function recordMessage(ChatMessage $message): bool
    {
        return $this->write('set', self::MESSAGES, (string) $message->id, $this->messagePayload($message));
    }

    /**
     * @param  iterable<int, ChatMessage>  $messages
     */
    public function recordMessages(iterable $messages): int
    {
        $documents = [];

        foreach ($messages as $message) {
            $documents[] = [
                'collection' => self::MESSAGES,
                'id' => (string) $message->id,
                'data' => $this->messagePayload($message),
            ];
        }

        return $this->writeMany($documents) ? count($documents) : 0;
    }

    public function recordReport(ChatReport $report): bool
    {
        return $this->write('set', self::REPORTS, (string) $report->id, $this->reportPayload($report));
    }

    /**
     * @param  iterable<int, ChatReport>  $reports
     */
    public function recordReports(iterable $reports): int
    {
        $documents = [];

        foreach ($reports as $report) {
            $documents[] = [
                'collection' => self::REPORTS,
                'id' => (string) $report->id,
                'data' => $this->reportPayload($report),
            ];
        }

        return $this->writeMany($documents) ? count($documents) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function pushPayload(Notification $notification, User $user): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'notification_id' => (string) $notification->id,
            'user_id' => (int) $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'type' => (string) $notification->type,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'url' => $notification->link(),
            'source' => $notification->source,
            'sound' => (bool) $notification->sound,
            'visual' => (bool) $notification->visual,
            'read_at' => $notification->read_at?->toIso8601String(),
            'payload' => $data,
            'created_at' => $notification->created_at?->toIso8601String(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(Conversation $conversation): array
    {
        $conversation->loadMissing('users');
        $last = $conversation->messages()->with('user')->latest('id')->first();

        $participants = $conversation->users
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'role' => (string) ($user->pivot->role ?? 'member'),
                'last_read_at' => $this->toIsoDate($user->pivot->last_read_at),
                'muted_at' => $this->toIsoDate($user->pivot->muted_at),
            ])
            ->values()
            ->all();

        return [
            'conversation_id' => (int) $conversation->id,
            'type' => (string) $conversation->type,
            'name' => $conversation->name,
            'is_group' => $conversation->isGroup(),
            'is_moderated' => (bool) $conversation->is_moderated,
            'created_by' => $conversation->created_by !== null ? (int) $conversation->created_by : null,
            'participants' => $participants,
            'last_message' => $last === null ? null : [
                'id' => (int) $last->id,
                'body' => $this->preview($last),
                'sender' => $last->is_moderation ? 'Moderación' : ($last->user?->name ?? 'Sistema'),
                'created_at' => $last->created_at?->toIso8601String(),
            ],
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(ChatMessage $message): array
    {
        $message->loadMissing('user', 'attachments', 'conversation');

        $deleted = $message->isDeleted();

        return [
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'conversation_type' => $message->conversation?->type,
            'user_id' => (int) $message->user_id,
            'sender' => $message->is_moderation ? 'Moderación' : ($message->user?->name ?? 'Sistema'),
            'body' => $deleted ? '' : $this->cleanBody($message->body),
            'type' => (string) $message->type,
            'is_moderation' => (bool) $message->is_moderation,
            'deleted' => $deleted,
            'deleted_by_admin' => (bool) $message->deleted_by_admin,
            'link_url' => $message->link_url,
            'latitude' => $message->latitude !== null ? (float) $message->latitude : null,
            'longitude' => $message->longitude !== null ? (float) $message->longitude : null,
            'attachments' => $message->attachments
                ->map(fn ($attachment) => [
                    'type' => (string) $attachment->type,
                    'name' => (string) $attachment->name,
                    'url' => asset('storage/'.$attachment->path),
                ])
                ->values()
                ->all(),
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(ChatReport $report): array
    {
        $report->loadMissing('message.conversation', 'message.user', 'reporter');

        $message = $report->message;
        $conversation = $message?->conversation;

        return [
            'report_id' => (int) $report->id,
            'message_id' => (int) $report->message_id,
            'conversation_id' => $conversation !== null ? (int) $conversation->id : null,
            'conversation_type' => $conversation?->type,
            'reporter_id' => (int) $report->reporter_id,
            'reporter_name' => $report->reporter?->name,
            'reported_user_id' => $message?->user_id !== null ? (int) $message->user_id : null,
            'reported_user_name' => $message?->user?->name,
            'reason' => (string) $report->reason,
            'message_body' => $message !== null ? $this->cleanBody($message->body) : null,
            'status' => 'pendiente',
            'created_at' => $report->created_at?->toIso8601String(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function preview(ChatMessage $message): string
    {
        $body = $this->cleanBody($message->body);

        if ($body !== '') {
            return mb_substr($body, 0, 160);
        }

        return match ($message->type) {
            'image' => 'Foto',
            'video' => 'Video',
            'audio' => 'Audio',
            'pdf' => 'PDF',
            'contact' => 'Contacto',
            'location' => 'Ubicación',
            default => 'Archivo',
        };
    }

    private function cleanBody(?string $body): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $body)));
    }

    /**
     * Convierte fechas de SQL (Carbon o texto del pivote) a ISO-8601.
     */
    private function toIsoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $mode, string $collection, string $id, array $data): bool
    {
        if (! $this->gateway->enabled()) {
            return false;
        }

        try {
            return $mode === 'update'
                ? $this->gateway->update($collection, $id, $data)
                : $this->gateway->set($collection, $id, $data);
        } catch (Throwable $exception) {
            Log::warning('Firestore: no se pudo sincronizar '.$collection.'/'.$id, [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<int, array{collection:string,id:string,data:array<string,mixed>}>  $documents
     */
    private function writeMany(array $documents): bool
    {
        if ($documents === []) {
            return true;
        }

        if (! $this->gateway->enabled()) {
            return false;
        }

        try {
            return $this->gateway->setMany($documents);
        } catch (Throwable $exception) {
            Log::warning('Firestore: no se pudo sincronizar el lote', [
                'documents' => count($documents),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
