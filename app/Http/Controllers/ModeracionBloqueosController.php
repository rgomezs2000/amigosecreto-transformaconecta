<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesModeracion;
use App\Models\ChatBlock;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueos entre usuarios vistos desde Moderación.
 *
 * El listado muestra a todos los usuarios del sistema con la cantidad de
 * personas que tienen bloqueadas. El modal de «Gestionar bloqueos» trabaja
 * con dos listas (desbloqueados y bloqueados) y guarda el estado completo
 * de cada lado en cada movimiento.
 */
class ModeracionBloqueosController extends Controller
{
    use AuthorizesModeracion;

    /**
     * Listado paginado de usuarios para la tabla del módulo.
     */
    public function usuarios(Request $request): JsonResponse
    {
        $this->ensureModeracionAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(100, max(1, (int) $request->input('length', 10)));
        $search = trim((string) $request->input('search.value', ''));

        $recordsTotal = User::query()->count();

        $query = User::query()->with('profile')->search($search);

        $filterType = (string) $request->input('filter_type', 'all');

        if ($filterType === 'role') {
            $role = (string) $request->input('role', '');

            if (in_array($role, ['admin', 'participant'], true)) {
                $query->where('role', $role);
            }
        }

        $recordsFiltered = (clone $query)->count();

        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $column = (int) $request->input('order.0.column', 0);

        $orderColumn = match ($column) {
            1 => 'username',
            3 => 'blocked_users_count',
            default => 'name',
        };

        $users = $query
            ->withCount('blockedUsers')
            ->orderBy($orderColumn, $direction)
            ->orderBy('id')
            ->skip($start)
            ->take($length)
            ->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => e($user->name),
                'name_raw' => $user->name,
                'username' => e($user->username ?? '—'),
                'role' => e($user->profile?->name ?? $user->role->label()),
                'blocked_count' => (int) $user->blocked_users_count,
                'actions' => view('moderacion.partials.block-actions', ['user' => $user])->render(),
            ])->all(),
        ]);
    }

    /**
     * Estado actual de los bloqueos de un usuario, en dos listas.
     */
    public function detalle(User $user): JsonResponse
    {
        $this->ensureModeracionAccess();

        $blocked = $user->blockedUsers()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username']);

        $blockedIds = $blocked->pluck('id')->map(fn ($id) => (int) $id)->all();

        $available = User::query()
            ->whereKeyNot($user->id)
            ->whereNotIn('id', $blockedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);

        return api_success('Bloqueos del usuario.', [
            'subject' => $this->personData($user),
            'blocked' => $blocked->map(fn (User $item) => $this->personData($item))->values()->all(),
            'available' => $available->map(fn (User $item) => $this->personData($item))->values()->all(),
        ]);
    }

    /**
     * Sincroniza los bloqueos del usuario con las listas recibidas.
     *
     * Las dos listas son completas: lo que está en «blocked» queda bloqueado
     * y lo que está en «available» deja de estarlo.
     */
    public function sincronizar(Request $request, User $user): JsonResponse
    {
        $this->ensureModeracionAccess();

        $validator = validator($request->all(), [
            'available' => ['present', 'array'],
            'available.*' => ['integer', 'distinct', 'exists:users,id'],
            'blocked' => ['present', 'array'],
            'blocked.*' => ['integer', 'distinct', 'exists:users,id'],
        ], [
            'available.present' => 'Falta la lista de usuarios desbloqueados.',
            'available.*.integer' => 'La lista de desbloqueados trae un usuario inválido.',
            'available.*.exists' => 'Uno de los usuarios desbloqueados ya no existe.',
            'blocked.present' => 'Falta la lista de usuarios bloqueados.',
            'blocked.*.integer' => 'La lista de bloqueados trae un usuario inválido.',
            'blocked.*.exists' => 'Uno de los usuarios bloqueados ya no existe.',
        ]);

        if ($validator->fails()) {
            return api_error($validator->errors()->first(), 422, ['errors' => $validator->errors()]);
        }

        /** @var array<int, int> $available */
        $available = array_map('intval', $validator->validated()['available']);
        /** @var array<int, int> $blocked */
        $blocked = array_map('intval', $validator->validated()['blocked']);

        if (array_intersect($available, $blocked) !== []) {
            return api_error('Un usuario no puede estar en las dos listas a la vez.', 422);
        }

        if (in_array($user->id, $blocked, true)) {
            return api_error('Un usuario no puede bloquearse a sí mismo.', 422);
        }

        if (in_array($user->id, $available, true)) {
            return api_error('El usuario gestionado no debe aparecer en las listas.', 422);
        }

        $created = 0;
        $removed = 0;

        DB::transaction(function () use ($user, $available, $blocked, &$created, &$removed) {
            $current = ChatBlock::query()
                ->where('blocker_id', $user->id)
                ->pluck('blocked_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $toCreate = array_values(array_diff($blocked, $current));
            $toRemove = array_values(array_intersect($available, $current));

            foreach ($toCreate as $blockedId) {
                ChatBlock::query()->firstOrCreate([
                    'blocker_id' => $user->id,
                    'blocked_id' => $blockedId,
                ]);

                $created++;
            }

            if ($toRemove !== []) {
                $removed = ChatBlock::query()
                    ->where('blocker_id', $user->id)
                    ->whereIn('blocked_id', $toRemove)
                    ->delete();
            }
        });

        return api_success($this->messageFor($created, $removed), [
            'created' => $created,
            'removed' => $removed,
        ]);
    }

    private function messageFor(int $created, int $removed): string
    {
        return match (true) {
            $created > 0 && $removed === 0 => 'Bloqueo creado.',
            $removed > 0 && $created === 0 => 'Bloqueo eliminado.',
            $created === 0 && $removed === 0 => 'Sin cambios en los bloqueos.',
            default => 'Bloqueos actualizados.',
        };
    }

    /**
     * @return array{id: int, name: string, username: ?string}
     */
    private function personData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
        ];
    }
}
