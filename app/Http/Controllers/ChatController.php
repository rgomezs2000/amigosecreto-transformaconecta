<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Module;
use App\Models\User;
use App\Services\Firestore\FirestoreService;
use App\Services\PushNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly FirestoreService $firestore,
        private readonly PushNotifier $push,
    ) {}

    private function ensureAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'chat')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
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
            ->with('users')
            ->orderByDesc('updated_at')
            ->get();

        $items = $conversations->map(fn (Conversation $conversation) => $this->conversationData($conversation, $user));

        $moderation = [];

        if ($user->role === UserRole::Admin) {
            $moderation = Conversation::query()
                ->with('users')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Conversation $conversation) => $this->conversationData($conversation, $user, true));
        }

        $users = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'role' => $item->role?->label() ?? 'Usuario',
                'online' => $item->last_seen_at !== null && $item->last_seen_at->gte(now()->subMinutes(5)),
            ]);

        return api_success('Resumen del chat.', [
            'conversations' => $items,
            'moderation' => $moderation,
            'users' => $users,
            'unread' => $conversations->sum(fn (Conversation $conversation) => $this->unreadCount($conversation, $user)),
            'is_admin' => $user->role === UserRole::Admin,
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
            $conversation = Conversation::query()
                ->where('type', 'direct')
                ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                ->whereHas('users', fn ($query) => $query->whereKey($target->id))
                ->withCount('users')
                ->get()
                ->first(fn (Conversation $item) => $item->users_count === 2);

            if ($conversation === null) {
                $conversation = Conversation::create([
                    'type' => 'direct',
                    'created_by' => $user->id,
                ]);

                $conversation->users()->attach([$user->id, $target->id]);
            }

            $conversation->load('users');

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

        if ($request->user()->role !== UserRole::Admin) {
            return api_error('Solo un administrador puede crear grupos.', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ], [
            'name.required' => 'Escribe el nombre del grupo.',
            'user_ids.required' => 'Selecciona al menos un usuario.',
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

            $ids = collect($validator->validated()['user_ids'])->map(fn ($id) => (int) $id)->unique()->values();

            $conversation->users()->attach($user->id, ['role' => 'moderator']);

            foreach ($ids->where('!=', $user->id) as $id) {
                $conversation->users()->attach($id, ['role' => 'member']);
            }

            $conversation->load('users');

            $this->firestore->recordConversation($conversation);

            return api_success('Grupo creado.', [
                'conversation' => $this->conversationData($conversation, $user, true),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el grupo', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el grupo. Intenta de nuevo.', 500);
        }
    }

    public function mensajes(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $conversation->messages()
            ->where('user_id', '!=', $user->id)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        $clearedAt = $conversation->users->firstWhere('id', $user->id)?->pivot->cleared_at;

        $messagesQuery = $conversation->messages()
            ->with(['user', 'attachments'])
            ->latest('id')
            ->limit(100);

        if ($clearedAt !== null) {
            $messagesQuery->where('created_at', '>', $clearedAt);
        }

        $messages = $messagesQuery
            ->get()
            ->reverse()
            ->values();

        $others = $conversation->users->where('id', '!=', $user->id);

        $messages = $messages->map(function (ChatMessage $message) use ($user, $others) {
            $deleted = $message->isDeleted();
            $read = $others->isNotEmpty() && $others->every(function ($other) use ($message) {
                $lastRead = $other->pivot->last_read_at;

                return $lastRead !== null
                    && Carbon::parse($lastRead)->gte($message->created_at);
            });

            $reactions = $message->reactions()
                ->selectRaw('emoji, count(*) as total')
                ->groupBy('emoji')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($reaction) => [
                    'emoji' => $reaction->emoji,
                    'count' => (int) $reaction->total,
                    'mine' => $message->reactions()
                        ->where('user_id', $user->id)
                        ->where('emoji', $reaction->emoji)
                        ->exists(),
                ]);

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
                'latitude' => $message->latitude,
                'longitude' => $message->longitude,
                'type' => $message->type,
                'attachment_url' => $message->attachment_path
                    ? asset('storage/'.$message->attachment_path)
                    : null,
                'attachment_name' => $message->attachment_name,
                'attachments' => $message->attachments->map(fn ($item) => [
                    'url' => asset('storage/'.$item->path),
                    'type' => $item->type,
                    'name' => $item->name,
                ])->values(),
                'link_url' => $message->link_url,
                'link_title' => $message->link_title,
                'link_description' => $message->link_description,
                'link_image' => $message->link_image,
                'edited' => $message->edited_at !== null,
                'deleted' => $deleted,
                'deleted_label' => $deleted
                    ? ($message->deleted_by_admin ? 'Eliminado por un administrador' : 'Eliminado por el autor')
                    : null,
                'reactions' => $reactions,
                'can_edit' => $message->user_id === $user->id && ! $deleted,
                'can_delete' => ! $deleted
                    && ($message->user_id === $user->id || $user->role === UserRole::Admin),
                'reported' => $message->reports()->where('reporter_id', $user->id)->exists(),
            ];
        });

        $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);

        $pivot = $conversation->users->firstWhere('id', $user->id)?->pivot;
        $lastRead = $pivot?->last_read_at;
        $newMessages = $lastRead === null
            ? collect()
            : $conversation->messages()->where('created_at', '>', $lastRead)->count();

        return api_success('Mensajes.', [
            'conversation' => $this->conversationData($conversation->fresh('users'), $user),
            'messages' => $messages,
            'new_count' => $newMessages,
        ]);
    }

    public function enviar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        if ($conversation->type === 'direct') {
            $other = $conversation->users->firstWhere('id', '!=', $user->id);

            if ($other !== null && \App\Models\ChatBlock::existsBetween($user->id, $other->id)) {
                return api_error('No puedes enviar mensajes a este usuario.', 403);
            }
        }

        $validator = Validator::make($request->all(), [
            'body' => ['nullable', 'string', 'max:2000'],
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

    public function editar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
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

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
        }

        if ($message->user_id !== $user->id && $user->role !== UserRole::Admin) {
            return api_error('Solo el autor o un administrador puede eliminar el mensaje.', 403);
        }

        if ($message->isDeleted()) {
            return api_success('El mensaje ya estaba eliminado.');
        }

        $byAdmin = $user->role === UserRole::Admin && $message->user_id !== $user->id;

        $message->update([
            'deleted_at' => now(),
            'deleted_by' => $user->id,
            'deleted_by_admin' => $byAdmin,
        ]);

        $this->firestore->recordMessage($message->load('user', 'attachments', 'conversation'));

        return api_success('Mensaje eliminado.', [
            'deleted_label' => $byAdmin ? 'Eliminado por un administrador' : 'Eliminado por el autor',
        ]);
    }

    public function reaccionar(Request $request, ChatMessage $message): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($message->conversation, $user)) {
            return api_error('No tienes acceso a este mensaje.', 403);
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
            route('moderacion.index'),
            'reporte',
            $user
        );

        return api_success('Reporte enviado a la administración.');
    }

    /**
     * Avisa a los demás participantes del mensaje nuevo (push + Firestore).
     */
    private function notifyConversation(Conversation $conversation, ChatMessage $message, User $sender): void
    {
        if ($conversation->type === 'reports') {
            return;
        }

        try {
            $recipients = $conversation->users()
                ->whereKeyNot($sender->id)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $isGroup = $conversation->isGroup();

            $title = $message->is_moderation
                ? 'Mensaje de moderación'
                : ($isGroup
                    ? 'Nuevo mensaje en '.($conversation->name ?: 'el grupo')
                    : 'Nuevo mensaje de '.$sender->name);

            $this->push->sendMany(
                $recipients,
                'chat.mensaje',
                $title,
                $this->messagePreview($message),
                [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    'sender' => $sender->name,
                    'is_group' => $isGroup,
                ],
                route('chat.index').'?conversacion='.$conversation->id,
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

        $this->firestore->recordConversation($conversation->fresh('users'));

        return api_success('Conversación marcada como leída.');
    }

    public function moderar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

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

        if (! $this->canAccess($conversation, $request->user())) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $items = $conversation->messages()
            ->with('attachments')
            ->latest('id')
            ->limit(300)
            ->get()
            ->flatMap(function (ChatMessage $message) {
                $base = ['created_at' => $message->created_at?->format('d/m/Y H:i')];

                if ($message->link_url) {
                    return [array_merge($base, [
                        'kind' => 'link',
                        'url' => $message->link_url,
                        'name' => $message->link_title ?: $message->link_url,
                        'extension' => 'enlace',
                        'size' => null,
                    ])];
                }

                $attachments = $message->attachments;

                if ($attachments->isEmpty() && $message->attachment_path) {
                    $attachments = collect([(object) [
                        'type' => $message->type,
                        'path' => $message->attachment_path,
                        'name' => $message->attachment_name,
                        'mime' => $message->attachment_mime,
                        'size' => $message->attachment_size,
                    ]]);
                }

                return $attachments->map(function ($item) use ($base) {
                    $extension = strtolower(pathinfo((string) $item->name, PATHINFO_EXTENSION));
                    $kind = $item->type;

                    if ($extension === 'pdf') {
                        $kind = 'pdf';
                    }

                    return array_merge($base, [
                        'kind' => $kind,
                        'url' => asset('storage/'.$item->path),
                        'name' => $item->name,
                        'extension' => $extension,
                        'size' => $item->size,
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

    private function canAccess(Conversation $conversation, User $user): bool
    {
        return $user->role === UserRole::Admin || $conversation->users()->whereKey($user->id)->exists();
    }

    public function silenciar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $pivot = $conversation->users->firstWhere('id', $user->id)?->pivot;
        $muted = $pivot === null || $pivot->muted_at === null;

        $conversation->users()->updateExistingPivot($user->id, [
            'muted_at' => $muted ? now() : null,
        ]);

        return api_success($muted ? 'Conversación silenciada.' : 'Silencio quitado.', ['muted' => $muted]);
    }

    public function limpiar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $conversation->users()->updateExistingPivot($user->id, ['cleared_at' => now()]);

        return api_success('Conversación borrada de tu lado.');
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

        \App\Models\ChatBlock::query()->firstOrCreate([
            'blocker_id' => $current->id,
            'blocked_id' => $user->id,
        ]);

        return api_success('Usuario bloqueado.', ['blocked' => true]);
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        \App\Models\ChatBlock::query()
            ->where('blocker_id', $request->user()->id)
            ->where('blocked_id', $user->id)
            ->delete();

        return api_success('Usuario desbloqueado.', ['blocked' => false]);
    }

    private function unreadCount(Conversation $conversation, User $user): int
    {
        $pivot = $conversation->users->firstWhere('id', $user->id)?->pivot;
        $lastRead = $pivot?->last_read_at;

        return $conversation->messages()
            ->where('user_id', '!=', $user->id)
            ->when($lastRead !== null, fn ($query) => $query->where('created_at', '>', $lastRead))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(Conversation $conversation, User $user, bool $forModeration = false): array
    {
        $last = $conversation->messages()->latest('id')->first();
        $lastPreview = null;

        if ($last !== null) {
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

        $unread = $forModeration ? 0 : $this->unreadCount($conversation, $user);
        $others = $conversation->users->where('id', '!=', $user->id);
        $other = $others->first();
        $onlineUsers = $conversation->users->filter(fn (User $item) => $item->last_seen_at !== null && $item->last_seen_at->gte(now()->subMinutes(5)))->count();

        if ($conversation->isGroup()) {
            $subtitle = $onlineUsers.' en línea';
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
            'updated_at' => $conversation->updated_at?->diffForHumans(),
        ];
    }
}
