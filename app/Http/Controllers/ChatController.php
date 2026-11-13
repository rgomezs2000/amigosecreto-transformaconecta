<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatBlock;
use App\Models\ChatGroupAudit;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatModerationRead;
use App\Models\ChatReaction;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\GroupBan;
use App\Models\GroupSuspension;
use App\Models\Module;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use App\Services\PushNotifier;
use App\Support\ChatMentions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    /**
     * Último mensaje ya consultado en esta petición, por conversación.
     *
     * @var array<int, ChatMessage|null>
     */
    private array $lastMessageCache = [];

    /**
     * Usuarios bloqueados por el usuario de la petición.
     *
     * @var array<int, array<int, int>>|null
     */
    private ?array $blockedCache = null;

    /**
     * Quiénes bloquearon al usuario de la petición.
     *
     * @var array<int, array<int, int>>|null
     */
    private ?array $blockedByCache = null;

    public function __construct(
        private readonly FirestoreService $firestore,
        private readonly PushNotifier $push,
    ) {}

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('chat');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        // Estas memorias valen solo para la petición en curso: el controlador
        // puede reutilizarse entre peticiones (pruebas u Octane) y los datos
        // de bloqueos y del último mensaje cambian.
        $this->blockedCache = null;
        $this->blockedByCache = null;
        $this->lastMessageCache = [];
    }

    public function index(): View
    {
        $this->ensureAccess();

        return view('chat.index');
    }

    public function resumen(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();
        $user->forceFill(['last_seen_at' => now()])->save();

        $conversations = Conversation::query()
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->with(['users', 'assignment.receiver', 'pinnedMessage.user'])
            ->orderByDesc('updated_at')
            ->get();

        $items = $conversations->map(fn (Conversation $conversation) => $this->conversationData($conversation, $user));

        // Las archivadas van a su propia pestaña; las eliminadas salen de la
        // lista principal salvo que tengan mensajes nuevos.
        $archived = $items->filter(fn (array $item) => $item['archived'])->values();
        $active = $items
            ->reject(fn (array $item) => $item['archived'])
            ->filter(fn (array $item) => ! $item['hidden'] || $item['unread'] > 0)
            ->values();

        $moderation = [];

        if ($user->role === UserRole::Admin) {
            $moderation = Conversation::query()
                ->with(['users', 'assignment.receiver', 'pinnedMessage.user'])
                ->whereDoesntHave('users', fn ($query) => $query->whereNotNull('archived_at'))
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Conversation $conversation) => $this->conversationData($conversation, $user, true));
        }

        // Estado de cada persona para la pestaña Usuarios: si la bloqueé, si me
        // bloqueó, si su chat está silenciado y en qué conversación va.
        $blocked = $this->blockedIds($user);
        $blockedBy = $this->blockedByIds($user);

        $directByUser = [];

        foreach ($conversations as $conversation) {
            if ($conversation->type !== 'direct' || $conversation->users->count() !== 2) {
                continue;
            }

            $other = $conversation->users->firstWhere('id', '!=', $user->id);

            if ($other !== null) {
                $directByUser[(int) $other->id] = $conversation;
            }
        }

        $users = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (User $item) use ($user, $blocked, $blockedBy, $directByUser) {
                $conversation = $directByUser[(int) $item->id] ?? null;
                $pivot = $conversation?->users->firstWhere('id', $user->id)?->pivot;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'role' => $item->role?->label() ?? 'Usuario',
                    'is_admin' => $item->role === UserRole::Admin,
                    'online' => $item->last_seen_at !== null && $item->last_seen_at->gte(now()->subMinutes(5)),
                    'blocked' => in_array((int) $item->id, $blocked, true),
                    'blocked_by' => in_array((int) $item->id, $blockedBy, true),
                    'muted' => $pivot?->muted_at !== null,
                    'conversation_id' => $conversation?->id,
                ];
            });

        return api_success('Resumen del chat.', [
            'conversations' => $this->sortedConversations($active),
            'archived' => $this->sortedConversations($archived),
            'moderation' => $moderation,
            'users' => $users,
            'unread' => $active->sum(fn (array $item) => $item['muted'] ? 0 : $item['unread']),
            'moderation_unread' => collect($moderation)->sum('unread'),
            'is_admin' => $user->role === UserRole::Admin,
        ]);
    }

    /**
     * Orden de la lista: las fijadas primero y, dentro de cada grupo, por
     * actividad (el favorito solo se destaca, no cambia el orden).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortedConversations($items): array
    {
        return $items
            ->sort(function (array $first, array $second) {
                if ($first['pinned'] !== $second['pinned']) {
                    return $first['pinned'] ? -1 : 1;
                }

                return strcmp(
                    (string) ($second['updated_iso'] ?? ''),
                    (string) ($first['updated_iso'] ?? '')
                );
            })
            ->values()
            ->all();
    }

    /**
     * Contador ligero para el botón flotante.
     *
     * Se consulta aunque el panel esté cerrado: `unread` son los mensajes
     * nuevos de mis conversaciones (grupos y directas, nunca silenciadas) y
     * `moderation` es el conteo aparte de lo que solo superviso.
     */
    public function contador(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        $unread = 0;

        $conversations = Conversation::query()
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->with('users')
            ->get();

        foreach ($conversations as $conversation) {
            $pivot = $this->pivotFor($conversation, $user);

            // Archivadas y silenciadas no cuentan para el botón flotante.
            if ($pivot?->archived_at !== null || $pivot?->muted_at !== null) {
                continue;
            }

            $unread += $this->unreadCount($conversation, $user);
        }

        $moderation = 0;

        if ($user->role === UserRole::Admin) {
            $moderation = Conversation::query()
                ->with('users')
                // Lo archivado queda fuera del alcance de la moderación.
                ->whereDoesntHave('users', fn ($query) => $query->whereNotNull('archived_at'))
                ->get()
                ->sum(fn (Conversation $conversation) => $this->moderationUnreadCount($conversation, $user));
        }

        return api_success('Contador del chat.', [
            'unread' => (int) $unread,
            'moderation' => (int) $moderation,
        ]);
    }

    public function abrir(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Selecciona un usuario.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $target = User::query()->whereKey($request->integer('user_id'))->where('is_active', true)->firstOrFail();

        if ($target->id === $user->id) {
            return api_error('No puedes abrir un chat contigo mismo.', 422);
        }

        if (ChatBlock::existsBetween($user->id, $target->id)) {
            return api_error('No puedes abrir el chat con esta persona mientras esté bloqueada.', 422);
        }

        try {
            $conversation = $this->directConversationWith($user, $target);

            $conversation->load('users');

            // Si la había eliminado, al abrirla de nuevo arranca limpia para
            // mí: la misma conversación, sin duplicarla.
            $pivot = $conversation->users->firstWhere('id', $user->id)?->pivot;

            if ($pivot !== null && $pivot->hidden_at !== null) {
                $now = now();

                $conversation->users()->updateExistingPivot($user->id, [
                    'hidden_at' => null,
                    'cleared_at' => $now,
                    'last_read_at' => $now,
                ]);

                $conversation->load('users');
            }

            $this->firestore->recordConversation($conversation);

            return api_success('Conversación abierta.', [
                'conversation' => $this->conversationData($conversation->fresh('users'), $user),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al abrir el chat', ['error' => $exception->getMessage()]);

            return api_error('No se pudo abrir el chat. Intenta de nuevo.', 500);
        }
    }

    public function grupo(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            // Sin integrantes también vale: el grupo se comparte por enlace.
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ], [
            'name.required' => 'Escribe el nombre del grupo.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        try {
            $conversation = Conversation::create([
                'name' => $validator->validated()['name'],
                'type' => 'group',
                'created_by' => $user->id,
                'is_moderated' => true,
            ]);

            $ids = collect($validator->validated()['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

            $conversation->users()->attach($user->id, ['role' => 'moderator']);

            foreach ($ids as $id) {
                if ((int) $id === (int) $user->id) {
                    continue;
                }

                $conversation->users()->attach($id, ['role' => 'member']);
            }

            $conversation->load('users');

            ChatGroupAudit::log($conversation, $user, 'grupo_creado', null, 'activo', 'Grupo creado.');

            $this->firestore->recordConversation($conversation);

            return api_success('Grupo creado.', [
                'conversation' => $this->conversationData($conversation, $user, true),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el grupo', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el grupo. Intenta de nuevo.', 500);
        }
    }

    /**
     * Mensajes de la conversación.
     *
     * Con `?after_id=N` responde de forma incremental: solo los mensajes
     * posteriores a N más una ventana corta con el estado actual de los
     * últimos, para reflejar ediciones, borrados, reacciones y confirmaciones
     * de lectura sin repintar todo el historial. Esa es la vía que usa el
     * sondeo del widget; sin `after_id` se comporta como siempre (historial
     * completo) y es la que se usa al abrir la conversación.
     *
     * Con `?ids[]=1&ids[]=2` devuelve solo esos mensajes (en `recent`), para
     * refrescar una burbuja concreta tras editarla, borrarla o reaccionar sin
     * pedir el historial ni escribir marcas de lectura.
     */
    public function mensajes(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $patchIds = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('ids', [])
        )));
        $patchOnly = $patchIds !== [];
        $afterId = max(0, (int) $request->query('after_id', 0));
        $full = $afterId === 0 && ! $patchOnly;

        $clearedAt = $conversation->users->firstWhere('id', $user->id)?->pivot->cleared_at;

        $base = function () use ($conversation, $clearedAt) {
            $query = $conversation->messages()->with(['user', 'attachments', 'replyTo.user']);

            if ($clearedAt !== null) {
                // Se incluye el mismo segundo del borrado para no ocultar mensajes nuevos.
                $query->where('created_at', '>=', $clearedAt);
            }

            return $query;
        };

        if ($full) {
            $messages = $base()->latest('id')->limit(100)->get()->reverse()->values();
        } elseif ($patchOnly) {
            $messages = collect();
        } else {
            $messages = $base()->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
        }

        // Ventana de estado: el cliente la compara con lo que ya pintó y solo
        // actualiza las burbujas que cambiaron.
        if ($full) {
            $recent = collect();
        } elseif ($patchOnly) {
            $recent = $base()->whereIn('id', $patchIds)->orderBy('id')->get();
        } else {
            $recent = $base()->latest('id')->limit(10)->get()->reverse()->values();
        }

        $pivot = $conversation->users->firstWhere('id', $user->id)?->pivot;
        $lastRead = $pivot?->last_read_at;
        $others = $conversation->users->where('id', '!=', $user->id);

        if (! $patchOnly) {
            // En reposo no se escribe nada: leer la conversación no debe golpear
            // la base de datos en cada sondeo.
            $undelivered = $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->whereNull('delivered_at')
                ->exists();

            if ($undelivered) {
                $conversation->messages()
                    ->where('user_id', '!=', $user->id)
                    ->whereNull('delivered_at')
                    ->update(['delivered_at' => now()]);
            }

            if ($full || $messages->isNotEmpty() || $undelivered) {
                $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);

                // El administrador no participa en las conversaciones que modera: su
                // marca de lectura va aparte para poder contar lo nuevo en silencio.
                $this->markModerationRead($conversation, $user);
                $this->markChatNotificationsRead($conversation, $user);
            }
        }

        $newCount = 0;

        if ($full && $lastRead !== null) {
            $newCount = $conversation->messages()
                ->where('created_at', '>', $lastRead)
                ->when($clearedAt !== null, fn ($query) => $query->where('created_at', '>=', $clearedAt))
                ->count();
        }

        $all = $messages->concat($recent)->unique('id')->values();
        $reactions = $this->reactionSummary($all, $user);
        $reported = $this->reportedMessageIds($all, $user);

        $map = fn (ChatMessage $message) => $this->messageData(
            $message,
            $user,
            $others,
            $reactions,
            $reported,
            $this->canDeleteContent($message, $user, $conversation),
            $clearedAt
        );

        $recentPayload = $recent->map($map)->values();

        return api_success('Mensajes.', [
            'delta' => ! $full,
            'conversation' => $full ? $this->conversationData($conversation->fresh('users'), $user) : null,
            'messages' => $messages->map($map)->values(),
            'recent' => $recentPayload,
            'recent_hash' => $recentPayload->isEmpty() ? null : md5((string) json_encode($recentPayload)),
            'latest_id' => (int) (($messages->last() ?? $recent->last())?->id ?? 0),
            'new_count' => $newCount,
            'mentionables' => $full ? $this->mentionables($conversation) : [],
        ]);
    }

    /**
     * Payload de un mensaje para el widget.
     *
     * @param  Collection<int, User>  $others
     * @param  array<int, array<int, array{emoji:string,count:int,mine:bool}>>  $reactions
     * @param  array<int, int>  $reported
     * @return array<string, mixed>
     */
    private function messageData(ChatMessage $message, User $user, $others, array $reactions, array $reported, bool $canDelete = false, $clearedAt = null): array
    {
        $deleted = $message->isDeleted();
        $read = $others->isNotEmpty() && $others->every(function ($other) use ($message) {
            $lastRead = $other->pivot->last_read_at;

            return $lastRead !== null
                && Carbon::parse($lastRead)->gte($message->created_at);
        });

        return [
            'id' => $message->id,
            'body' => $deleted ? '' : $message->body,
            'sender' => $message->is_moderation ? 'Moderación' : ($message->user?->name ?? 'Sistema'),
            'is_moderation' => $message->is_moderation,
            'mine' => $message->user_id === $user->id,
            'created_at' => $message->created_at?->format('H:i'),
            'created_date' => $message->created_at?->format('Y-m-d'),
            'read' => $read,
            'delivered' => $message->delivered_at !== null,
            'latitude' => $deleted ? null : $message->latitude,
            'longitude' => $deleted ? null : $message->longitude,
            'type' => $message->type,
            'attachment_url' => ! $deleted && $message->attachment_path
                ? asset('storage/'.$message->attachment_path)
                : null,
            'attachment_name' => $deleted ? null : $message->attachment_name,
            // Un mensaje eliminado no expone sus adjuntos ni sus enlaces.
            'attachments' => $deleted
                ? []
                : $message->attachments->map(fn ($item) => [
                    'id' => $item->id,
                    'url' => asset('storage/'.$item->path),
                    'type' => $item->type,
                    'name' => $item->name,
                ])->values(),
            'link_url' => $deleted ? null : $message->link_url,
            'link_title' => $deleted ? null : $message->link_title,
            'link_description' => $deleted ? null : $message->link_description,
            'link_image' => $deleted ? null : $message->link_image,
            'edited' => $message->edited_at !== null,
            'deleted' => $deleted,
            'deleted_label' => $deleted
                ? ($message->deleted_by_admin ? 'Eliminado por un administrador' : 'Eliminado por el autor')
                : null,
            'reactions' => $reactions[$message->id] ?? [],
            'can_edit' => $message->user_id === $user->id && ! $deleted,
            'can_delete' => ! $deleted && $canDelete,
            'reported' => in_array((int) $message->id, $reported, true),
            // Cita del mensaje respondido: se pinta dentro de la burbuja para
            // que la respuesta se entienda sin salir de ella.
            'reply' => $this->replyQuote($message, $clearedAt),
            'forwarded' => $message->isForwarded(),
        ];
    }

    /**
     * Datos del mensaje citado por una respuesta, ya recortados para la
     * burbuja. No expone nada que el usuario no esté viendo ya: la respuesta
     * solo puede apuntar a un mensaje de la misma conversación.
     *
     * @return array{id:int|null,sender:string,body:string,deleted:bool}|null
     */
    private function replyQuote(ChatMessage $message, $clearedAt = null): ?array
    {
        if (! $message->isReply()) {
            return null;
        }

        $original = $message->replyTo;
        $cleared = $clearedAt !== null ? Carbon::parse($clearedAt) : null;

        // Sin original, o con un original que el usuario ya había vaciado de
        // su lado: la cita no puede resucitar el texto.
        if ($original === null
            || ($cleared !== null && $original->created_at !== null && $original->created_at->lt($cleared))) {
            return [
                'id' => null,
                'sender' => 'Mensaje',
                'body' => 'El mensaje original ya no está disponible.',
                'deleted' => true,
            ];
        }

        return [
            'id' => (int) $original->id,
            'sender' => $original->is_moderation ? 'Moderación' : ($original->user?->name ?? 'Sistema'),
            'body' => $this->messageQuote($original),
            'deleted' => $original->isDeleted(),
        ];
    }

    /**
     * Texto corto de un mensaje para citarlo dentro de otra burbuja.
     */
    private function messageQuote(ChatMessage $message): string
    {
        if ($message->isDeleted()) {
            return 'Mensaje eliminado';
        }

        $body = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $message->body)));

        if ($body !== '') {
            return Str::limit($body, 180);
        }

        if ($message->link_title) {
            return Str::limit($message->link_title, 180);
        }

        return match ($message->type) {
            'image' => 'Foto',
            'video' => 'Video',
            'audio' => 'Audio',
            'pdf' => 'PDF',
            'contact' => 'Contacto',
            'location' => 'Ubicación',
            default => $message->attachment_name ?: 'Mensaje adjunto',
        };
    }

    /**
     * Reacciones de un conjunto de mensajes en dos consultas (no una por mensaje).
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, array<int, array{emoji:string,count:int,mine:bool}>>
     */
    private function reactionSummary($messages, User $user): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        $ids = $messages->pluck('id')->all();

        $totals = ChatReaction::query()
            ->whereIn('message_id', $ids)
            ->selectRaw('message_id, emoji, count(*) as total')
            ->groupBy('message_id', 'emoji')
            ->orderByDesc('total')
            ->orderBy('emoji')
            ->get();

        $mine = ChatReaction::query()
            ->whereIn('message_id', $ids)
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('message_id');

        $summary = [];

        foreach ($totals->groupBy('message_id') as $messageId => $rows) {
            $myEmojis = $mine->has($messageId) ? $mine->get($messageId)->pluck('emoji')->all() : [];

            $summary[(int) $messageId] = $rows->map(fn ($row) => [
                'emoji' => $row->emoji,
                'count' => (int) $row->total,
                'mine' => in_array($row->emoji, $myEmojis, true),
            ])->values()->all();
        }

        return $summary;
    }

    /**
     * Mensajes ya reportados por el usuario, en una sola consulta.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, int>
     */
    private function reportedMessageIds($messages, User $user): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        return ChatReport::query()
            ->whereIn('message_id', $messages->pluck('id')->all())
            ->where('reporter_id', $user->id)
            ->pluck('message_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function enviar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($conversation->type === 'direct') {
            $other = $conversation->users->firstWhere('id', '!=', $user->id);

            if ($other !== null && ChatBlock::existsBetween($user->id, $other->id)) {
                return api_error('No puedes enviar mensajes a este usuario.', 403);
            }
        }

        if ($conversation->type === 'group') {
            $suspension = GroupSuspension::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->where('until', '>', now())
                ->first();

            if ($suspension !== null) {
                return api_error(
                    'Estás suspendido en este grupo hasta el '.$suspension->until->format('d/m/Y H:i').'.',
                    403
                );
            }
        }

        $validator = Validator::make($request->all(), [
            'body' => ['nullable', 'string', 'max:2000'],
            'reply_to_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:20480'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:20480'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_title' => ['nullable', 'string', 'max:500'],
            'link_description' => ['nullable', 'string', 'max:2000'],
            'link_image' => ['nullable', 'url', 'max:2048'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $file = $request->file('file');
        $files = array_values(array_filter((array) $request->file('files', [])));

        if ($file !== null) {
            array_unshift($files, $file);
        }

        if ($files === [] && ($data['body'] ?? '') === '' && ! isset($data['latitude'], $data['longitude'])) {
            return api_error('Escribe un mensaje o adjunta un archivo.', 422);
        }

        // La respuesta solo puede citar un mensaje de esta misma conversación
        // y que siga visible: una cita a algo borrado no aporta nada.
        $replyTo = null;

        if (! empty($data['reply_to_id'])) {
            $replyTo = $conversation->messages()->whereKey((int) $data['reply_to_id'])->first();

            if ($replyTo === null) {
                return api_error('El mensaje que quieres responder no está en esta conversación.', 422);
            }

            if ($replyTo->isDeleted()) {
                return api_error('No puedes responder a un mensaje eliminado.', 422);
            }
        }

        $types = array_map(fn ($item) => $this->attachmentType($item), $files);

        if (count($files) > 1 && count(array_diff($types, ['image', 'video'])) > 0) {
            return api_error('Puedes enviar varias fotos y videos juntos. Los audios, PDF y archivos van en un mensaje aparte.', 422);
        }

        try {
            $isMember = $conversation->users()->whereKey($user->id)->exists();
            $type = $types[0] ?? 'text';
            $path = $name = $mime = $size = null;
            $stored = [];

            $bucket = match ($conversation->type) {
                'group' => 'groups',
                'reports' => 'moderations',
                default => 'conversations',
            };

            foreach ($files as $index => $item) {
                $itemType = $types[$index];
                $storedPath = $item->store('chat/'.$bucket.'/'.$conversation->id.'/'.$itemType, 'public');

                $stored[] = [
                    'type' => $itemType,
                    'path' => $storedPath,
                    'name' => $item->getClientOriginalName(),
                    'mime' => (string) $item->getMimeType(),
                    'size' => $item->getSize(),
                ];

                if ($itemType === 'video') {
                    $this->compressVideo($storedPath);
                }
            }

            if ($stored !== []) {
                $path = $stored[0]['path'];
                $name = $stored[0]['name'];
                $mime = $stored[0]['mime'];
                $size = $stored[0]['size'];
            }

            $linkUrl = $data['link_url'] ?? null;

            if (isset($data['latitude'], $data['longitude'])) {
                $type = 'location';
                $linkUrl = 'https://maps.google.com/?q='.$data['latitude'].','.$data['longitude'];
            }

            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'reply_to_id' => $replyTo?->id,
                'body' => $this->sanitizeBody($data['body'] ?? ''),
                'is_moderation' => ! $isMember && $user->role === UserRole::Admin,
                'link_url' => $linkUrl,
                'link_title' => $data['link_title'] ?? ($type === 'location' ? 'Ubicación' : null),
                'link_description' => $data['link_description'] ?? null,
                'link_image' => $data['link_image'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'type' => $type,
                'attachment_path' => $path,
                'attachment_name' => $name,
                'attachment_mime' => $mime,
                'attachment_size' => $size,
            ]);

            if ($stored !== []) {
                $message->attachments()->createMany($stored);
            }

            ChatMentions::handle($message, $conversation, $user);

            $conversation->touch();

            if ($isMember) {
                $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);
            }

            $message->load(['user', 'attachments', 'conversation']);

            $this->firestore->recordMessage($message);
            $this->firestore->recordConversationActivity(
                $conversation,
                $message,
                $message->is_moderation ? 'Moderación' : $user->name
            );

            $this->notifyConversation($conversation, $message, $user);

            return api_success('Mensaje enviado.', [
                'message' => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'sender' => $message->is_moderation ? 'Moderación' : $user->name,
                    'is_moderation' => $message->is_moderation,
                    'mine' => true,
                    'created_at' => $message->created_at?->format('H:i'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al enviar el mensaje', ['error' => $exception->getMessage()]);

            return api_error('No se pudo enviar el mensaje. Intenta de nuevo.', 500);
        }
    }

    /**
     * Destinos posibles para reenviar un mensaje.
     *
     * Se agrupan como en el buzón: grupos, administradores, amigos secretos y
     * personas. Los chats de solo lectura no aparecen porque no reciben
     * mensajes reenviados.
     */
    public function destinatarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();
        $excluded = array_unique(array_merge($this->blockedIds($user), $this->blockedByIds($user)));

        $conversations = Conversation::query()
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->where('is_read_only', false)
            ->whereIn('type', ['group', 'secret'])
            ->with(['users', 'assignment.receiver'])
            ->get();

        $groups = $conversations
            ->where('type', 'group')
            ->sortBy(fn (Conversation $item) => mb_strtolower((string) $item->name))
            ->map(fn (Conversation $item) => [
                'kind' => 'group',
                'target' => 'conversation',
                'id' => (int) $item->id,
                'label' => $item->name ?: 'Grupo',
                'meta' => $item->users->count() === 1
                    ? '1 integrante'
                    : $item->users->count().' integrantes',
            ])
            ->values();

        $secrets = $conversations
            ->where('type', 'secret')
            ->map(fn (Conversation $item) => [
                'kind' => 'secret',
                'target' => 'conversation',
                'id' => (int) $item->id,
                'label' => $item->titleFor($user),
                'meta' => 'Chat de amigo secreto',
            ])
            ->values();

        $people = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (User $item) => in_array((int) $item->id, $excluded, true));

        $personData = fn (User $item, string $kind, string $meta) => [
            'kind' => $kind,
            'target' => 'user',
            'id' => (int) $item->id,
            'label' => $item->name,
            'meta' => $meta,
        ];

        $admins = $people
            ->filter(fn (User $item) => $item->role === UserRole::Admin)
            ->map(fn (User $item) => $personData($item, 'admin', 'Administrador'))
            ->values();

        $users = $people
            ->reject(fn (User $item) => $item->role === UserRole::Admin)
            ->map(fn (User $item) => $personData($item, 'user', $item->role?->label() ?? 'Participante'))
            ->values();

        return api_success('Destinatarios para reenviar.', [
            'groups' => $groups,
            'admins' => $admins,
            'secrets' => $secrets,
            'users' => $users,
        ]);
    }

    /**
     * Reenvía un mensaje a una o varias conversaciones.
     *
     * Se puede reenviar desde cualquier chat al que se tenga acceso, incluidos
     * los de solo lectura: ahí no se responde ni se recibe nada, pero el
     * mensaje que se lee sí se puede reenviar. El destino, en cambio, nunca
     * puede ser de solo lectura.
     */
    public function reenviar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();
        $source = $message->conversation;

        if ($source === null || ! $this->canAccess($source, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        if ($message->isDeleted()) {
            return api_error('No puedes reenviar un mensaje eliminado.', 422);
        }

        $validator = Validator::make($request->all(), [
            'conversation_ids' => ['nullable', 'array', 'max:25'],
            'conversation_ids.*' => ['integer'],
            'user_ids' => ['nullable', 'array', 'max:25'],
            'user_ids.*' => ['integer'],
        ], [
            'conversation_ids.max' => 'Puedes reenviar a un máximo de 25 conversaciones a la vez.',
            'user_ids.max' => 'Puedes reenviar a un máximo de 25 personas a la vez.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $conversationIds = collect($validated['conversation_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $userIds = collect($validated['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === (int) $user->id)
            ->values();

        if ($conversationIds->isEmpty() && $userIds->isEmpty()) {
            return api_error('Elige al menos un destinatario.', 422);
        }

        $targets = collect();

        foreach ($conversationIds as $conversationId) {
            $conversation = Conversation::query()->with('users')->find($conversationId);

            if ($conversation === null) {
                return api_error('Una de las conversaciones ya no existe.', 422);
            }

            if ($conversation->is_read_only) {
                return api_error(
                    '«'.$conversation->titleFor($user).'» es de solo lectura: no recibe mensajes reenviados.',
                    422
                );
            }

            if (! $conversation->users->contains('id', $user->id)) {
                return api_error('Solo puedes reenviar a grupos y conversaciones a los que perteneces.', 403);
            }

            $suspended = $this->ensureNotSuspended($conversation, $user);

            if ($suspended !== null) {
                return $suspended;
            }

            $targets->push($conversation);
        }

        foreach ($userIds as $userId) {
            $person = User::query()->whereKey($userId)->where('is_active', true)->first();

            if ($person === null) {
                return api_error('Una de las personas ya no está disponible.', 422);
            }

            if (ChatBlock::existsBetween($user->id, $person->id)) {
                return api_error('No puedes reenviar a '.$person->name.': hay un bloqueo entre ustedes.', 422);
            }

            $targets->push($this->directConversationWith($user, $person));
        }

        $targets = $targets->unique(fn (Conversation $item) => (int) $item->id)->values();

        try {
            foreach ($targets as $target) {
                $copy = $this->forwardMessage($target, $message, $user);

                ChatMentions::handle($copy, $target, $user);

                $target->touch();
                $target->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);

                $copy->load(['user', 'attachments', 'conversation']);

                $this->firestore->recordMessage($copy);
                $this->firestore->recordConversationActivity($target, $copy, $user->name);
                $this->notifyConversation($target, $copy, $user);
            }
        } catch (Throwable $exception) {
            Log::error('Error al reenviar el mensaje', ['error' => $exception->getMessage()]);

            return api_error('No se pudo reenviar el mensaje. Intenta de nuevo.', 500);
        }

        return api_success('Mensaje reenviado.', [
            'count' => $targets->count(),
            'conversation_ids' => $targets->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    /**
     * Copia un mensaje a otra conversación.
     *
     * Los archivos se duplican en la carpeta del destino: borrar el reenvío no
     * puede dejar sin adjunto al mensaje original (ni al revés).
     */
    private function forwardMessage(Conversation $target, ChatMessage $source, User $sender): ChatMessage
    {
        $type = (string) $source->type;
        $bucket = match ($target->type) {
            'group' => 'groups',
            'reports' => 'moderations',
            default => 'conversations',
        };

        $source->loadMissing('attachments');

        $copied = [];

        $copy = function (string $path) use (&$copied, $bucket, $target, $type): ?string {
            if (array_key_exists($path, $copied)) {
                return $copied[$path];
            }

            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $newPath = 'chat/'.$bucket.'/'.$target->id.'/'.$type.'/'
                .Str::random(40).($extension !== '' ? '.'.$extension : '');

            try {
                $disk = Storage::disk('public');
                $done = $disk->exists($path) && $disk->copy($path, $newPath);
            } catch (Throwable) {
                $done = false;
            }

            return $copied[$path] = $done ? $newPath : null;
        };

        $legacyPath = $source->attachment_path ? $copy($source->attachment_path) : null;

        $attachments = $source->attachments
            ->map(function (ChatMessageAttachment $attachment) use ($copy) {
                $path = $copy($attachment->path);

                if ($path === null) {
                    return null;
                }

                return [
                    'type' => $attachment->type,
                    'path' => $path,
                    'name' => $attachment->name,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ];
            })
            ->filter()
            ->values();

        $message = ChatMessage::create([
            'conversation_id' => $target->id,
            'user_id' => $sender->id,
            'forwarded_from_id' => $source->id,
            'body' => $source->body,
            'is_moderation' => false,
            'link_url' => $source->link_url,
            'link_title' => $source->link_title,
            'link_description' => $source->link_description,
            'link_image' => $source->link_image,
            'latitude' => $source->latitude,
            'longitude' => $source->longitude,
            'type' => $type,
            'attachment_path' => $legacyPath,
            'attachment_name' => $legacyPath !== null ? $source->attachment_name : null,
            'attachment_mime' => $legacyPath !== null ? $source->attachment_mime : null,
            'attachment_size' => $legacyPath !== null ? $source->attachment_size : null,
        ]);

        if ($attachments->isNotEmpty()) {
            $message->attachments()->createMany($attachments->all());
        }

        return $message;
    }

    public function editar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($message->conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        $suspended = $this->ensureNotSuspended($message->conversation, $user);

        if ($suspended !== null) {
            return $suspended;
        }

        if ($message->user_id !== $user->id) {
            return api_error('Solo el autor puede editar el mensaje.', 403);
        }

        if ($message->isDeleted()) {
            return api_error('El mensaje está eliminado.', 409);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Escribe el mensaje.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $message->update([
            'body' => $this->sanitizeBody($validator->validated()['body']),
            'edited_at' => now(),
        ]);

        $this->firestore->recordMessage($message->load('user', 'attachments', 'conversation'));

        return api_success('Mensaje editado.', [
            'message' => [
                'id' => $message->id,
                'body' => $message->body,
                'edited' => true,
            ],
        ]);
    }

    public function eliminar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($message->conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        if (! $this->canDeleteContent($message, $user)) {
            return api_error('Solo el autor, el dueño del grupo o un administrador puede eliminar el mensaje.', 403);
        }

        if ($message->isDeleted()) {
            return api_success('El mensaje ya estaba eliminado.');
        }

        return api_success('Mensaje eliminado.', [
            'deleted_label' => $this->deleteMessageContent($message, $user),
        ]);
    }

    /**
     * Elimina un adjunto concreto (foto, vídeo, audio, documento o enlace).
     *
     * Si era el último adjunto del mensaje, el mensaje se elimina también: no
     * debe quedar una burbuja vacía ni el contenido en las galerías.
     */
    public function eliminarAdjunto(Request $request, ChatMessageAttachment $attachment): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($attachment->message?->conversation)) {
            return $readOnly;
        }

        $user = $request->user();
        $message = $attachment->message;

        if ($message === null) {
            return api_error('El contenido ya no existe.', 404);
        }

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este contenido.', 403);
        }

        if (! $this->canDeleteContent($message, $user)) {
            return api_error('Solo el autor, el dueño del grupo o un administrador puede eliminar este contenido.', 403);
        }

        if ($message->isDeleted()) {
            return api_success('El contenido ya estaba eliminado.', ['message_deleted' => true]);
        }

        $message->attachments()->whereKey($attachment->id)->delete();
        Storage::disk('public')->delete($attachment->path);

        if ($message->attachments()->count() === 0) {
            return api_success('Se eliminó el contenido y el mensaje.', [
                'message_deleted' => true,
                'deleted_label' => $this->deleteMessageContent($message, $user),
            ]);
        }

        return api_success('Contenido eliminado.', ['message_deleted' => false]);
    }

    /**
     * Quién puede borrar el contenido de un mensaje: su autor, el dueño del
     * grupo o un administrador.
     */
    private function canDeleteContent(ChatMessage $message, User $user, ?Conversation $conversation = null): bool
    {
        $conversation ??= $message->conversation;

        return $message->user_id === $user->id
            || $user->role === UserRole::Admin
            || ($conversation !== null
                && $conversation->type === 'group'
                && (int) $conversation->created_by === (int) $user->id);
    }

    /**
     * Marca el mensaje como eliminado y limpia sus adjuntos y su fijación.
     *
     * @return string Etiqueta que verá el resto («Eliminado por …»).
     */
    private function deleteMessageContent(ChatMessage $message, User $user): string
    {
        $byAdmin = $message->user_id !== $user->id
            && ($user->role === UserRole::Admin
                || ($message->conversation?->type === 'group'
                    && (int) $message->conversation?->created_by === (int) $user->id));

        // Además de marcarlo como eliminado, se limpian los datos que no deben
        // quedar como residuo: enlaces, ubicación y referencia al adjunto.
        $message->update([
            'deleted_at' => now(),
            'deleted_by' => $user->id,
            'deleted_by_admin' => $byAdmin,
            'link_url' => null,
            'link_title' => null,
            'link_description' => null,
            'link_image' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->firestore->recordMessage($message->load('user', 'attachments', 'conversation'));

        if ($message->conversation?->isGroup()) {
            ChatGroupAudit::log(
                $message->conversation,
                $user,
                'mensaje_eliminado',
                $message->user,
                'eliminado',
                $byAdmin ? 'Mensaje eliminado por moderación.' : 'Mensaje eliminado por su autor.'
            );
        }

        // El adjunto pertenece al mensaje: al eliminarlo no debe quedar ni en la
        // galería ni ocupando espacio en el almacenamiento.
        $this->purgeMessageAttachments($message);

        // Si estaba fijado, el cintillo tampoco debe seguir apuntando a él.
        if ((int) $message->conversation?->pinned_message_id === (int) $message->id) {
            $message->conversation->forceFill(['pinned_message_id' => null])->save();
        }

        return $byAdmin ? 'Eliminado por un administrador' : 'Eliminado por el autor';
    }

    /**
     * Borra los archivos adjuntos de un mensaje eliminado.
     */
    private function purgeMessageAttachments(ChatMessage $message): void
    {
        $paths = $message->attachments()->pluck('path')->all();

        if ($message->attachment_path) {
            $paths[] = $message->attachment_path;
        }

        $message->attachments()->delete();

        if ($message->attachment_path) {
            $message->forceFill([
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_mime' => null,
                'attachment_size' => null,
            ])->save();
        }

        foreach (array_unique(array_filter($paths)) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function reaccionar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($message->conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        $suspended = $this->ensureNotSuspended($message->conversation, $user);

        if ($suspended !== null) {
            return $suspended;
        }

        $validator = Validator::make($request->all(), [
            'emoji' => ['required', 'string', 'max:16'],
        ], [
            'emoji.required' => 'Selecciona un emoji.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $message->reactions()->where('user_id', $user->id)->where('emoji', $validator->validated()['emoji'])->delete();

        $message->reactions()->updateOrCreate(
            ['user_id' => $user->id],
            ['emoji' => $validator->validated()['emoji']]
        );

        return api_success('Reacción guardada.');
    }

    public function reportar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($message->conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        if ($message->user_id === $user->id) {
            return api_error('No puedes reportar tu propio mensaje.', 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo del reporte.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $reason = $validator->validated()['reason'];

        $report = ChatReport::create([
            'message_id' => $message->id,
            'reporter_id' => $user->id,
            'reason' => $reason,
        ]);

        $this->firestore->recordReport($report);

        $reports = $this->reportConversation();
        $sender = $message->user?->name ?? 'Sistema';
        $body = 'Mensaje reportado: «'.$message->body.'» de '.$sender
            .', reportado por '.$user->name
            .' el '.now()->format('d/m/Y').' a las '.now()->format('H:i')
            .'. Motivo: '.$reason;

        $reportMessage = $reports->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
            'is_moderation' => true,
        ]);

        $this->firestore->recordMessage($reportMessage->load('user', 'attachments', 'conversation'));
        $this->firestore->recordConversation($reports->fresh('users'));

        $admins = User::query()->where('role', UserRole::Admin)->where('is_active', true)->get();

        foreach ($admins as $admin) {
            send_mail(
                $admin->email,
                'Mensaje reportado en el chat',
                'Nuevo reporte en el chat',
                [
                    'Se reportó un mensaje de '.$sender.'.',
                    'Reportado por: '.$user->name.'.',
                    'Motivo: '.$reason,
                    'Fecha: '.now()->format('d/m/Y H:i'),
                ],
                null,
                [
                    'recipient_name' => $admin->name,
                    'eyebrow' => 'Reporte',
                    'preheader' => 'Reporte de mensaje',
                    'source' => 'reporte',
                ]
            );
        }

        $this->push->sendToAdmins(
            'chat.reporte',
            'Nuevo reporte en el chat',
            'Se reportó un mensaje de '.$sender.'.',
            [
                'report_id' => $report->id,
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'reporter' => $user->name,
                'reason' => $reason,
            ],
            module_url('moderacion'),
            'reporte',
            $user
        );

        return api_success('Reporte enviado a la administración.');
    }

    /**
     * Avisa a los demás participantes del mensaje nuevo (push + Firestore).
     *
     * El silencio de una conversación frena los avisos normales, pero no una
     * respuesta: quien recibe una respuesta recibe también su carga de
     * notificación, aunque tenga silenciado al remitente.
     */
    private function notifyConversation(Conversation $conversation, ChatMessage $message, User $sender): void
    {
        if ($conversation->type === 'reports') {
            return;
        }

        try {
            $isReply = $message->isReply();
            $isForward = $message->isForwarded();

            $recipients = $conversation->users()
                ->whereKeyNot($sender->id)
                ->get();

            // Quien silenció la conversación no recibe push ni aviso interno:
            // el mensaje sigue contando en la lista, pero sin interrumpir. La
            // respuesta es la excepción: atraviesa el silencio.
            if (! $isReply) {
                $recipients = $recipients->reject(fn (User $recipient) => $recipient->pivot?->muted_at !== null);
            }

            if ($recipients->isEmpty()) {
                return;
            }

            $isGroup = $conversation->isGroup();
            $isSecret = $conversation->type === 'secret';

            $title = $message->is_moderation
                ? 'Mensaje de moderación'
                : ($isSecret
                    ? ($isReply
                        ? 'Tienes una respuesta en tu chat de amigo secreto'
                        : 'Nuevo mensaje en tu chat de amigo secreto')
                    : ($isReply
                        ? ($isGroup
                            ? $sender->name.' respondió en '.($conversation->name ?: 'el grupo')
                            : $sender->name.' te respondió')
                        : ($isGroup
                            ? 'Nuevo mensaje en '.($conversation->name ?: 'el grupo')
                            : 'Nuevo mensaje de '.$sender->name)));

            $this->push->sendMany(
                $recipients,
                'chat.mensaje',
                $title,
                $isForward ? 'Mensaje reenviado: '.$this->messagePreview($message) : $this->messagePreview($message),
                [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    // En el amigo secreto no se revela quién escribe.
                    'sender' => $isSecret ? 'Amigo secreto' : $sender->name,
                    'is_group' => $isGroup,
                    'is_reply' => $isReply,
                    'is_forward' => $isForward,
                    'reply_to_id' => $message->reply_to_id,
                ],
                module_url('chat').'?conversacion='.$conversation->id,
                'chat'
            );
        } catch (Throwable $exception) {
            Log::warning('No se pudo avisar del mensaje del chat', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function messagePreview(ChatMessage $message): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $message->body)));

        if ($body !== '') {
            return Str::limit($body, 140);
        }

        return match ($message->type) {
            'image' => 'Envió una foto.',
            'video' => 'Envió un video.',
            'audio' => 'Envió un audio.',
            'pdf' => 'Envió un PDF.',
            'contact' => 'Envió un contacto.',
            'location' => 'Compartió una ubicación.',
            default => 'Envió un archivo.',
        };
    }

    private function reportConversation(): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['type' => 'reports'],
            ['name' => 'Reportes', 'is_moderated' => true]
        );

        $admins = User::query()->where('role', UserRole::Admin)->pluck('id');

        foreach ($admins as $adminId) {
            $conversation->users()->syncWithoutDetaching([$adminId => ['role' => 'moderator']]);
        }

        return $conversation;
    }

    public function leer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $conversation->users()->whereKey($user->id)->exists()) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);

        $this->markChatNotificationsRead($conversation, $user);
        $this->firestore->recordConversation($conversation->fresh('users'));

        return api_success('Conversación marcada como leída.');
    }

    public function moderar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($request->user()->role !== UserRole::Admin) {
            return api_error('Solo un administrador puede moderar.', 403);
        }

        $conversation->load('users');

        return api_success('Conversación abierta para moderación.', [
            'conversation' => $this->conversationData($conversation, $request->user(), true),
        ]);
    }

    public function enlace(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'url' => ['required', 'url', 'max:2048'],
        ], [
            'url.required' => 'El enlace no es válido.',
            'url.url' => 'El enlace no es válido.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $url = $validator->validated()['url'];

        try {
            $html = Http::timeout(6)->get($url)->body();
            $title = $this->metaTag($html, 'og:title') ?? $this->titleTag($html) ?? $url;
            $description = $this->metaTag($html, 'og:description');
            $image = $this->metaTag($html, 'og:image');

            if ($image !== null && ! str_starts_with($image, 'http')) {
                $image = rtrim($url, '/').'/'.ltrim($image, '/');
            }

            return api_success('Vista previa.', [
                'preview' => [
                    'url' => $url,
                    'title' => $title,
                    'description' => $description,
                    'image' => $image,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('No se pudo obtener la vista previa', ['url' => $url, 'error' => $exception->getMessage()]);

            return api_success('Sin vista previa.', ['preview' => ['url' => $url, 'title' => $url]]);
        }
    }

    public function media(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        // Lo que el usuario borró de su lado tampoco aparece en su galería.
        $clearedAt = $conversation->users->firstWhere('id', $user->id)?->pivot->cleared_at;

        $items = $conversation->messages()
            // Un mensaje eliminado se lleva su adjunto: no queda como residuo
            // en multimedia, documentos, audios ni enlaces.
            ->whereNull('deleted_at')
            ->when($clearedAt !== null, fn ($query) => $query->where('created_at', '>=', $clearedAt))
            ->with('attachments')
            ->latest('id')
            ->limit(300)
            ->get()
            ->flatMap(function (ChatMessage $message) use ($user) {
                // `message_id` y `position` dejan ordenar el contenido por el
                // mensaje en que se envió, sin agrupar por tipo.
                $base = [
                    'message_id' => (int) $message->id,
                    'created_at' => $message->created_at?->format('d/m/Y H:i'),
                    'can_delete' => $this->canDeleteContent($message, $user),
                ];

                if ($message->link_url) {
                    return [array_merge($base, [
                        'id' => null,
                        'kind' => 'link',
                        'url' => $message->link_url,
                        'name' => $message->link_title ?: $message->link_url,
                        'extension' => 'enlace',
                        'size' => null,
                        'position' => 0,
                    ])];
                }

                $attachments = $message->attachments;

                if ($attachments->isEmpty() && $message->attachment_path) {
                    $attachments = collect([(object) [
                        'id' => null,
                        'type' => $message->type,
                        'path' => $message->attachment_path,
                        'name' => $message->attachment_name,
                        'mime' => $message->attachment_mime,
                        'size' => $message->attachment_size,
                    ]]);
                }

                return $attachments->values()->map(function ($item, $index) use ($base) {
                    $extension = strtolower(pathinfo((string) $item->name, PATHINFO_EXTENSION));
                    $kind = $item->type;

                    if ($extension === 'pdf') {
                        $kind = 'pdf';
                    }

                    return array_merge($base, [
                        'id' => $item->id ?? null,
                        'kind' => $kind,
                        'url' => asset('storage/'.$item->path),
                        'name' => $item->name,
                        'extension' => $extension,
                        'size' => $item->size,
                        'position' => (int) $index,
                    ]);
                })->all();
            })
            ->filter()
            ->values();

        return api_success('Multimedia.', ['items' => $items]);
    }

    private function attachmentType($file): string
    {
        if (strtolower($file->getClientOriginalExtension()) === 'vcf') {
            return 'contact';
        }

        $clientMime = strtolower((string) $file->getClientMimeType());
        $detectedMime = strtolower((string) $file->getMimeType());
        $mime = $clientMime !== '' && $clientMime !== 'application/octet-stream'
            ? $clientMime
            : $detectedMime;

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function compressVideo(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $absolute = Storage::disk('public')->path($path);

        if (! is_file($absolute)) {
            return;
        }

        $binary = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where ffmpeg 2>NUL' : 'command -v ffmpeg 2>/dev/null'));

        if ($binary === '') {
            return;
        }

        $temp = $absolute.'.compressed.mp4';
        $command = $binary.' -y -i '.escapeshellarg($absolute)
            .' -vf scale=-2:720 -c:v libx264 -crf 30 -preset veryfast -c:a aac -b:a 64k '
            .escapeshellarg($temp).' 2>&1';

        @exec($command, $output, $code);

        if ($code === 0 && is_file($temp) && filesize($temp) > 0) {
            @rename($temp, $absolute);
        } else {
            @unlink($temp);
        }
    }

    private function sanitizeBody(?string $body): string
    {
        $body = (string) $body;
        $body = strip_tags($body, '<b><strong><i><em><u><s><strike><br>');

        return preg_replace('/<(b|strong|i|em|u|s|strike|br)\b[^>]*>/i', '<$1>', $body);
    }

    private function metaTag(string $html, string $property): ?string
    {
        $pattern = '/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']+)/i';

        return preg_match($pattern, $html, $match) ? trim(html_entity_decode($match[1])) : null;
    }

    private function titleTag(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)
            ? trim(html_entity_decode(strip_tags($match[1])))
            : null;
    }

    private function ensureNotSuspended(Conversation $conversation, User $user): ?JsonResponse
    {
        if ($conversation->type !== 'group') {
            return null;
        }

        $suspension = GroupSuspension::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where('until', '>', now())
            ->first();

        if ($suspension === null) {
            return null;
        }

        return api_error(
            'Estás suspendido en este grupo hasta el '.$suspension->until->format('d/m/Y H:i').'.',
            403
        );
    }

    /**
     * Los chats de solo lectura (el del evento) no admiten nada del usuario:
     * ni escribir, ni adjuntar, ni reaccionar, ni reportar, ni silenciar.
     */
    private function ensureWritable(?Conversation $conversation): ?JsonResponse
    {
        if ($conversation === null || ! $conversation->is_read_only) {
            return null;
        }

        return api_error('Este chat es de solo lectura: aquí solo se publican los avisos del evento.', 403);
    }

    private function canAccess(Conversation $conversation, User $user): bool
    {
        $conversation->loadMissing('users');

        if (! $conversation->users->contains('id', $user->id)) {
            if ($user->role !== UserRole::Admin) {
                return false;
            }

            // Lo archivado queda fuera del alcance de la moderación.
            return ! $conversation->isArchivedForAnyUser();
        }

        // Un bloqueo entre las dos personas corta el acceso al chat directo.
        if ($conversation->type === 'direct') {
            $other = $conversation->users->firstWhere('id', '!=', $user->id);

            if ($other !== null && ChatBlock::existsBetween($user->id, $other->id)) {
                return false;
            }
        }

        return true;
    }

    public function silenciar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $pivot = $this->pivotFor($conversation, $user);

        if ($pivot === null) {
            return api_error('Solo los participantes pueden silenciar la conversación.', 403);
        }

        $muted = $pivot->muted_at === null;

        $conversation->users()->updateExistingPivot($user->id, [
            'muted_at' => $muted ? now() : null,
        ]);

        return api_success($muted ? 'Conversación silenciada.' : 'Silencio quitado.', ['muted' => $muted]);
    }

    public function limpiar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($this->pivotFor($conversation, $user) === null) {
            return api_error('Solo los participantes pueden borrar la conversación de su lado.', 403);
        }

        $conversation->users()->updateExistingPivot($user->id, ['cleared_at' => now()]);

        return api_success('Conversación borrada de tu lado.');
    }

    /**
     * «Vaciar chat»: limpia mensajes, enlaces y adjuntos de mi lado, pero la
     * conversación sigue en la lista.
     */
    public function vaciar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $conversation->users()->updateExistingPivot($request->user()->id, ['cleared_at' => now()]);

        if ($conversation->isGroup()) {
            ChatGroupAudit::log(
                $conversation,
                $request->user(),
                'vaciar_sala',
                $request->user(),
                'vaciado',
                'El miembro vació la sala de su lado.'
            );
        }

        return api_success('Chat vaciado. Se limpiaron los mensajes, enlaces y adjuntos.');
    }

    /**
     * «Eliminar»: saca la conversación de mi lista. Al volver a abrirla con la
     * misma persona se reinicia mi vista, sin duplicar la conversación.
     */
    public function eliminarPropia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $now = now();

        $conversation->users()->updateExistingPivot($request->user()->id, [
            'cleared_at' => $now,
            'hidden_at' => $now,
            'last_read_at' => $now,
            'archived_at' => null,
            'favorite_at' => null,
            'pinned_at' => null,
        ]);

        return api_success('Conversación eliminada de tu lista.');
    }

    /**
     * «Archivar»: pasa a la pestaña Archivadas y queda fuera de la moderación
     * mientras esté archivada. Solo las conversaciones directas.
     */
    public function archivar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        if ($conversation->type !== 'direct') {
            return api_error('Solo las conversaciones directas se pueden archivar.', 422);
        }

        $conversation->users()->updateExistingPivot($request->user()->id, ['archived_at' => now()]);

        return api_success('Conversación archivada. Mientras esté archivada queda fuera de la moderación.');
    }

    /**
     * «Deshacer archivo»: vuelve a Conversaciones y a la moderación.
     */
    public function desarchivar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        if (! $conversation->canBeUnarchived()) {
            return api_error('Este chat de amigo secreto queda archivado al terminar el evento.', 422);
        }

        $conversation->users()->updateExistingPivot($request->user()->id, ['archived_at' => null]);

        return api_success('Conversación devuelta a la lista.');
    }

    /**
     * Destacar o quitar de favoritos: solo cambia para mí y sigue el orden
     * por mensaje.
     */
    public function favorito(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $favorite = $this->pivotFor($conversation, $request->user())?->favorite_at === null;

        $conversation->users()->updateExistingPivot($request->user()->id, [
            'favorite_at' => $favorite ? now() : null,
        ]);

        return api_success(
            $favorite ? 'Conversación destacada en favoritos.' : 'Conversación quitada de favoritos.',
            ['favorite' => $favorite]
        );
    }

    /**
     * Fijar o desfijar la conversación: las fijadas van primero en la lista y
     * no se mueven aunque lleguen mensajes.
     */
    public function fijar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $pinned = $this->pivotFor($conversation, $request->user())?->pinned_at === null;

        $conversation->users()->updateExistingPivot($request->user()->id, [
            'pinned_at' => $pinned ? now() : null,
        ]);

        return api_success(
            $pinned ? 'Conversación fijada arriba.' : 'Conversación desfijada.',
            ['pinned' => $pinned]
        );
    }

    /**
     * Marcar como no leído: se revierte la lectura de toda la conversación.
     */
    public function noLeido(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $conversation->users()->updateExistingPivot($request->user()->id, ['last_read_at' => null]);

        return api_success('Conversación marcada como no leída.', ['unread' => true]);
    }

    /**
     * Fijar un mensaje dentro del chat (cintillo bajo el título). Solo los
     * participantes: la moderación no fija mensajes.
     */
    public function fijarMensaje(Request $request, Conversation $conversation, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        if ((int) $message->conversation_id !== (int) $conversation->id) {
            return api_error('Ese mensaje no pertenece a la conversación.', 422);
        }

        if ($message->isDeleted()) {
            return api_error('No puedes fijar un mensaje eliminado.', 422);
        }

        $conversation->forceFill(['pinned_message_id' => $message->id])->save();

        return api_success('Mensaje fijado.', [
            'pinned_message' => $this->pinnedMessageData($conversation->fresh('pinnedMessage.user'), $request->user()),
        ]);
    }

    /**
     * Quitar el mensaje fijado (desde el cintillo del chat).
     */
    public function desfijarMensaje(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $conversation->forceFill(['pinned_message_id' => null])->save();

        return api_success('Mensaje desfijado.', ['pinned_message' => null]);
    }

    /**
     * Solo los participantes pueden cambiar el estado de la conversación.
     */
    private function ensureMember(Conversation $conversation, User $user): ?JsonResponse
    {
        $conversation->loadMissing('users');

        if ($conversation->users->contains('id', $user->id)) {
            return null;
        }

        if ($user->role === UserRole::Admin && $this->canAccess($conversation, $user)) {
            return api_error('La moderación no puede cambiar esta conversación.', 403);
        }

        return api_error('No tienes acceso a esta conversación.', 403);
    }

    public function bloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        $current = $request->user();

        if ($current->id === $user->id) {
            return api_error('No puedes bloquearte a ti mismo.', 422);
        }

        if ($user->role === UserRole::Admin) {
            return api_error('No puedes bloquear a un administrador.', 422);
        }

        ChatBlock::query()->firstOrCreate([
            'blocker_id' => $current->id,
            'blocked_id' => $user->id,
        ]);

        return api_success('Usuario bloqueado.', ['blocked' => true]);
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        ChatBlock::query()
            ->where('blocker_id', $request->user()->id)
            ->where('blocked_id', $user->id)
            ->delete();

        return api_success('Usuario desbloqueado.', ['blocked' => false]);
    }

    public function salir(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if ($conversation->type !== 'group') {
            return api_error('Solo puedes salir de un grupo.', 422);
        }

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No perteneces a este grupo.', 403);
        }

        if ($conversation->created_by === $user->id) {
            return api_error('El dueño del grupo no puede salir: puede eliminar el grupo o expulsar miembros.', 422);
        }

        $conversation->users()->detach($user->id);
        $conversation->touch();

        ChatGroupAudit::log($conversation, $user, 'salida', $user, 'salida', 'El miembro salió del grupo.');

        return api_success('Saliste del grupo.');
    }

    public function reportarConversacion(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $lastMessageId = $conversation->messages()->latest('id')->value('id');
        $conversationName = $conversation->name ?: ($conversation->isGroup() ? 'un grupo' : 'una conversación');
        $reason = 'Reporte de la conversación: '.$conversationName;
        $report = null;

        if ($lastMessageId !== null) {
            $report = ChatReport::query()->firstOrCreate(
                ['message_id' => $lastMessageId, 'reporter_id' => $user->id],
                ['reason' => $reason]
            );

            if (! $report->wasRecentlyCreated) {
                return api_success('Este reporte ya estaba registrado.');
            }

            $this->firestore->recordReport($report);
        }

        $reports = $this->reportConversation();
        $reportMessage = $reports->messages()->create([
            'user_id' => $user->id,
            'body' => 'Conversación reportada: '.$user->name.' reportó '.$conversationName
                .'. Fecha: '.now()->format('d/m/Y H:i'),
            'is_moderation' => true,
        ]);

        $this->firestore->recordMessage($reportMessage->load('user', 'attachments', 'conversation'));
        $this->firestore->recordConversation($reports->fresh('users'));

        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            if ((int) $admin->id === (int) $user->id) {
                continue;
            }

            send_mail(
                $admin->email,
                'Conversación reportada en el chat',
                'Nuevo reporte de conversación',
                [
                    $user->name.' reportó '.$conversationName.'.',
                    'Fecha: '.now()->format('d/m/Y H:i'),
                ],
                null,
                [
                    'recipient_name' => $admin->name,
                    'eyebrow' => 'Reporte',
                    'preheader' => 'Reporte de conversación',
                    'source' => 'reporte',
                ]
            );
        }

        $this->push->sendToAdmins(
            'chat.report',
            'Conversación reportada',
            $user->name.' reportó '.$conversationName.'.',
            [
                'report_id' => $report?->id,
                'report_type' => 'conversation',
                'conversation_id' => $conversation->id,
                'reporter' => $user->name,
                'reason' => $reason,
            ],
            module_url('moderacion'),
            'reporte',
            $user
        );

        return api_success('Reporte enviado a la administración.');
    }

    public function expulsar(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $current = $request->user();

        $isOwner = $conversation->type === 'group' && $conversation->created_by === $current->id;

        if ($current->role !== UserRole::Admin && ! $isOwner) {
            abort(403);
        }

        $conversation->users()->detach($user->id);

        ChatGroupAudit::log(
            $conversation,
            $current,
            'expulsion',
            $user,
            'expulsado',
            'Miembro expulsado del grupo.'
        );

        return api_success('Miembro expulsado del grupo.');
    }

    /**
     * Perfil de la conversación para el panel del chat: identidad, miembros,
     * auditoría del grupo y estado de las opciones del usuario.
     */
    public function perfil(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $conversation->loadMissing('users', 'assignment.receiver');

        $isGroup = $conversation->isGroup();
        $isAdmin = $user->role === UserRole::Admin;
        $pivot = $this->pivotFor($conversation, $user);
        $isParticipant = $pivot !== null;
        $isOwner = $isGroup && (int) $conversation->created_by === (int) $user->id;
        $canManage = $isAdmin || $isOwner;
        $isSecret = $conversation->type === 'secret';
        $secretRole = $conversation->secretRoleFor($user);
        $other = $isGroup
            ? null
            : ($conversation->users->firstWhere('id', '!=', $user->id)
                ?? $conversation->users->first());

        $showOther = ! $isSecret || $isAdmin || $secretRole === 'giver';

        $suspensions = $canManage
            ? GroupSuspension::query()
                ->where('conversation_id', $conversation->id)
                ->where('until', '>', now())
                ->get()
                ->keyBy('user_id')
            : collect();

        $bans = $canManage
            ? GroupBan::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('revoked_at')
                ->get()
                ->keyBy('user_id')
            : collect();

        $members = $conversation->users->map(function (User $member) use ($conversation, $canManage, $suspensions, $bans) {
            $status = null;

            if ($canManage) {
                $status = $bans->has($member->id)
                    ? 'Vetado'
                    : ($suspensions->has($member->id) ? 'Suspendido' : 'Activo');
            }

            return [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'is_owner' => (int) $member->id === (int) $conversation->created_by,
                'online' => $member->last_seen_at !== null && $member->last_seen_at->gte(now()->subMinutes(5)),
                'status' => $status,
            ];
        })->values();

        $blocked = ! $isGroup && $isParticipant && $other !== null
            && in_array((int) $other->id, $this->blockedIds($user), true);
        $blockedByThem = ! $isGroup && $isParticipant && $other !== null
            && in_array((int) $other->id, $this->blockedByIds($user), true);

        $audit = [];

        if ($isGroup && $canManage) {
            $audit = ChatGroupAudit::query()
                ->where('conversation_id', $conversation->id)
                ->with(['actor:id,name', 'target:id,name'])
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (ChatGroupAudit $entry) => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'label' => $entry->label(),
                    'status' => $entry->status,
                    'details' => $entry->details,
                    'actor' => $entry->actor?->name,
                    'target' => $entry->target?->name,
                    'created_at' => $entry->created_at?->format('d/m/Y H:i'),
                ])
                ->values()
                ->all();
        }

        return api_success('Perfil de la conversación.', [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'is_group' => $isGroup,
            'is_secret' => $isSecret,
            'secret_role' => $secretRole,
            'title' => $conversation->titleFor($user),
            'name' => $conversation->name,
            'is_owner' => $isOwner,
            'is_admin' => $isAdmin,
            'is_participant' => $isParticipant,
            'is_moderating' => $isAdmin && ! $isParticipant,
            'can_manage' => $canManage,
            'can_leave' => $isGroup && $isParticipant && ! $isOwner && ! $isAdmin,
            'can_delete' => $isGroup && $canManage,
            'can_block' => ! $isGroup && $isParticipant && $other !== null
                && $other->role !== UserRole::Admin,
            'can_report' => $isParticipant,
            'members_count' => $conversation->users->count(),
            'members' => $members,
            'other' => $other !== null && $showOther ? [
                'id' => $other->id,
                'name' => $other->name,
                'username' => $other->username,
                'email' => $other->email,
                'online' => $other->last_seen_at !== null && $other->last_seen_at->gte(now()->subMinutes(5)),
            ] : null,
            'favorite' => $isParticipant && $pivot?->favorite_at !== null,
            'muted' => $isParticipant && $pivot?->muted_at !== null,
            'blocked' => $blocked,
            'blocked_by_them' => $blockedByThem,
            'audit' => $audit,
        ]);
    }

    public function miembros(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $current = $request->user();
        $isOwner = $conversation->type === 'group' && $conversation->created_by === $current->id;

        if ($current->role !== UserRole::Admin && ! $isOwner) {
            abort(403);
        }

        $suspensions = GroupSuspension::query()
            ->where('conversation_id', $conversation->id)
            ->where('until', '>', now())
            ->get()
            ->keyBy('user_id');

        $bans = GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->get()
            ->keyBy('user_id');

        $items = $conversation->users->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'is_owner' => $member->id === $conversation->created_by,
            'suspended_until' => $suspensions->get($member->id)?->until?->format('d/m/Y H:i'),
            'banned' => $bans->has($member->id),
        ])->values();

        return api_success('Miembros del grupo.', ['items' => $items]);
    }

    public function eliminarConversacion(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $current = $request->user();

        $isOwner = $conversation->type === 'group' && $conversation->created_by === $current->id;

        if ($current->role !== UserRole::Admin && ! $isOwner) {
            abort(403);
        }

        $messageIds = $conversation->messages()->pluck('id');
        $paths = collect();

        if ($messageIds->isNotEmpty()) {
            $paths = ChatMessageAttachment::query()->whereIn('message_id', $messageIds)->pluck('path');
            ChatMessageAttachment::query()->whereIn('message_id', $messageIds)->delete();
        }

        $legacy = $conversation->messages()->whereNotNull('attachment_path')->pluck('attachment_path');

        $conversation->messages()->delete();
        DB::table('chat_conversation_user')
            ->where('conversation_id', $conversation->id)
            ->delete();

        $conversation->delete();

        // Los archivos de la conversación tampoco se quedan en el disco.
        foreach ($paths->merge($legacy)->filter()->unique() as $path) {
            Storage::disk('public')->delete($path);
        }

        return api_success('Conversación eliminada.');
    }

    /**
     * Pivote del usuario en la conversación (silencio, limpieza, lectura).
     */
    private function pivotFor(Conversation $conversation, User $user): mixed
    {
        $conversation->loadMissing('users');

        return $conversation->users->firstWhere('id', $user->id)?->pivot;
    }

    private function isMuted(Conversation $conversation, User $user): bool
    {
        return $this->pivotFor($conversation, $user)?->muted_at !== null;
    }

    private function unreadCount(Conversation $conversation, User $user): int
    {
        $pivot = $this->pivotFor($conversation, $user);
        $lastRead = $pivot?->last_read_at;
        $clearedAt = $pivot?->cleared_at;

        return $conversation->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead !== null, fn ($query) => $query->where('created_at', '>', $lastRead))
            // La columna guarda segundos: se compara con >= para no perder un
            // mensaje nuevo enviado en el mismo segundo en que se borró.
            ->when($clearedAt !== null, fn ($query) => $query->where('created_at', '>=', $clearedAt))
            ->count();
    }

    /**
     * Mensajes nuevos de una conversación que el administrador solo modera.
     *
     * Se cuentan desde su marca de lectura de moderación y, si todavía no
     * tiene una, desde su último inicio de sesión. Nunca se suman al contador
     * propio: van aparte y en silencio.
     */
    private function moderationUnreadCount(Conversation $conversation, User $user): int
    {
        if ($user->role !== UserRole::Admin) {
            return 0;
        }

        $conversation->loadMissing('users');

        // Lo que el administrador sí juega se cuenta como propio, en su lista
        // de conversaciones; aquí solo va lo que supervisa sin participar.
        if ($conversation->users->contains('id', $user->id)) {
            return 0;
        }

        $since = ChatModerationRead::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->value('last_read_at');

        $since = $since !== null ? Carbon::parse($since) : $user->last_login_at;

        if ($since === null) {
            return 0;
        }

        return $conversation->messages()
            ->where('user_id', '!=', $user->id)
            ->where('created_at', '>', $since)
            ->count();
    }

    /**
     * Guarda hasta dónde llegó el administrador en una conversación que modera.
     *
     * Se escribe como mucho una vez por minuto para no golpear la base en cada
     * sondeo de mensajes.
     */
    private function markModerationRead(Conversation $conversation, User $user): void
    {
        if ($user->role !== UserRole::Admin) {
            return;
        }

        $conversation->loadMissing('users');

        if ($conversation->users->contains('id', $user->id)) {
            return;
        }

        $read = ChatModerationRead::query()->firstOrNew([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
        ]);

        if ($read->last_read_at !== null && $read->last_read_at->gt(now()->subMinute())) {
            return;
        }

        $read->last_read_at = now();
        $read->save();
    }

    /**
     * Al leer una conversación, sus notificaciones de chat dejan de contar
     * como nuevas. Así la campana y la burbuja del chat muestran lo mismo.
     */
    private function markChatNotificationsRead(Conversation $conversation, User $user): void
    {
        $unread = $user->unreadNotifications()
            ->whereIn('type', ['chat.mensaje', 'chat.mention'])
            ->get()
            ->filter(fn ($notification) => (int) ($notification->data['conversation_id'] ?? 0) === (int) $conversation->id);

        if ($unread->isEmpty()) {
            return;
        }

        $user->unreadNotifications()
            ->whereIn('id', $unread->pluck('id')->all())
            ->update(['read_at' => now()]);

        $this->firestore->markPushesRead($unread);
    }

    /**
     * Etiquetas disponibles en una conversación.
     *
     * Solo los miembros de la conversación (más los atajos propios del tipo de
     * conversación). `kind` decide el color con el que se resalta la etiqueta
     * en el chat: azul para participantes, púrpura para el dueño del grupo y
     * rojo para el rol de administrador.
     *
     * @return array<int, array{token:string,text?:string,name:string,label:string,kind:string}>
     */
    private function mentionables(Conversation $conversation): array
    {
        $conversation->loadMissing('users');

        $isGroup = $conversation->isGroup();
        $items = [];

        // En el amigo secreto no se revela quién es el otro: solo se puede
        // etiquetar el seudónimo, igual que el título de la conversación.
        if ($conversation->type === 'secret') {
            return [[
                'token' => 'amigosecreto',
                'text' => 'AmigoSecreto',
                'name' => 'Amigo secreto',
                'label' => 'Amigo secreto',
                'kind' => 'secret',
            ]];
        }

        foreach ($conversation->users as $member) {
            $token = mb_strtolower(trim((string) $member->username));

            if ($token === '') {
                continue;
            }

            if ($member->role === UserRole::Admin) {
                $kind = 'admin';
                $label = 'Administrador';
            } elseif ($isGroup && $conversation->created_by === $member->id) {
                $kind = 'owner';
                $label = 'Dueño del grupo';
            } else {
                $kind = 'member';
                $label = 'Participante';
            }

            $items[$token] = [
                'token' => $token,
                'name' => $member->name,
                'label' => $label,
                'kind' => $kind,
            ];
        }

        if ($isGroup) {
            $items['everyone'] = ['token' => 'everyone', 'name' => 'Todos', 'label' => 'Todos', 'kind' => 'broadcast'];
            $items['here'] = ['token' => 'here', 'name' => 'En línea', 'label' => 'En línea', 'kind' => 'broadcast'];
        }

        if ($conversation->type === 'secret') {
            $items['amigosecreto'] = [
                'token' => 'amigosecreto',
                'name' => 'Amigo secreto',
                'label' => 'Amigo secreto',
                'kind' => 'secret',
            ];
        }

        return array_values($items);
    }

    /**
     * Último mensaje de la conversación, cacheado durante la petición.
     */
    private function lastMessageFor(Conversation $conversation): ?ChatMessage
    {
        if (! array_key_exists($conversation->id, $this->lastMessageCache)) {
            $this->lastMessageCache[$conversation->id] = $conversation->messages()->latest('id')->first();
        }

        return $this->lastMessageCache[$conversation->id];
    }

    /**
     * Ids de los usuarios que bloqueó quien hace la petición.
     *
     * @return array<int, int>
     */
    private function blockedIds(User $user): array
    {
        $this->blockedCache ??= [];

        if (! array_key_exists($user->id, $this->blockedCache)) {
            $this->blockedCache[$user->id] = ChatBlock::query()
                ->where('blocker_id', $user->id)
                ->pluck('blocked_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->blockedCache[$user->id];
    }

    /**
     * Ids de quienes bloquearon a este usuario.
     *
     * @return array<int, int>
     */
    private function blockedByIds(User $user): array
    {
        $this->blockedByCache ??= [];

        if (! array_key_exists($user->id, $this->blockedByCache)) {
            $this->blockedByCache[$user->id] = ChatBlock::query()
                ->where('blocked_id', $user->id)
                ->pluck('blocker_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->blockedByCache[$user->id];
    }

    /**
     * Conversación directa entre dos personas: la reutiliza si existe y la
     * crea si es la primera vez.
     */
    private function directConversationWith(User $current, User $other): Conversation
    {
        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('users', fn ($query) => $query->whereKey($current->id))
            ->whereHas('users', fn ($query) => $query->whereKey($other->id))
            ->with('users')
            ->get()
            ->first(fn (Conversation $item) => $item->users->count() === 2);

        if ($conversation === null) {
            $conversation = Conversation::create([
                'type' => 'direct',
                'created_by' => $current->id,
            ]);

            $conversation->users()->attach([$current->id, $other->id]);
            $conversation->load('users');
        }

        return $conversation;
    }

    /**
     * Silenciar a una persona desde la pestaña Usuarios.
     *
     * Mientras esté silenciada, sus mensajes no suman avisos ni en el
     * contador. Volver a pulsar quita el silencio.
     */
    public function silenciarUsuario(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        $current = $request->user();

        if ((int) $current->id === (int) $user->id) {
            return api_error('No puedes silenciarte a ti mismo.', 422);
        }

        $conversation = $this->directConversationWith($current, $user);
        $pivot = $this->pivotFor($conversation, $current);
        $muted = $pivot?->muted_at === null;

        $conversation->users()->updateExistingPivot($current->id, [
            'muted_at' => $muted ? now() : null,
        ]);

        return api_success($muted ? 'Usuario silenciado.' : 'Silencio quitado.', [
            'muted' => $muted,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Reportar a una persona desde la pestaña Usuarios.
     *
     * Si ya hay mensajes con ella, el reporte se enlaza al último para que la
     * moderación vea el contexto. Con la persona bloqueada no se puede
     * reportar: primero hay que desbloquearla.
     */
    public function reportarUsuario(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        $current = $request->user();

        if ((int) $current->id === (int) $user->id) {
            return api_error('No puedes reportarte a ti mismo.', 422);
        }

        if (ChatBlock::existsBetween($current->id, $user->id)) {
            return api_error('Desbloquea a la persona antes de reportarla.', 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $conversation = $this->directConversationWith($current, $user);
        $lastMessageId = $conversation->messages()->latest('id')->value('id');
        $reason = trim((string) ($validator->validated()['reason'] ?? ''));

        if ($reason === '') {
            $reason = 'Reporte del usuario '.$user->name;
        }

        $report = ChatReport::query()->firstOrCreate(
            ['message_id' => $lastMessageId, 'reporter_id' => $current->id],
            ['reason' => $reason]
        );

        if (! $report->wasRecentlyCreated) {
            return api_success('Este reporte ya estaba registrado.');
        }

        $this->firestore->recordReport($report);

        $reports = $this->reportConversation();
        $reportMessage = $reports->messages()->create([
            'user_id' => $current->id,
            'body' => 'Usuario reportado: '.$current->name.' reportó a '.$user->name
                .'. Motivo: '.$reason
                .'. Fecha: '.now()->format('d/m/Y H:i'),
            'is_moderation' => true,
        ]);

        $this->firestore->recordMessage($reportMessage->load('user', 'attachments', 'conversation'));
        $this->firestore->recordConversation($reports->fresh('users'));

        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            if ((int) $admin->id === (int) $current->id) {
                continue;
            }

            send_mail(
                $admin->email,
                'Usuario reportado en el chat',
                'Nuevo reporte de usuario',
                [
                    $current->name.' reportó a '.$user->name.'.',
                    'Motivo: '.$reason,
                    'Fecha: '.now()->format('d/m/Y H:i'),
                ],
                null,
                [
                    'recipient_name' => $admin->name,
                    'eyebrow' => 'Reporte',
                    'preheader' => 'Reporte de usuario',
                    'source' => 'reporte',
                ]
            );
        }

        $this->push->sendToAdmins(
            'chat.report',
            'Usuario reportado',
            $current->name.' reportó a '.$user->name.' por: '.$reason,
            [
                'report_id' => $report->id,
                'report_type' => 'user',
                'conversation_id' => $conversation->id,
                'reporter' => $current->name,
                'reported_user' => $user->name,
                'reason' => $reason,
            ],
            module_url('moderacion'),
            'reporte',
            $current
        );

        return api_success('Reporte enviado a la administración.');
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(Conversation $conversation, User $user, bool $forModeration = false): array
    {
        $pivot = $this->pivotFor($conversation, $user);
        $clearedAt = $pivot?->cleared_at;
        // Se consulta una sola vez por conversación y petición: el resumen del
        // administrador pinta la misma conversación en dos pestañas.
        $last = $this->lastMessageFor($conversation);

        // Lo que borró de su lado no se le vuelve a mostrar como vista previa.
        if ($last !== null && $clearedAt !== null && $last->created_at !== null && $last->created_at->lt($clearedAt)) {
            $last = null;
        }

        $lastPreview = null;

        if ($last !== null && $last->isDeleted()) {
            // Un mensaje eliminado no deja ver su texto ni su adjunto.
            $lastPreview = 'Mensaje eliminado';
        } elseif ($last !== null) {
            $last->loadMissing('attachments');
            $body = trim(strip_tags((string) $last->body));
            $attachments = $last->attachments;
            $attachmentCount = $attachments->count();

            if ($body !== '') {
                $lastPreview = $body;
            } elseif ($attachmentCount > 0) {
                $first = $attachments->first();
                $extension = strtolower(pathinfo((string) $first->name, PATHINFO_EXTENSION));
                $type = $first->type;

                if ($extension === 'pdf') {
                    $type = 'pdf';
                }

                $labels = [
                    'image' => ['foto', 'fotos'],
                    'video' => ['video', 'videos'],
                    'audio' => ['audio', 'audios'],
                    'pdf' => ['PDF', 'PDF'],
                    'file' => ['archivo', 'archivos'],
                    'contact' => ['contacto', 'contactos'],
                ];

                $label = $labels[$type] ?? ['archivo', 'archivos'];
                $lastPreview = $attachmentCount > 1
                    ? $attachmentCount.' '.$label[1]
                    : ucfirst($label[0]);
            } elseif ($last->type === 'location') {
                $lastPreview = 'Ubicación';
            } elseif ($last->link_url) {
                $lastPreview = $last->link_title ?: $last->link_url;
            }
        }

        $unread = $forModeration
            ? $this->moderationUnreadCount($conversation, $user)
            : $this->unreadCount($conversation, $user);
        $muted = $pivot !== null && $pivot->muted_at !== null;
        $others = $conversation->users->where('id', '!=', $user->id);
        $other = $others->first();
        $blocked = ! $forModeration
            && ! $conversation->isGroup()
            && $other !== null
            && in_array((int) $other->id, $this->blockedIds($user), true);
        $blockedByThem = ! $forModeration
            && ! $conversation->isGroup()
            && $other !== null
            && in_array((int) $other->id, $this->blockedByIds($user), true);
        $onlineUsers = $conversation->users->filter(fn (User $item) => $item->last_seen_at !== null && $item->last_seen_at->gte(now()->subMinutes(5)))->count();

        if ($conversation->isGroup()) {
            $subtitle = $onlineUsers.' en línea';
        } elseif ($conversation->isEventChannel()) {
            $subtitle = 'Avisos del evento · solo lectura';
        } elseif ($conversation->type === 'secret') {
            $subtitle = $conversation->secretRoleFor($user) === 'giver'
                ? 'Tu amigo secreto asignado'
                : 'Amigo secreto incógnito';
        } elseif ($other === null) {
            $subtitle = 'Conversación';
        } elseif ($other->last_seen_at !== null && $other->last_seen_at->gte(now()->subMinutes(5))) {
            $subtitle = 'En línea';
        } elseif ($other->last_seen_at !== null) {
            $subtitle = 'Última vez '.$other->last_seen_at->diffForHumans();
        } else {
            $subtitle = 'Desconectado';
        }

        return [
            'id' => $conversation->id,
            'title' => $forModeration
                ? ($conversation->isGroup() ? ($conversation->name ?: 'Grupo') : $conversation->users->pluck('name')->implode(' / '))
                : $conversation->titleFor($user),
            'type' => $conversation->type,
            'is_group' => $conversation->isGroup(),
            'subtitle' => $subtitle,
            'online_count' => $onlineUsers,
            'last_message' => $lastPreview,
            'last_sender' => $last?->is_moderation ? 'Moderación' : $last?->user?->name,
            'unread' => $unread,
            'muted' => $muted,
            'blocked' => $blocked,
            'blocked_by' => $blockedByThem,
            'is_owner' => $conversation->created_by === $user->id,
            'other_user_id' => ! $forModeration && ! $conversation->isGroup() ? $other?->id : null,
            'updated_at' => $conversation->updated_at?->diffForHumans(),
            'updated_iso' => $conversation->updated_at?->toIso8601String(),
            'favorite' => $pivot?->favorite_at !== null,
            'pinned' => $pivot?->pinned_at !== null,
            'archived' => $pivot?->archived_at !== null,
            'hidden' => $pivot?->hidden_at !== null,
            'is_moderation' => $forModeration,
            'read_only' => $conversation->is_read_only,
            'is_event_channel' => $conversation->isEventChannel(),
            'can_archive' => ! $forModeration && $conversation->type === 'direct',
            'can_unarchive' => ! $forModeration && $conversation->canBeUnarchived(),
            'pinned_message' => $this->pinnedMessageData($conversation, $user),
        ];
    }

    /**
     * Mensaje fijado dentro de la conversación, para el cintillo.
     *
     * @return array{id:int,body:string,sender:string}|null
     */
    private function pinnedMessageData(Conversation $conversation, ?User $user = null): ?array
    {
        if ($conversation->pinned_message_id === null) {
            return null;
        }

        $message = $conversation->pinnedMessage;

        if ($message === null || $message->isDeleted()) {
            return null;
        }

        // Si el usuario vació el chat, el mensaje fijado anterior tampoco debe
        // seguir viéndose en el cintillo.
        if ($user !== null) {
            $clearedAt = $this->pivotFor($conversation, $user)?->cleared_at;

            if ($clearedAt !== null && $message->created_at !== null && $message->created_at->lt($clearedAt)) {
                return null;
            }
        }

        $body = trim(strip_tags((string) $message->body));

        if ($body === '') {
            $body = $message->attachment_name ?: 'Mensaje adjunto';
        }

        return [
            'id' => (int) $message->id,
            'body' => Str::limit($body, 160),
            'sender' => $message->is_moderation ? 'Moderación' : ($message->user?->name ?? 'Sistema'),
        ];
    }
}
