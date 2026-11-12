<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Module;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ChatController extends Controller
{
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

        $messages = $conversation->messages()
            ->with('user')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(function (ChatMessage $message) use ($user) {
                $deleted = $message->isDeleted();

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
                    'type' => $message->type,
                    'attachment_url' => $message->attachment_path
                        ? Storage::disk('public')->url($message->attachment_path)
                        : null,
                    'attachment_name' => $message->attachment_name,
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

        return api_success('Mensajes.', [
            'conversation' => $this->conversationData($conversation->fresh('users'), $user),
            'messages' => $messages,
        ]);
    }

    public function enviar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureAccess();

        $user = $request->user();

        if (! $this->canAccess($conversation, $user)) {
            return api_error('No tienes acceso a esta conversación.', 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:20480'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), ['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $file = $request->file('file');

        if (($data['body'] ?? '') === '' && $file === null) {
            return api_error('Escribe un mensaje o adjunta un archivo.', 422);
        }

        try {
            $isMember = $conversation->users()->whereKey($user->id)->exists();
            $type = 'text';
            $path = $name = $mime = $size = null;

            if ($file !== null) {
                $mime = (string) $file->getMimeType();

                $type = match (true) {
                    str_starts_with($mime, 'image/') => 'image',
                    str_starts_with($mime, 'video/') => 'video',
                    str_starts_with($mime, 'audio/') => 'audio',
                    default => 'file',
                };

                $path = $file->store('chat', 'public');
                $name = $file->getClientOriginalName();
                $size = $file->getSize();
            }

            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $data['body'] ?? '',
                'is_moderation' => ! $isMember && $user->role === UserRole::Admin,
                'type' => $type,
                'attachment_path' => $path,
                'attachment_name' => $name,
                'attachment_mime' => $mime,
                'attachment_size' => $size,
            ]);

            $conversation->touch();

            if ($isMember) {
                $conversation->users()->updateExistingPivot($user->id, ['last_read_at' => now()]);
            }

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
            'body' => $validator->validated()['body'],
            'edited_at' => now(),
        ]);

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

        ChatReport::create([
            'message_id' => $message->id,
            'reporter_id' => $user->id,
            'reason' => $reason,
        ]);

        $reports = $this->reportConversation();
        $sender = $message->user?->name ?? 'Sistema';
        $body = 'Mensaje reportado: «' . $message->body . '» de ' . $sender
            . ', reportado por ' . $user->name
            . ' el ' . now()->format('d/m/Y') . ' a las ' . now()->format('H:i')
            . '. Motivo: ' . $reason;

        $reports->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
            'is_moderation' => true,
        ]);

        $admins = User::query()->where('role', UserRole::Admin)->where('is_active', true)->get();

        foreach ($admins as $admin) {
            send_mail(
                $admin->email,
                'Mensaje reportado en el chat',
                'Nuevo reporte en el chat',
                [
                    'Se reportó un mensaje de ' . $sender . '.',
                    'Reportado por: ' . $user->name . '.',
                    'Motivo: ' . $reason,
                    'Fecha: ' . now()->format('d/m/Y H:i'),
                ],
                null,
                ['recipient_name' => $admin->name, 'eyebrow' => 'Reporte', 'preheader' => 'Reporte de mensaje']
            );
        }

        return api_success('Reporte enviado a la administración.');
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

    private function canAccess(Conversation $conversation, User $user): bool
    {
        return $user->role === UserRole::Admin || $conversation->users()->whereKey($user->id)->exists();
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
        $unread = $forModeration ? 0 : $this->unreadCount($conversation, $user);

        return [
            'id' => $conversation->id,
            'title' => $forModeration
                ? ($conversation->isGroup() ? ($conversation->name ?: 'Grupo') : $conversation->users->pluck('name')->implode(' / '))
                : $conversation->titleFor($user),
            'type' => $conversation->type,
            'is_group' => $conversation->isGroup(),
            'last_message' => $last?->body,
            'last_sender' => $last?->is_moderation ? 'Moderación' : $last?->user?->name,
            'unread' => $unread,
            'updated_at' => $conversation->updated_at?->diffForHumans(),
        ];
    }
}
