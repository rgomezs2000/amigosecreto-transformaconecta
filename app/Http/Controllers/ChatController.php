<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatBlock;
use App\Models\ChatGroupAudit;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatMessageFavorite;
use App\Models\ChatModerationRead;
use App\Models\ChatPoll;
use App\Models\ChatPollOption;
use App\Models\ChatPollVote;
use App\Models\ChatReaction;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\GroupBan;
use App\Models\GroupSuspension;
use App\Models\Module;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use App\Services\PushNotifier;
use App\Services\ReporteAdjuntos;
use App\Services\ReporteAvisos;
use App\Services\ReporteChat;
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
        private readonly ReporteChat $reportes,
        private readonly ReporteAvisos $avisosReporte,
        private readonly ReporteAdjuntos $reporteAdjuntos,
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
            ->withCount('broadcastRecipients')
            ->orderByDesc('updated_at')
            ->get();

        $items = $conversations->map(fn (Conversation $conversation) => $this->conversationData($conversation, $user));

        // Los chats de caso (tickets) y las difusiones tienen su propia
        // pestaña y no se mezclan con las conversaciones normales.
        $reportItems = $items->filter(fn (array $item) => $item['is_report'])->values();
        $broadcastItems = $items
            ->filter(fn (array $item) => $item['is_broadcast'])
            ->filter(fn (array $item) => ! $item['hidden'] || $item['unread'] > 0)
            ->values();
        $regular = $items
            ->reject(fn (array $item) => $item['is_report'])
            ->reject(fn (array $item) => $item['is_broadcast']);

        // Las archivadas van a su propia pestaña; las eliminadas salen de la
        // lista principal salvo que tengan mensajes nuevos.
        $archived = $regular->filter(fn (array $item) => $item['archived'])->values();
        $active = $regular
            ->reject(fn (array $item) => $item['archived'])
            ->filter(fn (array $item) => ! $item['hidden'] || $item['unread'] > 0)
            ->values();

        $moderation = [];

        if ($user->role === UserRole::Admin) {
            $moderation = Conversation::query()
                ->with(['users', 'assignment.receiver', 'pinnedMessage.user'])
                ->withCount('broadcastRecipients')
                // Los chats de caso y las difusiones son privados: no entran en
                // la moderación general ni en el canal antiguo de reportes.
                ->whereNotIn('type', ['report', 'reports', 'broadcast', 'system'])
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
            'reports' => $this->sortedConversations($reportItems),
            'broadcasts' => $this->sortedConversations($broadcastItems),
            'moderation' => $moderation,
            'users' => $users,
            'unread' => $active->sum(fn (array $item) => $item['muted'] ? 0 : $item['unread'])
                + $reportItems->sum(fn (array $item) => $item['muted'] ? 0 : $item['unread']),
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
                // Los chats de caso son privados y el canal antiguo de
                // reportes ya no se usa: ninguno cuenta como moderación.
                ->whereNotIn('type', ['report', 'reports'])
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
        $ids = collect($validator->validated()['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $blockedPeople = $ids->reject(fn ($id) => $id === (int) $user->id)
            ->filter(fn ($id) => ChatBlock::existsBetween($user->id, $id));

        if ($blockedPeople->isNotEmpty()) {
            return api_error('No puedes agregar a una persona bloqueada al grupo.', 422);
        }

        try {
            $conversation = Conversation::create([
                'name' => $validator->validated()['name'],
                'type' => 'group',
                'created_by' => $user->id,
                'is_moderated' => true,
            ]);

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
     * Crea una sala cerrada: un grupo con usuarios elegidos, sin enlace de
     * invitación ni vetos. Necesita al menos tres personas en total.
     */
    public function sala(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'user_ids' => ['required', 'array', 'min:2', 'max:50'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ], [
            'user_ids.required' => 'Elige al menos dos personas para la sala.',
            'user_ids.min' => 'Una sala necesita al menos tres personas contando contigo.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $data = $validator->validated();

        $ids = collect($data['user_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $user->id)
            ->unique()
            ->values();

        if ($ids->count() < 2) {
            return api_error('Una sala necesita al menos tres personas contando contigo.', 422);
        }

        $active = User::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->count();

        if ($active !== $ids->count()) {
            return api_error('Alguna de las personas elegidas ya no está activa.', 422);
        }

        $blockedPeople = $ids->filter(fn ($id) => ChatBlock::existsBetween($user->id, $id));

        if ($blockedPeople->isNotEmpty()) {
            return api_error('No puedes crear una sala con una persona bloqueada.', 422);
        }

        try {
            $conversation = Conversation::create([
                'name' => null,
                'type' => 'group',
                'created_by' => $user->id,
                'is_moderated' => true,
                'is_closed' => true,
            ]);

            $conversation->users()->attach($user->id, ['role' => 'moderator']);

            foreach ($ids as $id) {
                $conversation->users()->attach($id, ['role' => 'member']);
            }

            $conversation->load('users');

            $this->firestore->recordConversation($conversation);

            return api_success('Sala creada.', [
                'conversation' => $this->conversationData($conversation, $user),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear la sala', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear la sala. Intenta de nuevo.', 500);
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
        // Ventana centrada en un mensaje: la usa la búsqueda al abrir un
        // resultado que queda fuera de los últimos cien mensajes cargados.
        $focusId = max(0, (int) $request->query('focus_id', 0));
        $focus = $focusId > 0;
        $full = ! $focus && $afterId === 0 && ! $patchOnly;

        $clearedAt = $conversation->users->firstWhere('id', $user->id)?->pivot->cleared_at;

        $base = function () use ($conversation, $clearedAt) {
            $query = $conversation->messages()->with([
                'user',
                'attachments',
                'replyTo.user',
                'conversation.users',
                'poll.options',
                'poll.votes.user',
                'event' => fn ($query) => $query->withCount('participants'),
            ]);

            if ($clearedAt !== null) {
                // Se incluye el mismo segundo del borrado para no ocultar mensajes nuevos.
                $query->where('created_at', '>=', $clearedAt);
            }

            return $query;
        };

        if ($focus) {
            $target = $base()->whereKey($focusId)->first();

            if ($target === null) {
                return api_error('El mensaje ya no está disponible.', 404);
            }

            // Ventana simétrica: cuarenta mensajes antes del encontrado y
            // cuarenta después, para que el salto tenga contexto sin cargar
            // toda la conversación.
            $before = $base()->where('id', '<=', $focusId)->latest('id')->limit(40)->get()->reverse()->values();
            $after = $base()->where('id', '>', $focusId)->orderBy('id')->limit(40)->get();

            $messages = $before->concat($after)->unique('id')->values();
        } elseif ($full) {
            $messages = $base()->latest('id')->limit(100)->get()->reverse()->values();
        } elseif ($patchOnly) {
            $messages = collect();
        } else {
            $messages = $base()->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
        }

        // Ventana de estado: el cliente la compara con lo que ya pintó y solo
        // actualiza las burbujas que cambiaron.
        if ($full || $focus) {
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
        $favorites = $this->favoriteMessageIds($all, $user);

        // En el amigo secreto no se etiqueta el rol: revelaría quién es.
        $hideAdminBadge = $conversation->type === 'secret';

        $map = fn (ChatMessage $message) => $this->messageData(
            $message,
            $user,
            $others,
            $reactions,
            $reported,
            $favorites,
            $this->canDeleteContent($message, $user, $conversation),
            $clearedAt,
            $hideAdminBadge
        );

        $recentPayload = $recent->map($map)->values();

        return api_success('Mensajes.', [
            'delta' => ! $full && ! $focus,
            // Estado de escritura en cada respuesta: si el caso se cierra con
            // el chat abierto, se deshabilita sin recargar.
            'read_only' => (bool) $conversation->is_read_only,
            'read_only_label' => $this->readOnlyLabel($conversation),
            'conversation' => ($full || $focus) ? $this->conversationData($conversation->fresh('users'), $user) : null,
            'messages' => $messages->map($map)->values(),
            'recent' => $recentPayload,
            'recent_hash' => $recentPayload->isEmpty() ? null : md5((string) json_encode($recentPayload)),
            'latest_id' => (int) (($messages->last() ?? $recent->last())?->id ?? 0),
            'new_count' => $newCount,
            'mentionables' => ($full || $focus) ? $this->mentionables($conversation) : [],
        ]);
    }

    /**
     * Busca texto en todo el historial de la conversación.
     *
     * La barra del chat consulta aquí mientras se escribe. Se respeta lo que el
     * usuario vació de su lado y nunca se devuelven mensajes eliminados. El
     * término se recorta y se escapan los comodines de SQL.
     */
    public function buscar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $term = trim((string) preg_replace('/\s+/u', ' ', (string) $request->query('q', '')));

        if (mb_strlen($term) > 100) {
            $term = mb_substr($term, 0, 100);
        }

        // Menos de dos letras devolvería casi toda la conversación: la barra
        // muestra la pista y no consulta.
        if (mb_strlen($term) < 2) {
            return api_success('Escribe al menos dos caracteres.', [
                'query' => $term,
                'items' => [],
                'total' => 0,
                'limited' => false,
            ]);
        }

        $clearedAt = $conversation->users->firstWhere('id', $user->id)?->pivot->cleared_at;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query = $conversation->messages()
            ->with(['user', 'attachments', 'poll', 'event'])
            ->whereNull('deleted_at')
            ->where(function ($query) use ($like) {
                $query->where('body', 'like', $like)
                    ->orWhere('attachment_name', 'like', $like)
                    ->orWhere('link_title', 'like', $like);
            })
            ->when($clearedAt !== null, fn ($query) => $query->where('created_at', '>=', $clearedAt));

        $total = (clone $query)->count();
        $limit = 50;
        $messages = $query->latest('id')->limit($limit)->get();

        $items = $messages->map(function (ChatMessage $message) use ($term, $user, $conversation) {
            $ocultarRemitente = $conversation->type === 'secret'
                && ! $message->is_moderation
                && $message->user_id !== null
                && (int) $message->user_id !== (int) $user->id
                && $conversation->secretRoleFor($user) === 'receiver';

            return [
                'id' => (int) $message->id,
                'body' => $this->searchSnippet($message, $term),
                'sender' => $ocultarRemitente
                    ? 'Amigo secreto'
                    : ($message->is_moderation
                        ? ($message->user?->name ?? 'Moderación')
                        : ($message->user?->name ?? 'Sistema')),
                'sender_id' => $ocultarRemitente ? null : $message->user_id,
                'sender_is_system' => $message->user === null && ! $message->is_moderation,
                'is_moderation' => (bool) $message->is_moderation,
                'mine' => $message->user_id === $user->id,
                'created_at' => $message->created_at?->format('H:i'),
                'created_date' => $message->created_at?->format('Y-m-d'),
                'type' => $message->type,
                'has_attachment' => $message->attachments->isNotEmpty() || $message->attachment_path !== null,
            ];
        })->values();

        return api_success('Resultados de la búsqueda.', [
            'query' => $term,
            'items' => $items,
            'total' => $total,
            'limited' => $total > $items->count(),
        ]);
    }

    /**
     * Recorte del mensaje alrededor de la primera coincidencia, listo para
     * pintarse con la palabra resaltada en los resultados.
     */
    private function searchSnippet(ChatMessage $message, string $term): string
    {
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags((string) $message->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        if ($text === '' && $message->attachment_name) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $message->attachment_name));
        }

        if ($text === '' && $message->link_title) {
            $text = trim((string) $message->link_title);
        }

        if ($text === '') {
            if ($message->type === 'poll' && $message->poll !== null) {
                $text = 'Encuesta: '.trim((string) $message->poll->question);
            } elseif ($message->event !== null) {
                $text = 'Evento: '.trim((string) $message->event->name);
            } else {
                $text = html_entity_decode(
                    $this->messageQuote($message),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }
        }

        $position = mb_stripos($text, $term);

        if ($position === false) {
            return Str::limit($text, 180);
        }

        $start = max(0, $position - 60);
        $snippet = mb_substr($text, $start, 220);

        if ($start > 0) {
            $snippet = '…'.$snippet;
        }

        if (mb_strlen($text) > $start + 220) {
            $snippet .= '…';
        }

        return $snippet;
    }

    /**
     * Payload de un mensaje para el widget.
     *
     * @param  Collection<int, User>  $others
     * @param  array<int, array<int, array{emoji:string,count:int,mine:bool}>>  $reactions
     * @param  array<int, int>  $reported
     * @return array<string, mixed>
     */
    private function messageData(ChatMessage $message, User $user, $others, array $reactions, array $reported, array $favorites = [], bool $canDelete = false, $clearedAt = null, bool $hideAdminBadge = false): array
    {
        $deleted = $message->isDeleted();
        $read = $others->isNotEmpty() && $others->every(function ($other) use ($message) {
            $lastRead = $other->pivot->last_read_at;

            return $lastRead !== null
                && Carbon::parse($lastRead)->gte($message->created_at);
        });

        $conversation = $message->conversation;
        $esSecreto = $conversation?->type === 'secret';
        // Quien recibe el regalo no debe saber quién le escribe: el nombre del
        // remitente se oculta en su vista. Quien regala sí conoce a su amigo.
        $ocultarRemitente = $esSecreto
            && ! $message->is_moderation
            && $message->user_id !== null
            && (int) $message->user_id !== (int) $user->id
            && $conversation?->secretRoleFor($user) === 'receiver';

        return [
            'id' => $message->id,
            'body' => $deleted ? '' : $message->body,
            'sender' => $ocultarRemitente
                ? 'Amigo secreto'
                : ($message->is_moderation
                    ? ($message->user?->name ?? 'Moderación')
                    : ($message->user?->name ?? 'Sistema')),
            'sender_id' => $ocultarRemitente ? null : $message->user_id,
            'sender_is_system' => $message->user === null && ! $message->is_moderation,
            'conversation_type' => $conversation?->type,
            // Administrador del sistema: se marca junto al nombre. Un mensaje
            // de moderación ya dice «Moderación» y no necesita la etiqueta.
            'sender_is_admin' => ! $ocultarRemitente
                && ! $hideAdminBadge
                && ! $message->is_moderation
                && $message->user?->role === UserRole::Admin,
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
            'pinned' => ! $deleted && $message->pinned_at !== null,
            'broadcast' => ! $deleted && $message->is_broadcast,
            'deleted' => $deleted,
            'deleted_label' => $deleted
                ? ($message->deleted_by_admin ? 'Eliminado por un administrador' : 'Eliminado por el autor')
                : null,
            'reactions' => $reactions[$message->id] ?? [],
            'can_edit' => $message->user_id === $user->id && ! $deleted,
            'can_delete' => ! $deleted && $canDelete,
            'reported' => in_array((int) $message->id, $reported, true),
            'favorite' => in_array((int) $message->id, $favorites, true),
            // Cita del mensaje respondido: se pinta dentro de la burbuja para
            // que la respuesta se entienda sin salir de ella.
            'reply' => $this->replyQuote($message, $clearedAt, $hideAdminBadge, $user),
            'forwarded' => $message->isForwarded(),
            // Encuestas y eventos: la burbuja trae todo lo necesario para
            // pintarse y, en las encuestas, para votar.
            'poll' => ! $deleted && $message->type === 'poll' && $message->poll !== null
                ? $this->pollData(
                    $message->poll,
                    $message,
                    $user,
                    $message->conversation?->users->contains('id', $user->id) ?? false
                )
                : null,
            'event' => ! $deleted && $message->event !== null
                ? $this->eventData($message->event)
                : null,
        ];
    }

    /**
     * Datos del mensaje citado por una respuesta, ya recortados para la
     * burbuja. No expone nada que el usuario no esté viendo ya: la respuesta
     * solo puede apuntar a un mensaje de la misma conversación.
     *
     * @return array{id:int|null,sender:string,sender_is_admin:bool,body:string,deleted:bool}|null
     */
    private function replyQuote(ChatMessage $message, $clearedAt = null, bool $hideAdminBadge = false, ?User $user = null): ?array
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
                'sender_is_admin' => false,
                'body' => 'El mensaje original ya no está disponible.',
                'deleted' => true,
            ];
        }

        $conversation = $original->conversation;
        $ocultarRemitente = $user !== null
            && $conversation?->type === 'secret'
            && ! $original->is_moderation
            && (int) $original->user_id !== (int) $user->id
            && $conversation?->secretRoleFor($user) === 'receiver';

        return [
            'id' => (int) $original->id,
            'sender' => $ocultarRemitente
                ? 'Amigo secreto'
                : ($original->is_moderation ? 'Moderación' : ($original->user?->name ?? 'Sistema')),
            'sender_is_admin' => ! $ocultarRemitente
                && ! $hideAdminBadge
                && ! $original->is_moderation
                && $original->user?->role === UserRole::Admin,
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

        // Nombres de quienes reaccionaron, para el globo de ayuda.
        $voters = ChatReaction::query()
            ->whereIn('message_id', $ids)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ChatReaction $reaction) => $reaction->message_id.'|'.$reaction->emoji);

        $summary = [];

        foreach ($totals->groupBy('message_id') as $messageId => $rows) {
            $myEmojis = $mine->has($messageId) ? $mine->get($messageId)->pluck('emoji')->all() : [];

            $summary[(int) $messageId] = $rows->map(function ($row) use ($myEmojis, $voters, $messageId) {
                $names = $voters->get($messageId.'|'.$row->emoji, collect())
                    ->map(fn (ChatReaction $reaction) => $reaction->user?->name)
                    ->filter()
                    ->values()
                    ->take(3)
                    ->all();

                return [
                    'emoji' => $row->emoji,
                    'count' => (int) $row->total,
                    'mine' => in_array($row->emoji, $myEmojis, true),
                    'names' => $names,
                    'tooltip' => $this->reactionTooltip($names, $row->emoji, (int) $row->total),
                ];
            })->values()->all();
        }

        return $summary;
    }

    /**
     * Texto del globo de una reacción: quiénes reaccionaron y con qué emoji.
     *
     * @param  array<int, string>  $names
     */
    private function reactionTooltip(array $names, string $emoji, int $total): string
    {
        if ($total <= 1 || count($names) === 1) {
            return ($names[0] ?? 'Alguien').' ha reaccionado con: '.$emoji;
        }

        if ($total > 3) {
            $who = $names[0].', '.$names[1].', '.$names[2].' y '.($total - 3).' más';
        } elseif (count($names) === 2) {
            $who = $names[0].' y '.$names[1];
        } else {
            $who = $names[0].', '.$names[1].' y '.$names[2];
        }

        return $who.' han reaccionado con: '.$emoji;
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

    private function favoriteMessageIds($messages, User $user): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        return ChatMessageFavorite::query()
            ->whereIn('message_id', $messages->pluck('id')->all())
            ->where('user_id', $user->id)
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
                $iBlocked = in_array((int) $other->id, $this->blockedIds($user), true);
                $theyBlocked = in_array((int) $other->id, $this->blockedByIds($user), true);

                if ($iBlocked && $theyBlocked) {
                    return api_error(
                        'Has bloqueado a '.$other->name.' y '.$other->name.' te ha bloqueado. No pueden enviarse mensajes.',
                        403
                    );
                }

                if ($iBlocked) {
                    return api_error(
                        'Has bloqueado a '.$other->name.'. Desbloquea para enviar mensajes.',
                        403
                    );
                }

                return api_error(
                    $other->name.' te ha bloqueado. No puedes enviarle mensajes.',
                    403
                );
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
                'broadcast' => 'broadcasts',
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

            $isModeration = $user->role === UserRole::Admin
                && ($request->boolean('moderation') || ! $isMember);

            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'reply_to_id' => $replyTo?->id,
                'body' => $this->sanitizeBody($data['body'] ?? ''),
                'is_moderation' => $isModeration,
                'is_broadcast' => $conversation->type === 'broadcast',
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

            if ($conversation->type === 'broadcast') {
                $this->replicateBroadcastMessage($conversation, $message, $user);
            } else {
                $this->notifyConversation($conversation, $message, $user);
            }

            return api_success('Mensaje enviado.', [
                'message' => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'sender' => $message->is_moderation
                        ? ($user->name ?? 'Moderación')
                        : $user->name,
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
     * Crea una sala de difusión: conversación del dueño con una lista de
     * destinatarios. Los destinatarios no son miembros del chat; reciben copia
     * de cada mensaje en su conversación directa con el dueño.
     */
    public function crearBroadcast(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ], [
            'user_ids.required' => 'Elige al menos una persona.',
            'user_ids.min' => 'Elige al menos una persona.',
            'user_ids.max' => 'Puedes elegir hasta 50 destinatarios.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $owner = $request->user();
        $ids = collect($validator->validated()['user_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $owner->id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return api_error('Elige al menos una persona distinta de ti.', 422);
        }

        $recipients = User::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->get();

        if ($recipients->count() !== $ids->count()) {
            return api_error('Alguna de las personas elegidas ya no está activa.', 422);
        }

        $blocked = $recipients->filter(fn (User $target) => ChatBlock::existsBetween($owner->id, $target->id));

        if ($blocked->isNotEmpty()) {
            return api_error(
                'No puedes difundir a '.$blocked->pluck('name')->implode(', ').' mientras haya un bloqueo.',
                422
            );
        }

        try {
            $conversation = Conversation::create([
                'name' => null,
                'type' => 'broadcast',
                'created_by' => $owner->id,
                'is_moderated' => false,
            ]);

            // El dueño es el único miembro de la conversación. Los
            // destinatarios viven en chat_broadcast_recipients.
            $conversation->users()->attach($owner->id, ['role' => 'moderator']);
            $conversation->broadcastRecipients()->attach($recipients->pluck('id')->all());

            $conversation->load('users');
            $conversation->loadCount('broadcastRecipients');

            $this->firestore->recordConversation($conversation);

            return api_success('Difusión creada.', [
                'conversation' => $this->conversationData($conversation, $owner),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear la difusión', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear la difusión. Intenta de nuevo.', 500);
        }
    }

    /**
     * Copia el mensaje de una sala de difusión en el chat directo de cada
     * destinatario. No agrega a nadie a la conversación de difusión.
     */
    private function replicateBroadcastMessage(Conversation $broadcast, ChatMessage $message, User $sender): void
    {
        $recipients = $broadcast->broadcastRecipients()->get();

        foreach ($recipients as $recipient) {
            if (ChatBlock::existsBetween($sender->id, $recipient->id)) {
                continue;
            }

            try {
                $target = $this->directConversationWith($sender, $recipient);
                $target->load('users');

                $copy = $this->forwardMessage($target, $message, $sender, true);
                ChatMentions::handle($copy, $target, $sender);

                $target->touch();
                $target->users()->updateExistingPivot($sender->id, [
                    'hidden_at' => null,
                    'last_read_at' => now(),
                ]);

                $copy->load(['user', 'attachments', 'conversation']);

                $this->firestore->recordMessage($copy);
                $this->firestore->recordConversationActivity($target, $copy, $sender->name);
                $this->notifyConversation($target, $copy, $sender);
            } catch (Throwable $exception) {
                Log::error('Error al replicar la difusión', [
                    'recipient' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Crea una encuesta y la adjunta a un mensaje de la conversación.
     *
     * Solo en chats directos y salas de grupo: la moderación no crea
     * encuestas. La encuesta no obliga a votar y, si nadie marca una fecha de
     * cierre, caduca a las 24 horas.
     */
    public function crearEncuesta(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($memberError = $this->ensureMember($conversation, $user)) {
            return $memberError;
        }

        if (! in_array($conversation->type, ['direct', 'group'], true)) {
            return api_error('Las encuestas solo están disponibles en los chats directos y en las salas de grupo.', 422);
        }

        if ($sendError = $this->ensureCanStartChat($conversation, $user)) {
            return $sendError;
        }

        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'max:300'],
            'options' => ['required', 'array', 'min:2', 'max:10'],
            'options.*' => ['required', 'string', 'max:120'],
            'multiple' => ['nullable', 'boolean'],
            'hide_voters' => ['nullable', 'boolean'],
            'ends_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addYear()->toDateTimeString()],
        ], [
            'question.required' => 'Escribe la pregunta de la encuesta.',
            'question.max' => 'La pregunta no puede superar los 300 caracteres.',
            'options.required' => 'Agrega al menos dos respuestas.',
            'options.min' => 'La encuesta necesita al menos dos respuestas.',
            'options.max' => 'La encuesta admite como máximo diez respuestas.',
            'options.*.required' => 'Las respuestas no pueden estar en blanco.',
            'options.*.max' => 'Cada respuesta puede tener hasta 120 caracteres.',
            'ends_at.after' => 'La fecha de finalización debe ser posterior a ahora.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $question = trim((string) $data['question']);
        $options = [];

        foreach ((array) $data['options'] as $option) {
            $label = trim((string) $option);

            if ($label === '') {
                return api_error('Las respuestas no pueden estar en blanco.', 422);
            }

            $options[] = $label;
        }

        // Comparación sin distinguir mayúsculas ni acentos: dos respuestas
        // repetidas no aportan nada.
        $normalized = array_map(fn (string $label) => mb_strtolower($label), $options);

        if (count(array_unique($normalized)) !== count($options)) {
            return api_error('Las respuestas deben ser diferentes entre sí.', 422);
        }

        if (count($options) < 2) {
            return api_error('La encuesta necesita al menos dos respuestas.', 422);
        }

        if ($question === '') {
            return api_error('Escribe la pregunta de la encuesta.', 422);
        }

        try {
            $message = DB::transaction(function () use ($conversation, $user, $question, $options, $data) {
                $message = ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'body' => '',
                    'is_moderation' => false,
                    'type' => 'poll',
                ]);

                $poll = ChatPoll::create([
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'created_by' => $user->id,
                    'question' => $question,
                    'multiple' => (bool) ($data['multiple'] ?? false),
                    'hide_voters' => (bool) ($data['hide_voters'] ?? false),
                    // Sin fecha elegida la encuesta dura 24 horas.
                    'ends_at' => ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : now()->addDay(),
                ]);

                foreach ($options as $position => $label) {
                    $poll->options()->create([
                        'label' => $label,
                        'position' => $position,
                    ]);
                }

                return $message;
            });

            $this->afterNewMessage($conversation, $message, $user);

            return api_success('Encuesta creada.', [
                'message' => $this->createdMessagePayload($message, $user),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear la encuesta', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear la encuesta. Intenta de nuevo.', 500);
        }
    }

    /**
     * Registra el voto de un participante. Con selección múltiple se reemplaza
     * la selección completa; sin ella solo se admite una respuesta.
     */
    public function votarEncuesta(Request $request, ChatPoll $poll): JsonResponse
    {
        $this->ensureAccess();

        $conversation = $poll->conversation;

        if ($conversation === null) {
            return api_error('La encuesta ya no está disponible.', 404);
        }

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($memberError = $this->ensureMember($conversation, $user)) {
            return $memberError;
        }

        if (! in_array($conversation->type, ['direct', 'group'], true)) {
            return api_error('Las encuestas solo están disponibles en los chats directos y en las salas de grupo.', 422);
        }

        if ($poll->isClosed()) {
            return api_error('La encuesta ya está cerrada.', 422);
        }

        $validator = Validator::make($request->all(), [
            'options' => ['required', 'array', 'min:1'],
            'options.*' => ['integer'],
        ], [
            'options.required' => 'Elige al menos una respuesta.',
            'options.min' => 'Elige al menos una respuesta.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $poll->loadMissing('options');

        $selected = collect((array) $validator->validated()['options'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $valid = $poll->options->pluck('id')->map(fn ($id) => (int) $id);

        if ($selected->diff($valid)->isNotEmpty()) {
            return api_error('Alguna de las respuestas no pertenece a la encuesta.', 422);
        }

        if (! $poll->multiple && $selected->count() !== 1) {
            return api_error('Esta encuesta admite una sola respuesta.', 422);
        }

        try {
            DB::transaction(function () use ($poll, $user, $selected) {
                ChatPollVote::query()
                    ->where('poll_id', $poll->id)
                    ->where('user_id', $user->id)
                    ->delete();

                foreach ($selected as $optionId) {
                    ChatPollVote::create([
                        'poll_id' => $poll->id,
                        'option_id' => $optionId,
                        'user_id' => $user->id,
                    ]);
                }
            });

            return api_success('Voto registrado.', [
                'poll' => $this->pollData(
                    $poll->fresh(['options', 'votes.user']),
                    $poll->message,
                    $user,
                    true
                ),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al votar en la encuesta', ['error' => $exception->getMessage()]);

            return api_error('No se pudo registrar el voto. Intenta de nuevo.', 500);
        }
    }

    /**
     * Adjunta un evento del sistema a un mensaje de la conversación.
     *
     * Sirve cualquier evento existente, sin importar su etapa o estado.
     */
    public function adjuntarEvento(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($memberError = $this->ensureMember($conversation, $user)) {
            return $memberError;
        }

        if (! in_array($conversation->type, ['direct', 'group'], true)) {
            return api_error('Los eventos solo se pueden adjuntar en los chats directos y en las salas de grupo.', 422);
        }

        if ($sendError = $this->ensureCanStartChat($conversation, $user)) {
            return $sendError;
        }

        $validator = Validator::make($request->all(), [
            'event_id' => ['required', 'integer', 'exists:events,id'],
        ], [
            'event_id.required' => 'Elige el evento que quieres adjuntar.',
            'event_id.exists' => 'El evento ya no está disponible.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $event = Event::query()->find((int) $validator->validated()['event_id']);

        if ($event === null) {
            return api_error('El evento ya no está disponible.', 422);
        }

        try {
            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => '',
                'is_moderation' => false,
                'type' => 'event',
                'event_id' => $event->id,
            ]);

            $this->afterNewMessage($conversation, $message, $user);

            return api_success('Evento adjuntado.', [
                'message' => $this->createdMessagePayload($message, $user),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al adjuntar el evento', ['error' => $exception->getMessage()]);

            return api_error('No se pudo adjuntar el evento. Intenta de nuevo.', 500);
        }
    }

    /**
     * Eventos que se pueden adjuntar desde el chat: todos los creados, en
     * cualquier etapa (borrador, inscripción, sorteo, cerrado...).
     */
    public function eventosDisponibles(): JsonResponse
    {
        $this->ensureAccess();

        $events = Event::query()
            ->withCount('participants')
            ->orderByDesc('year')
            ->get()
            ->map(fn (Event $event) => $this->eventData($event))
            ->values();

        return api_success('Eventos disponibles.', ['events' => $events]);
    }

    /**
     * Ficha de un evento para la burbuja del chat, disponible para todos los
     * usuarios del chat (también administradores).
     */
    public function fichaEvento(Event $event): JsonResponse
    {
        $this->ensureAccess();

        $event->loadCount('participants');

        return api_success('Ficha del evento.', ['event' => $this->eventData($event)]);
    }

    /**
     * Comprobaciones comunes al abrir un tema nuevo (encuesta o evento):
     * bloqueos y suspensiones del grupo.
     */
    private function ensureCanStartChat(Conversation $conversation, User $user): ?JsonResponse
    {
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

        return null;
    }

    /**
     * Efectos comunes después de crear un mensaje desde una acción del chat
     * (encuesta o evento): marcar leído, avisar a Firestore y notificar.
     */
    private function afterNewMessage(Conversation $conversation, ChatMessage $message, User $user): void
    {
        $conversation->touch();

        if ($conversation->users->contains('id', $user->id)) {
            $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);
        }

        $message->load(['user', 'attachments', 'conversation.users', 'poll.options', 'poll.votes.user', 'event']);

        $this->firestore->recordMessage($message);
        $this->firestore->recordConversationActivity(
            $conversation,
            $message,
            $message->is_moderation ? 'Moderación' : $user->name
        );

        $this->notifyConversation($conversation, $message, $user);
    }

    /**
     * Datos del mensaje recién creado, con el mismo formato que el resto del
     * historial para que el cliente lo pinte sin recargar.
     */
    private function createdMessagePayload(ChatMessage $message, User $user): array
    {
        $message->loadMissing(['user', 'attachments', 'conversation.users', 'poll.options', 'poll.votes.user', 'event']);

        $others = $message->conversation->users->where('id', '!=', $user->id);

        return $this->messageData(
            $message,
            $user,
            $others,
            [],
            [],
            [],
            $this->canDeleteContent($message, $user, $message->conversation)
        );
    }

    /**
     * Datos de una encuesta: opciones, votos, porcentajes, votantes y si el
     * usuario puede votar todavía.
     */
    private function pollData(ChatPoll $poll, ?ChatMessage $message, User $user, bool $isParticipant): array
    {
        $poll->loadMissing(['options', 'votes.user']);

        $closed = $poll->isClosed();
        $votes = $poll->votes;
        $byOption = $votes->groupBy('option_id');
        $totalVoters = $votes->pluck('user_id')->unique()->count();
        $myVotes = $votes->where('user_id', $user->id)->pluck('option_id')->map(fn ($id) => (int) $id)->values();

        $conversation = $message?->conversation;
        $readOnly = (bool) $conversation?->is_read_only;
        $deleted = (bool) $message?->isDeleted();

        $options = $poll->options->map(function (ChatPollOption $option) use ($byOption, $totalVoters, $poll, $user) {
            $optionVotes = $byOption->get($option->id, collect());
            $count = $optionVotes->count();

            return [
                'id' => (int) $option->id,
                'label' => $option->label,
                'votes' => $count,
                'percent' => $totalVoters > 0 ? (int) round($count * 100 / $totalVoters) : 0,
                'mine' => $optionVotes->contains(fn ($vote) => (int) $vote->user_id === (int) $user->id),
                'voters' => $poll->hide_voters
                    ? []
                    : $optionVotes
                        ->map(fn ($vote) => $vote->user?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
            ];
        })->values();

        return [
            'id' => (int) $poll->id,
            'message_id' => (int) $poll->message_id,
            'question' => $poll->question,
            'multiple' => (bool) $poll->multiple,
            'hide_voters' => (bool) $poll->hide_voters,
            'closed' => $closed,
            'ends_at' => $poll->ends_at?->toIso8601String(),
            'ends_at_label' => $poll->ends_at?->format('d/m/Y H:i'),
            'total_votes' => $totalVoters,
            'options' => $options,
            'my_votes' => $myVotes,
            'can_vote' => $isParticipant && ! $closed && ! $deleted && ! $readOnly,
        ];
    }

    /**
     * Resumen de un evento para la burbuja y la ficha del chat.
     */
    private function eventData(Event $event): array
    {
        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'year' => (int) $event->year,
            // Etapa real del evento (Borrador, Inscripción, Sorteado...), no la
            // etiqueta genérica de las tarjetas del módulo.
            'status' => $event->isSuspended()
                ? 'Suspendido'
                : ($event->isPostponed() ? 'Pospuesto' : $event->status->label()),
            'status_class' => $event->isSuspended()
                ? 'blocked'
                : ($event->status->isFinished() ? 'inactive' : 'active'),
            'exchange_at' => $event->exchange_at?->format('d/m/Y'),
            'exchange_at_iso' => $event->exchange_at?->toIso8601String(),
            'registration_opens_at' => $event->registration_opens_at?->format('d/m/Y'),
            'registration_closes_at' => $event->registration_closes_at?->format('d/m/Y'),
            'dynamic_starts_at' => $event->dynamic_starts_at?->format('d/m/Y'),
            'participants' => (int) ($event->participants_count ?? $event->participants()->count()),
            'suspended' => $event->isSuspended(),
        ];
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
                'label' => $item->isClosed() ? 'Sala' : ($item->name ?: 'Grupo'),
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
    private function forwardMessage(Conversation $target, ChatMessage $source, User $sender, bool $broadcast = false): ChatMessage
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
            'forwarded_from_id' => $broadcast ? null : $source->id,
            'body' => $source->body,
            'is_moderation' => false,
            'is_broadcast' => $broadcast,
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
        // quedar como residuo: enlaces, ubicación, referencia al adjunto y el
        // evento adjunto. El evento en sí no se borra: solo se quita la ficha
        // de la burbuja, porque los eventos no se eliminan con un mensaje.
        $message->update([
            'deleted_at' => now(),
            'deleted_by' => $user->id,
            'deleted_by_admin' => $byAdmin,
            'pinned_at' => null,
            'pinned_by' => null,
            'link_url' => null,
            'link_title' => null,
            'link_description' => null,
            'link_image' => null,
            'latitude' => null,
            'longitude' => null,
            'event_id' => null,
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

        // La encuesta también vive dentro del mensaje: si el mensaje se elimina,
        // la encuesta y sus votos desaparecen con él.
        $this->purgeMessagePoll($message);

        // Si estaba fijado, el cintillo tampoco debe seguir apuntando a él: se
        // pasa al siguiente fijado visible, si queda alguno.
        if ((int) $message->conversation?->pinned_message_id === (int) $message->id) {
            $latestPinnedId = $message->conversation->messages()
                ->whereNotNull('pinned_at')
                ->whereNull('deleted_at')
                ->orderByDesc('pinned_at')
                ->orderByDesc('id')
                ->value('id');

            $message->conversation->forceFill(['pinned_message_id' => $latestPinnedId])->save();
        }

        return $byAdmin ? 'Eliminado por un administrador' : 'Eliminado por el autor';
    }

    /**
     * Borra la encuesta de un mensaje eliminado, junto con sus opciones y sus
     * votos. La encuesta no existe fuera del mensaje al que está adjunta.
     */
    private function purgeMessagePoll(ChatMessage $message): void
    {
        $poll = $message->poll()->first();

        if ($poll === null) {
            return;
        }

        $poll->votes()->delete();
        $poll->options()->delete();
        $poll->delete();
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

    /**
     * Marca o quita un mensaje de los favoritos del usuario actual. Es una
     * marca personal: no cambia nada para los demás participantes.
     */
    public function favoritoMensaje(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if ($message->conversation === null || ! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        if ($message->isDeleted()) {
            return api_error('No puedes marcar como favorito un mensaje eliminado.', 422);
        }

        $requested = $request->input('favorite');
        $exists = ChatMessageFavorite::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->exists();

        $favorite = $requested !== null ? filter_var($requested, FILTER_VALIDATE_BOOLEAN) : ! $exists;

        if ($favorite) {
            ChatMessageFavorite::query()->firstOrCreate([
                'message_id' => $message->id,
                'user_id' => $user->id,
            ]);
        } else {
            ChatMessageFavorite::query()
                ->where('message_id', $message->id)
                ->where('user_id', $user->id)
                ->delete();
        }

        return api_success(
            $favorite ? 'Mensaje añadido a favoritos.' : 'Mensaje quitado de favoritos.',
            ['favorite' => $favorite]
        );
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

        $emoji = $validator->validated()['emoji'];

        // Cada usuario puede dejar varias reacciones distintas. Si vuelve a
        // pulsar la misma, se quita (alterna).
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
            $removed = true;
        } else {
            $message->reactions()->create([
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);

            $removed = false;
        }

        // Se devuelve el estado actualizado de las reacciones del mensaje para
        // que la burbuja se pinte al instante, sin esperar al sondeo.
        $summary = $this->reactionSummary(collect([$message]), $user);

        return api_success($removed ? 'Reacción quitada.' : 'Reacción guardada.', [
            'removed' => $removed,
            'reactions' => $summary[(int) $message->id] ?? [],
        ]);
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
            'reason' => ['required', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['nullable', 'string', 'max:2000', 'url'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:51200'],
        ], [
            'reason.required' => 'Escribe el motivo del reporte.',
            'reason.max' => 'El motivo no puede pasar de 2000 caracteres.',
            'links.*.url' => 'Cada enlace debe ser una dirección válida.',
            'files.*.max' => 'Cada archivo puede pesar hasta 50 MB.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $reason = $validator->validated()['reason'];

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_MODERATION,
            'message_id' => $message->id,
            'reporter_id' => $user->id,
            'reason' => $reason,
        ]);

        $this->reporteAdjuntos->guardarArchivos($report, $request->file('files', []));
        $this->reporteAdjuntos->guardarEnlaces($report, $request->input('links', []));
        $this->reporteAdjuntos->generarEvidencia($report);

        $this->firestore->recordReport($report);

        // El caso abre su chat (en solo lectura y solo para el beneficiario) y
        // se le avisa con correo y push. La administración recibe el push.
        $conversation = $this->reportes->abrirTicket($report);

        if ($conversation !== null) {
            $this->avisosReporte->abierto($report, $conversation);
        }

        $sender = $message->user?->name ?? 'Sistema';

        // Aviso inmediato a la administración; el correo del caso sale cuando
        // un administrador lo tome y lo gestione.
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
            $isRoom = $conversation->isClosed();
            $groupLabel = $isRoom ? 'la sala' : ($conversation->name ?: 'el grupo');
            $isSecret = $conversation->type === 'secret';

            $title = $message->is_moderation
                ? 'Mensaje de moderación'
                : ($isSecret
                    ? ($isReply
                        ? 'Tienes una respuesta en tu chat de amigo secreto'
                        : 'Nuevo mensaje en tu chat de amigo secreto')
                    : ($isReply
                        ? ($isGroup
                            ? $sender->name.' respondió en '.$groupLabel
                            : $sender->name.' te respondió')
                        : ($isGroup
                            ? 'Nuevo mensaje en '.$groupLabel
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
            'poll' => 'Creó una encuesta: '.($message->poll?->question ?? ''),
            'event' => 'Adjuntó el evento '.($message->event?->name ?? ''),
            default => 'Envió un archivo.',
        };
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

        if ($conversation->isBroadcast()) {
            return api_error('La difusión es privada de su dueño.', 403);
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

        if ($conversation->isReportChat()) {
            $report = $conversation->report;

            return api_error(
                $report !== null && $report->isClosed()
                    ? 'El caso está cerrado: el chat quedó en solo lectura.'
                    : 'El caso está por atender: el chat se habilita cuando la administración lo tome.',
                403
            );
        }

        return api_error('Este chat es de solo lectura: aquí solo se publican los avisos del evento.', 403);
    }

    private function canAccess(Conversation $conversation, User $user): bool
    {
        // La sala de difusión es del dueño: los destinatarios no entran a ella.
        if ($conversation->isBroadcast()) {
            return (int) $conversation->created_by === (int) $user->id;
        }

        $conversation->loadMissing('users');

        if (! $conversation->users->contains('id', $user->id)) {
            // El chat de un caso es privado: solo el beneficiario y el
            // administrador que lo tomó. Ningún otro administrador entra.
            if ($conversation->isReportChat()) {
                return false;
            }

            if ($user->role !== UserRole::Admin) {
                return false;
            }

            // Lo archivado queda fuera del alcance de la moderación.
            return ! $conversation->isArchivedForAnyUser();
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

        // Un solo mensaje fijado por chat: al fijar uno se suelta el anterior.
        $conversation->messages()
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by' => null]);

        $message->forceFill([
            'pinned_at' => now(),
            'pinned_by' => $request->user()->id,
        ])->save();

        $conversation->forceFill(['pinned_message_id' => $message->id])->save();
        $conversation->unsetRelation('pinnedMessage')->load('pinnedMessage.user');

        return api_success('Mensaje fijado.', [
            'pinned_message' => $this->pinnedMessageData($conversation, $request->user()),
        ]);
    }

    /**
     * Quitar el mensaje fijado (desde el cintillo del chat).
     */
    public function desfijarMensaje(Request $request, Conversation $conversation, ?ChatMessage $message = null): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        if ($error = $this->ensureMember($conversation, $request->user())) {
            return $error;
        }

        $target = $message;

        if ($target === null && $conversation->pinned_message_id !== null) {
            $target = $conversation->messages()->whereKey($conversation->pinned_message_id)->first();
        }

        if ($target !== null) {
            if ((int) $target->conversation_id !== (int) $conversation->id) {
                return api_error('Ese mensaje no pertenece a la conversación.', 422);
            }

            $target->forceFill(['pinned_at' => null, 'pinned_by' => null])->save();
        }

        // Suele quedar ninguno; se recalcula por si el id recibido no era el
        // fijado actual.
        $pinnedId = $conversation->messages()
            ->whereNotNull('pinned_at')
            ->whereNull('deleted_at')
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->value('id');

        $conversation->forceFill(['pinned_message_id' => $pinnedId])->save();
        $conversation->unsetRelation('pinnedMessage')->load('pinnedMessage.user');

        return api_success('Mensaje desfijado.', [
            'pinned_message' => $this->pinnedMessageData($conversation, $request->user()),
        ]);
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

    /**
     * Un bloqueo entre el dueño y un miembro saca del grupo o de la sala al
     * miembro. En una difusión saca al destinatario de la lista.
     */
    private function applyBlockToSharedChats(User $blocker, User $blocked): void
    {
        $pairIds = [(int) $blocker->id, (int) $blocked->id];

        $groups = Conversation::query()
            ->where('type', 'group')
            ->whereIn('created_by', $pairIds)
            ->whereHas('users', fn ($query) => $query->whereKey($blocker->id))
            ->whereHas('users', fn ($query) => $query->whereKey($blocked->id))
            ->with('users')
            ->get();

        foreach ($groups as $group) {
            $ownerId = (int) $group->created_by;

            if ($ownerId !== (int) $blocker->id && $ownerId !== (int) $blocked->id) {
                continue;
            }

            $memberId = $ownerId === (int) $blocker->id ? (int) $blocked->id : (int) $blocker->id;

            if (! $group->users->contains('id', $memberId)) {
                continue;
            }

            GroupBan::query()->updateOrCreate(
                ['conversation_id' => $group->id, 'user_id' => $memberId],
                [
                    'banned_by' => $blocker->id,
                    'reason' => 'Bloqueo entre miembros',
                    'revoked_at' => null,
                ]
            );

            $group->users()->detach($memberId);
            $group->touch();

            ChatGroupAudit::log(
                $group,
                $blocker,
                'baneo',
                User::query()->find($memberId),
                'vetado',
                'Miembro expulsado por un bloqueo entre el dueño y un integrante.'
            );
        }

        $broadcasts = Conversation::query()
            ->where('type', 'broadcast')
            ->whereIn('created_by', $pairIds)
            ->get();

        foreach ($broadcasts as $broadcast) {
            $ownerId = (int) $broadcast->created_by;
            $recipientId = $ownerId === (int) $blocker->id ? (int) $blocked->id : (int) $blocker->id;

            $broadcast->broadcastRecipients()->detach($recipientId);
        }
    }

    private function revokeBansFromBlock(User $first, User $second): void
    {
        GroupBan::query()
            ->whereIn('user_id', [(int) $first->id, (int) $second->id])
            ->where('reason', 'Bloqueo entre miembros')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
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

        $this->applyBlockToSharedChats($current, $user);

        return api_success('Usuario bloqueado.', ['blocked' => true]);
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        ChatBlock::query()
            ->where('blocker_id', $request->user()->id)
            ->where('blocked_id', $user->id)
            ->delete();

        $this->revokeBansFromBlock($request->user(), $user);

        return api_success('Usuario desbloqueado.', ['blocked' => false]);
    }

    public function salir(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $user = $request->user();
        $isRoom = $conversation->is_closed;

        if ($conversation->type !== 'group') {
            return api_error('Solo puedes salir de un grupo o una sala.', 422);
        }

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No perteneces a esta conversación.', 403);
        }

        if ($conversation->created_by === $user->id) {
            return api_error(
                $isRoom
                    ? 'El dueño de la sala no puede salir: puede eliminarla o quitar miembros.'
                    : 'El dueño del grupo no puede salir: puede eliminar el grupo o expulsar miembros.',
                422
            );
        }

        $conversation->users()->detach($user->id);
        $conversation->touch();

        ChatGroupAudit::log(
            $conversation,
            $user,
            'salida',
            $user,
            'salida',
            $isRoom ? 'El miembro salió de la sala.' : 'El miembro salió del grupo.'
        );

        return api_success($isRoom ? 'Saliste de la sala.' : 'Saliste del grupo.');
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
        $conversationName = $conversation->isClosed()
            ? 'una sala'
            : ($conversation->name ?: ($conversation->isGroup() ? 'un grupo' : 'una conversación'));
        $defaultReason = 'Reporte de la conversación: '.$conversationName;

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['nullable', 'string', 'max:2000', 'url'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:51200'],
        ], [
            'links.*.url' => 'Cada enlace debe ser una dirección válida.',
            'files.*.max' => 'Cada archivo puede pesar hasta 50 MB.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $reason = trim((string) ($validator->validated()['reason'] ?? ''));

        if ($reason === '') {
            $reason = $defaultReason;
        }

        // También se registra cuando la conversación aún no tiene mensajes: el
        // ticket debe existir para que después se le abra su chat.
        $report = ChatReport::query()->firstOrCreate(
            ['message_id' => $lastMessageId, 'reporter_id' => $user->id],
            [
                'type' => ChatReport::TYPE_MODERATION,
                'reason' => $reason,
            ]
        );

        if (! $report->wasRecentlyCreated) {
            return api_success('Este reporte ya estaba registrado.');
        }

        $this->reporteAdjuntos->guardarArchivos($report, $request->file('files', []));
        $this->reporteAdjuntos->guardarEnlaces($report, $request->input('links', []));
        $this->reporteAdjuntos->generarEvidencia($report);

        $this->firestore->recordReport($report);

        // El caso abre su chat (solo lectura, solo el beneficiario) y se le
        // avisa por correo y push.
        $ticketConversation = $this->reportes->abrirTicket($report);

        if ($ticketConversation !== null) {
            $this->avisosReporte->abierto($report, $ticketConversation);
        }

        $this->push->sendToAdmins(
            'chat.report',
            'Conversación reportada',
            $user->name.' reportó '.$conversationName.'.',
            [
                'report_id' => $report->id,
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

    /**
     * Agrega una persona a una sala cerrada. Solo el dueño o la administración.
     */
    public function agregarMiembroSala(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $current = $request->user();

        if (! $conversation->isClosed()) {
            return api_error('Esta conversación no es una sala cerrada.', 422);
        }

        $isOwner = (int) $conversation->created_by === (int) $current->id;

        if ($current->role !== UserRole::Admin && ! $isOwner) {
            return api_error('Solo el dueño de la sala puede agregar miembros.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Selecciona una persona.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $target = User::query()
            ->whereKey($request->integer('user_id'))
            ->where('is_active', true)
            ->firstOrFail();

        if ((int) $target->id === (int) $current->id) {
            return api_error('Ya estás en la sala.', 422);
        }

        if (ChatBlock::existsBetween($current->id, $target->id)) {
            return api_error('No puedes agregar a '.$target->name.' a la sala mientras haya un bloqueo.', 422);
        }

        if (GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $target->id)
            ->whereNull('revoked_at')
            ->exists()) {
            return api_error($target->name.' está vetado en esta sala.', 422);
        }

        if ($conversation->users()->whereKey($target->id)->exists()) {
            return api_success('Esa persona ya pertenece a la sala.', ['added' => false]);
        }

        $conversation->users()->attach($target->id, ['role' => 'member']);
        $conversation->touch();
        $conversation->load('users');

        ChatGroupAudit::log(
            $conversation,
            $current,
            'miembro_agregado',
            $target,
            'agregado',
            'Miembro agregado a la sala.'
        );

        $this->firestore->recordConversation($conversation);

        return api_success('Miembro agregado a la sala.', [
            'added' => true,
            'conversation' => $this->conversationData($conversation, $current),
        ]);
    }

    public function expulsar(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureAccess();

        if ($conversation->isBroadcast()) {
            return api_error('La difusión no tiene miembros; solo destinatarios.', 422);
        }

        if ($readOnly = $this->ensureWritable($conversation)) {
            return $readOnly;
        }

        $current = $request->user();

        $isOwner = $conversation->type === 'group' && $conversation->created_by === $current->id;

        if ($current->role !== UserRole::Admin && ! $isOwner) {
            abort(403);
        }

        $conversation->users()->detach($user->id);

        $isRoom = $conversation->is_closed;

        ChatGroupAudit::log(
            $conversation,
            $current,
            'expulsion',
            $user,
            'expulsado',
            $isRoom ? 'Miembro expulsado de la sala.' : 'Miembro expulsado del grupo.'
        );

        return api_success($isRoom ? 'Miembro expulsado de la sala.' : 'Miembro expulsado del grupo.');
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
        $isBroadcast = $conversation->isBroadcast();
        $isAdmin = $user->role === UserRole::Admin;
        $pivot = $this->pivotFor($conversation, $user);
        $isParticipant = $pivot !== null;
        $isOwner = ($isGroup || $isBroadcast) && (int) $conversation->created_by === (int) $user->id;
        $canManage = $isAdmin || $isOwner;
        $isSecret = $conversation->type === 'secret';
        $secretRole = $conversation->secretRoleFor($user);
        $other = ($isGroup || $isBroadcast)
            ? null
            : ($conversation->users->firstWhere('id', '!=', $user->id)
                ?? $conversation->users->first());
        $broadcastRecipients = $isBroadcast
            ? $conversation->broadcastRecipients()->orderBy('name')->get()
            : collect();

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

        $commonGroups = [];
        $myGroups = [];

        if (! $isGroup && ! $isBroadcast) {
            $myGroups = Conversation::query()
                ->where('type', 'group')
                ->where('is_closed', false)
                ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                ->with(['users:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Conversation $group) => [
                    'id' => (int) $group->id,
                    'name' => $group->name ?: 'Grupo',
                    'has_other' => $other !== null && $group->users->contains('id', $other->id),
                ])
                ->values()
                ->all();
        }

        if (! $isGroup && $other !== null && $showOther) {
            $commonGroups = Conversation::query()
                ->where('type', 'group')
                ->where('is_closed', false)
                ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                ->whereHas('users', fn ($query) => $query->whereKey($other->id))
                ->with('users:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Conversation $group) => [
                    'id' => (int) $group->id,
                    'name' => $group->name ?: 'Grupo',
                    'members' => $group->users->pluck('name')->values()->all(),
                ])
                ->values()
                ->all();
        }

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
            'is_closed' => (bool) $conversation->is_closed,
            'title' => $conversation->titleFor($user),
            'name' => $conversation->name,
            'is_broadcast' => $isBroadcast,
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
            'can_delete' => ($isGroup || $isBroadcast) && $canManage,
            'can_block' => ! $isGroup && ! $isBroadcast && $isParticipant && $other !== null
                && $other->role !== UserRole::Admin,
            'can_report' => $isParticipant && ! $isBroadcast,
            'members_count' => $conversation->users->count(),
            'members' => $members,
            'recipients_count' => $broadcastRecipients->count(),
            'recipients' => $broadcastRecipients->map(fn (User $recipient) => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'username' => $recipient->username,
                'online' => $recipient->last_seen_at !== null && $recipient->last_seen_at->gte(now()->subMinutes(5)),
            ])->values(),
            'common_groups' => $commonGroups,
            'my_groups' => $myGroups,
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

    /**
     * Agrega al destinatario de un chat directo a los grupos del remitente.
     */
    public function agregarAGrupos(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($conversation->isGroup()) {
            return api_error('Esta acción solo aplica a chats directos.', 422);
        }

        $other = $conversation->users->firstWhere('id', '!=', $user->id);

        if ($other === null) {
            return api_error('No se encontró al destinatario.', 422);
        }

        if (ChatBlock::existsBetween($user->id, $other->id)) {
            return api_error('No puedes agregar a una persona bloqueada.', 422);
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('groups', []))));

        if ($ids === []) {
            return api_error('Selecciona al menos un grupo.', 422);
        }

        $groups = Conversation::query()
            ->whereIn('id', $ids)
            ->where('type', 'group')
            ->where('is_closed', false)
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->get();

        $added = 0;

        foreach ($groups as $group) {
            if (! $group->users()->whereKey($other->id)->exists()) {
                $group->users()->attach($other->id);
                $added++;
            }
        }

        return api_success(
            $added > 0
                ? 'Se agregó a '.$other->name.' a '.$added.' grupo(s).'
                : 'Ya pertenece a los grupos seleccionados.',
            ['added' => $added]
        );
    }

    public function miembros(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        if ($conversation->isBroadcast()) {
            return api_error('La difusión no tiene miembros; solo destinatarios.', 422);
        }

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

        if ($conversation->isBroadcast() && (int) $conversation->created_by !== (int) $current->id) {
            abort(403);
        }

        $isOwner = in_array($conversation->type, ['group', 'broadcast'], true)
            && $conversation->created_by === $current->id;

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
    /**
     * Abre (o crea) la conversación directa con un usuario desde la bandeja.
     */
    public function abrirConversacion(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        $current = $request->user();

        if ((int) $user->id === (int) $current->id) {
            return api_error('No puedes abrir un chat contigo mismo.', 422);
        }

        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('users', fn ($query) => $query->whereKey($current->id))
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::create([
                'type' => 'direct',
                'created_by' => $current->id,
                'is_moderated' => false,
            ]);

            $conversation->users()->attach([$current->id, $user->id]);
        }

        return api_success('Conversación abierta.', [
            'conversation_id' => (int) $conversation->id,
        ]);
    }

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
            'reason' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['nullable', 'string', 'max:2000', 'url'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:51200'],
        ], [
            'links.*.url' => 'Cada enlace debe ser una dirección válida.',
            'files.*.max' => 'Cada archivo puede pesar hasta 50 MB.',
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
            [
                'type' => ChatReport::TYPE_MODERATION,
                'reason' => $reason,
            ]
        );

        if (! $report->wasRecentlyCreated) {
            return api_success('Este reporte ya estaba registrado.');
        }

        $this->reporteAdjuntos->guardarArchivos($report, $request->file('files', []));
        $this->reporteAdjuntos->guardarEnlaces($report, $request->input('links', []));
        $this->reporteAdjuntos->generarEvidencia($report);

        $this->firestore->recordReport($report);

        // El caso abre su chat (solo lectura, solo el beneficiario) y se le
        // avisa por correo y push.
        $ticketConversation = $this->reportes->abrirTicket($report);

        if ($ticketConversation !== null) {
            $this->avisosReporte->abierto($report, $ticketConversation);
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
        $conversation->loadMissing('users');
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

        $isReport = $conversation->isReportChat();
        $isBroadcast = $conversation->isBroadcast();
        $report = $isReport ? $conversation->report : null;
        $reportStatus = $report?->statusLabel();
        $recipientsCount = $isBroadcast
            ? (int) ($conversation->broadcast_recipients_count ?? $conversation->broadcastRecipients()->count())
            : 0;

        if ($isBroadcast) {
            $subtitle = $recipientsCount === 1
                ? '1 destinatario'
                : $recipientsCount.' destinatarios';
        } elseif ($conversation->isGroup()) {
            $subtitle = $onlineUsers.' en línea';
        } elseif ($isReport) {
            $status = $reportStatus ?? 'Por atender';
            $subtitle = $conversation->is_read_only
                ? $status.' · solo lectura'
                : ($other !== null ? $status.' · '.$other->name : $status);
        } elseif ($conversation->isEventChannel()) {
            $subtitle = 'Avisos del evento · solo lectura';
        } elseif ($conversation->isSystem()) {
            $subtitle = 'Chat del sistema · solo lectura';
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

        // En grupos y salas se avisa si hay un bloqueo entre miembros que no
        // son el dueño: la persona puede quedarse o salir.
        $groupBlocks = [];

        if ($conversation->isGroup() && ! $forModeration) {
            $blockedByMe = $this->blockedIds($user);
            $blockedMe = $this->blockedByIds($user);

            foreach ($conversation->users as $member) {
                if ((int) $member->id === (int) $user->id) {
                    continue;
                }

                $blocked = in_array((int) $member->id, $blockedByMe, true);
                $blockedBy = in_array((int) $member->id, $blockedMe, true);

                if ($blocked || $blockedBy) {
                    $groupBlocks[] = [
                        'id' => (int) $member->id,
                        'name' => $member->name,
                        'blocked' => $blocked,
                        'blocked_by' => $blockedBy,
                    ];
                }
            }
        }

        return [
            'id' => $conversation->id,
            'title' => $forModeration
                ? ($conversation->isBroadcast()
                    ? 'Difusión'
                    : ($conversation->isClosed()
                        ? 'Sala'
                        : ($conversation->isGroup()
                            ? ($conversation->name ?: 'Grupo')
                            : $conversation->users->pluck('name')->implode(' / '))))
                : $conversation->titleFor($user),
            'type' => $conversation->type,
            'is_group' => $conversation->isGroup(),
            'is_closed' => (bool) $conversation->is_closed,
            'title' => $conversation->titleFor($user),
            'name' => $conversation->name,
            'is_broadcast' => $isBroadcast,
            'recipients_count' => $recipientsCount,
            'subtitle' => $subtitle,
            'online_count' => $onlineUsers,
            'last_message' => $lastPreview,
            'last_sender' => $last?->is_moderation ? 'Moderación' : $last?->user?->name,
            'unread' => $unread,
            'muted' => $muted,
            'blocked' => $blocked,
            'blocked_by' => $blockedByThem,
            'group_blocks' => $groupBlocks,
            'is_owner' => $conversation->created_by === $user->id,
            'other_user_id' => ! $forModeration && ! $conversation->isGroup() && ! $isBroadcast ? $other?->id : null,
            // Con quién se habla es administrador del sistema: la barra del
            // chat lo marca junto al nombre. En el amigo secreto se calla para
            // no revelar quién es.
            'other_is_admin' => ! $forModeration
                && $conversation->type === 'direct'
                && $other?->role === UserRole::Admin,
            'updated_at' => $conversation->updated_at?->diffForHumans(),
            'updated_iso' => $conversation->updated_at?->toIso8601String(),
            'favorite' => $pivot?->favorite_at !== null,
            'pinned' => $pivot?->pinned_at !== null,
            'archived' => $pivot?->archived_at !== null,
            'hidden' => $pivot?->hidden_at !== null,
            'is_moderation' => $forModeration,
            'read_only' => $conversation->is_read_only,
            // Texto del aviso cuando el chat no admite escritura.
            'read_only_label' => $this->readOnlyLabel($conversation),
            'is_event_channel' => $conversation->isEventChannel(),
            // Chat del caso (ticket): pestaña Reportes y solo lectura al cerrar.
            'is_report' => $isReport,
            'report_id' => $isReport ? (int) $conversation->report_id : null,
            'report_status' => $isReport ? $report?->status : null,
            'report_status_label' => $isReport ? $reportStatus : null,
            'can_archive' => ! $forModeration && $conversation->type === 'direct',
            'can_unarchive' => ! $forModeration && $conversation->canBeUnarchived(),
            'pinned_message' => $this->pinnedMessageData($conversation, $user),
        ];
    }

    /**
     * Aviso que explica por qué un chat no admite escritura.
     */
    private function readOnlyLabel(Conversation $conversation): ?string
    {
        if (! $conversation->is_read_only) {
            return null;
        }

        if ($conversation->isReportChat()) {
            $report = $conversation->report;

            // El chat del caso está en solo lectura en dos fases: recién
            // abierto (por atender) y después de cerrarlo.
            return $report !== null && $report->isClosed()
                ? 'Caso cerrado: chat de solo lectura.'
                : 'Caso por atender: el chat se habilita cuando la administración lo tome.';
        }

        if ($conversation->isEventChannel()) {
            return 'Chat del evento: solo lectura.';
        }

        if ($conversation->isSystem()) {
            return 'Chat del sistema: solo lectura.';
        }

        return 'Chat de solo lectura.';
    }

    /**
     * Mensaje fijado dentro de la conversación, para el cintillo.
     *
     * @return array{id:int,body:string,sender:string}|null
     */
    private function pinnedMessageData(Conversation $conversation, ?User $user = null): ?array
    {
        $clearedAt = $user !== null ? $this->pivotFor($conversation, $user)?->cleared_at : null;

        $visible = function (?ChatMessage $message) use ($clearedAt) {
            if ($message === null || $message->isDeleted() || $message->pinned_at === null) {
                return false;
            }

            return ! ($clearedAt !== null
                && $message->created_at !== null
                && $message->created_at->lt($clearedAt));
        };

        $message = $conversation->pinnedMessage;

        // Si el fijado quedó eliminado o el usuario lo vació de su lado, el
        // cintillo no muestra nada.
        if (! $visible($message)) {
            $message = $conversation->messages()
                ->with(['user', 'poll', 'event'])
                ->whereNotNull('pinned_at')
                ->whereNull('deleted_at')
                ->when($clearedAt !== null, fn ($query) => $query->where('created_at', '>=', $clearedAt))
                ->orderByDesc('pinned_at')
                ->orderByDesc('id')
                ->first();
        }

        if (! $visible($message)) {
            return null;
        }

        $body = trim(strip_tags((string) $message->body));

        if ($body === '') {
            $body = match ($message->type) {
                'poll' => 'Encuesta: '.($message->poll?->question ?? ''),
                'event' => 'Evento: '.($message->event?->name ?? ''),
                default => $message->attachment_name ?: 'Mensaje adjunto',
            };
        }

        return [
            'id' => (int) $message->id,
            'body' => Str::limit($body, 160),
            'sender' => $message->is_moderation
                ? ($message->user?->name ?? 'Moderación')
                : ($message->user?->name ?? 'Sistema'),
            'sender_id' => $message->user_id,
            'sender_is_system' => $message->user === null && ! $message->is_moderation,
            'conversation_type' => $message->conversation?->type,
        ];
    }
}
