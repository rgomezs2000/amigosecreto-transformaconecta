<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Module;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UsuariosController extends Controller
{
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('usuarios');

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

        return view('usuarios.index', [
            'profiles' => Profile::query()->orderBy('name')->get(),
        ]);
    }

    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $search = (string) $request->input('search.value', '');
        $filterType = (string) $request->input('filter_type', 'all');
        $filterRole = (int) $request->input('filter_role', 0);
        $filterStatus = (string) $request->input('filter_status', '');

        $recordsTotal = User::query()->count();

        $filtered = User::query()->with('profile')->search($search);

        if ($filterType === 'role' && $filterRole > 0) {
            $filtered->where('profile_id', $filterRole);
        }

        if ($filterType === 'status') {
            match ($filterStatus) {
                'active' => $filtered->where('is_active', true)->whereNull('locked_until'),
                'blocked' => $filtered->whereNotNull('locked_until'),
                'inactive' => $filtered->where('is_active', false),
                default => $filtered,
            };
        }

        $recordsFiltered = (clone $filtered)->count();

        $columnIndex = (int) $request->input('order.0.column', 2);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            3 => 'email',
            4 => 'username',
            default => 'name',
        };

        $query = $filtered->orderBy($column, $direction);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $users = $query->get();

        $data = $users->map(fn (User $user) => [
            'check' => '<input type="checkbox" class="form-check-input user-checkbox" value="' . $user->id . '" aria-label="Seleccionar usuario ' . e($user->name) . '">',
            'id' => $user->id,
            'name' => e($user->name),
            'email' => e($user->email),
            'username' => e($user->username),
            'role' => e($user->profile?->name ?? $user->role->label()),
            'status' => $this->statusLabel($user),
            'actions' => view('usuarios.partials.actions', ['user' => $user])->render(),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ese correo ya está registrado.',
            'username.required' => 'El usuario es obligatorio.',
            'username.unique' => 'Usuario no disponible.',
            'profile_id.required' => 'Selecciona un rol.',
            'profile_id.exists' => 'El rol seleccionado no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (User::query()->loginExists($data['username'])->exists()) {
            return api_response(false, 'Usuario no disponible.', [
                'errors' => ['username' => ['Usuario no disponible.']],
            ], 422);
        }

        try {
            $profile = Profile::query()->findOrFail($data['profile_id']);
            $role = $profile->slug === 'administrador' ? UserRole::Admin : UserRole::Participant;
            $plainPassword = Str::password(12);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => $plainPassword,
                'profile_id' => $profile->id,
                'role' => $role,
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $sent = $user->sendCredentialsEmail($plainPassword, $profile);

            $message = $sent
                ? 'Usuario creado y correo enviado correctamente.'
                : 'Usuario creado, pero no se pudo enviar el correo. Restablece la contraseña.';

            $request->session()->flash('usuarios_status', $message);

            return api_success($message, [
                'redirect' => module_url('usuarios'),
                'mail_sent' => $sent,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el usuario', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el usuario. Intenta de nuevo.', 500);
        }
    }

    public function editar(User $user): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Usuario encontrado.', [
            'user' => $this->userData($user),
        ]);
    }

    public function ver(User $user): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Usuario encontrado.', [
            'user' => $this->userData($user),
        ]);
    }

    public function modificar(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ese correo ya está registrado.',
            'username.required' => 'El usuario es obligatorio.',
            'username.unique' => 'Usuario no disponible.',
            'profile_id.required' => 'Selecciona un rol.',
            'profile_id.exists' => 'El rol seleccionado no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (User::query()->loginExists($data['username'], $user->id)->exists()) {
            return api_response(false, 'Usuario no disponible.', [
                'errors' => ['username' => ['Usuario no disponible.']],
            ], 422);
        }

        try {
            $profile = Profile::query()->findOrFail($data['profile_id']);
            $role = $profile->slug === 'administrador' ? UserRole::Admin : UserRole::Participant;

            // El superadministrador no puede perder su rol.
            if ($user->isSuperAdmin() && (int) $user->profile_id !== (int) $profile->id) {
                return api_error('El superadministrador es intocable: no puede cambiar de rol.', 409);
            }

            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'profile_id' => $profile->id,
                'role' => $role,
            ]);

            $request->session()->flash('usuarios_status', 'Usuario modificado correctamente.');

            return api_success('Usuario modificado correctamente.', [
                'redirect' => module_url('usuarios'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al modificar el usuario', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo modificar el usuario. Intenta de nuevo.', 500);
        }
    }

    public function eliminar(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        if ($user->isSuperAdmin()) {
            return api_error('El superadministrador es intocable: no se puede eliminar.', 409);
        }

        if ($request->user()?->id === $user->id) {
            return api_error('No puedes eliminar tu propio usuario.', 409);
        }

        try {
            $user->delete();

            $request->session()->flash('usuarios_status', 'Usuario eliminado correctamente.');

            return api_success('Usuario eliminado correctamente.', [
                'redirect' => module_url('usuarios'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar el usuario', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo eliminar el usuario. Intenta de nuevo.', 500);
        }
    }

    public function restablecer(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        try {
            $plainPassword = Str::password(12);
            $user->updatePassword($plainPassword);

            $sent = $user->sendPasswordResetEmail($plainPassword);

            $message = $sent
                ? 'Contraseña restablecida y correo enviado.'
                : 'Contraseña restablecida, pero no se pudo enviar el correo.';

            $request->session()->flash('usuarios_status', $message);

            return api_success($message, [
                'redirect' => module_url('usuarios'),
                'mail_sent' => $sent,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al restablecer la contraseña', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo restablecer la contraseña. Intenta de nuevo.', 500);
        }
    }

    public function bloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        if ($user->isSuperAdmin()) {
            return api_error('El superadministrador es intocable: no se puede bloquear.', 409);
        }

        if ($request->user()?->id === $user->id) {
            return api_error('No puedes bloquear tu propio usuario.', 409);
        }

        try {
            $user->forceFill([
                'is_active' => false,
                'locked_until' => now(),
                'failed_login_attempts' => 0,
            ])->save();

            $request->session()->flash('usuarios_status', 'Usuario bloqueado correctamente.');

            return api_success('Usuario bloqueado correctamente.', [
                'redirect' => module_url('usuarios'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al bloquear el usuario', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo bloquear el usuario. Intenta de nuevo.', 500);
        }
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        $this->ensureAccess();

        try {
            $user->forceFill([
                'is_active' => true,
                'locked_until' => null,
                'failed_login_attempts' => 0,
            ])->save();

            $request->session()->flash('usuarios_status', 'Usuario desbloqueado correctamente.');

            return api_success('Usuario desbloqueado correctamente.', [
                'redirect' => module_url('usuarios'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al desbloquear el usuario', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo desbloquear el usuario. Intenta de nuevo.', 500);
        }
    }

    public function eliminarVarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ], [
            'ids.required' => 'Selecciona al menos un usuario.',
            'ids.*.exists' => 'Uno de los usuarios seleccionados no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $requested = collect($request->input('ids'))
            ->map(fn ($id) => (int) $id);

        $superAdminId = User::superAdmin()?->id;

        $ids = $requested
            ->reject(fn ($id) => $id === $request->user()?->id)
            ->reject(fn ($id) => $superAdminId !== null && $id === $superAdminId)
            ->values();

        if ($ids->isEmpty()) {
            if ($superAdminId !== null && $requested->contains($superAdminId)) {
                return api_error('El superadministrador es intocable: no se puede eliminar.', 409);
            }

            return api_error('No puedes eliminar tu propio usuario.', 409);
        }

        try {
            $deleted = User::query()->whereIn('id', $ids)->delete();

            $message = $deleted . ' usuario(s) eliminado(s).';

            $request->session()->flash('usuarios_status', $message);

            return api_success($message, [
                'redirect' => module_url('usuarios'),
                'deleted' => $deleted,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar varios usuarios', ['error' => $exception->getMessage()]);

            return api_error('No se pudieron eliminar los usuarios. Intenta de nuevo.', 500);
        }
    }

    private function statusLabel(User $user): string
    {
        if (! $user->is_active) {
            return 'Inactivo';
        }

        if ($user->locked_until !== null) {
            return 'Bloqueado';
        }

        return 'Activo';
    }

    /**
     * @return array{id:int,name:string,email:string,username:string,profile_id:?int,role:string,status:string,is_active:bool,locked:bool,created_at:string}
     */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'profile_id' => $user->profile_id,
            'role' => $user->profile?->name ?? $user->role->label(),
            'status' => $this->statusLabel($user),
            'is_active' => $user->is_active,
            'locked' => $user->locked_until !== null,
            'created_at' => format_date($user->created_at),
        ];
    }
}
