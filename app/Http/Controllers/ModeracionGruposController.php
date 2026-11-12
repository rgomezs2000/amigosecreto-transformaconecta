<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesModeracion;
use App\Models\Conversation;
use App\Models\GroupBan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vetados de grupo vistos desde Moderación.
 *
 * El listado muestra todos los grupos del sistema con su cantidad de
 * miembros y de vetados. El modal de «Ver baneados» trabaja con dos listas
 * (usuarios disponibles y vetados) y guarda el estado completo de cada lado
 * en cada movimiento. Vetar a alguien que está dentro del grupo lo saca.
 */
class ModeracionGruposController extends Controller
{
    use AuthorizesModeracion;

    /**
     * Listado paginado de grupos para la tabla del módulo.
     */
    public function grupos(Request $request): JsonResponse
    {
        $this->ensureModeracionAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $search = trim((string) $request->input('search.value', ''));

        $recordsTotal = Conversation::query()->where('type', 'group')->count();

        $query = Conversation::query()->where('type', 'group');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $recordsFiltered = (clone $query)->count();

        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $column = (int) $request->input('order.0.column', 0);

        $groups = $query
            ->with('creator:id,name')
            ->withCount('users')
            ->withCount(['bans as banned_count' => fn ($bans) => $bans->whereNull('revoked_at')])
            ->when($column === 0, fn ($groups) => $groups->orderByRaw("LOWER(COALESCE(name, '')) ".$direction))
            ->when($column !== 0, fn ($groups) => $groups->orderBy('id', $direction))
            ->orderBy('id')
            ->skip($start)
            ->take($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $groups->map(fn (Conversation $group) => [
                'id' => $group->id,
                'name' => e($group->name ?: 'Grupo sin nombre'),
                'name_raw' => $group->name ?: 'Grupo sin nombre',
                'owner' => e($group->creator?->name ?? '—'),
                'owner_raw' => $group->creator?->name ?? '—',
                'members' => (int) $group->users_count,
                'banned' => (int) $group->banned_count,
                'actions' => view('moderacion.partials.ban-actions', ['group' => $group])->render(),
            ])->all(),
        ]);
    }

    /**
     * Estado actual de los vetados de un grupo, en dos listas.
     */
    public function detalle(Conversation $conversation): JsonResponse
    {
        $this->ensureModeracionAccess();
        $this->ensureGroup($conversation);

        $current = (int) auth()->id();

        $banned = GroupBan::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->with('user')
            ->get()
            ->filter(fn (GroupBan $ban) => $ban->user !== null)
            ->sortBy(fn (GroupBan $ban) => mb_strtolower((string) $ban->user?->name))
            ->values();

        $bannedIds = $banned->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $memberIds = $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $available = User::query()
            ->whereKeyNot($current)
            ->whereNotIn('id', $bannedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);

        return api_success('Vetados del grupo.', [
            'subject' => [
                'id' => $conversation->id,
                'name' => $conversation->name ?: 'Grupo sin nombre',
            ],
            'banned' => $banned->map(fn (GroupBan $ban) => $this->personData($ban->user, $memberIds))->values()->all(),
            'available' => $available->map(fn (User $user) => $this->personData($user, $memberIds))->values()->all(),
        ]);
    }

    /**
     * Sincroniza los vetados del grupo con las listas recibidas.
     *
     * Las dos listas son completas: lo que está en «banned» queda vetado y
     * lo que está en «available» deja de estarlo.
     */
    public function sincronizar(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureModeracionAccess();
        $this->ensureGroup($conversation);

        $validator = validator($request->all(), [
            'available' => ['present', 'array'],
            'available.*' => ['integer', 'distinct', 'exists:users,id'],
            'banned' => ['present', 'array'],
            'banned.*' => ['integer', 'distinct', 'exists:users,id'],
        ], [
            'available.present' => 'Falta la lista de usuarios disponibles.',
            'available.*.integer' => 'La lista de disponibles trae un usuario inválido.',
            'available.*.exists' => 'Uno de los usuarios disponibles ya no existe.',
            'banned.present' => 'Falta la lista de vetados.',
            'banned.*.integer' => 'La lista de vetados trae un usuario inválido.',
            'banned.*.exists' => 'Uno de los usuarios vetados ya no existe.',
        ]);

        if ($validator->fails()) {
            return api_error($validator->errors()->first(), 422, ['errors' => $validator->errors()]);
        }

        /** @var array<int, int> $available */
        $available = array_map('intval', $validator->validated()['available']);
        /** @var array<int, int> $banned */
        $banned = array_map('intval', $validator->validated()['banned']);

        if (array_intersect($available, $banned) !== []) {
            return api_error('Un usuario no puede estar en las dos listas a la vez.', 422);
        }

        if (in_array((int) auth()->id(), $banned, true)) {
            return api_error('No puedes vetarte a ti mismo.', 422);
        }

        $created = 0;
        $removed = 0;
        $kicked = 0;

        DB::transaction(function () use ($conversation, $available, $banned, &$created, &$removed, &$kicked) {
            $current = GroupBan::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('revoked_at')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toBan = array_values(array_diff($banned, $current));
            $toRevoke = array_values(array_intersect($available, $current));

            foreach ($toBan as $userId) {
                GroupBan::query()->updateOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $userId],
                    ['banned_by' => auth()->id(), 'revoked_at' => null]
                );

                if ($conversation->users()->whereKey($userId)->exists()) {
                    $conversation->users()->detach($userId);
                    $kicked++;
                }

                $created++;
            }

            foreach ($toRevoke as $userId) {
                $removed += GroupBan::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $userId)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
        });

        return api_success($this->messageFor($created, $removed, $kicked), [
            'created' => $created,
            'removed' => $removed,
            'kicked' => $kicked,
        ]);
    }

    private function messageFor(int $created, int $removed, int $kicked): string
    {
        return match (true) {
            $created > 0 && $removed === 0 && $kicked > 0 => 'Veto aplicado y usuario sacado del grupo.',
            $created > 0 && $removed === 0 => 'Veto aplicado.',
            $removed > 0 && $created === 0 => 'Veto retirado.',
            $created === 0 && $removed === 0 => 'Sin cambios en los vetados.',
            default => 'Vetados actualizados.',
        };
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array{id: int, name: string, username: ?string, member: bool}
     */
    private function personData(?User $user, array $memberIds): array
    {
        return [
            'id' => (int) $user?->id,
            'name' => (string) $user?->name,
            'username' => $user?->username,
            'member' => in_array((int) $user?->id, $memberIds, true),
        ];
    }

    private function ensureGroup(Conversation $conversation): void
    {
        abort_unless($conversation->type === 'group', 404);
    }
}
