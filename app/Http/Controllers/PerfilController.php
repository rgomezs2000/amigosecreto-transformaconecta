<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class PerfilController extends Controller
{
    /**
     * Módulo compartido que habilita la página de perfil.
     */
    private function perfilModule(): Module
    {
        $module = Module::activeByCode('perfil');

        if ($module === null) {
            abort(404);
        }

        return $module;
    }

    private function ensureAccess(?User $user = null): Module
    {
        $module = $this->perfilModule();
        $user ??= auth()->user();

        if (! $user?->hasModuleAccess($module)) {
            abort(403);
        }

        return $module;
    }

    /**
     * Muestra el perfil propio: datos y la matriz del perfil (sus accesos).
     */
    public function index(Request $request): View
    {
        $module = $this->ensureAccess($request->user());
        $user = $request->user();

        $filas = $this->matrizFilas($user);

        return view('perfil.index', [
            'user' => $user,
            'module' => $module,
            'permisos' => $this->permisosDisponibles($filas),
            'perfil' => $user->profile,
        ]);
    }

    /**
     * Matriz del perfil en formato DataTable (búsqueda y filtros en servidor).
     */
    public function matrizDatos(Request $request): JsonResponse
    {
        $this->ensureAccess($request->user());

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = min(max(0, (int) $request->input('length', 10)), 200);
        $search = trim((string) $request->input('search.value', ''));
        $filterType = (string) $request->input('filter_type', 'all');
        $filterPermission = (int) $request->input('filter_permission', 0);

        $filas = $this->matrizFilas($request->user());
        $total = count($filas);

        if ($search !== '') {
            $needle = Str::lower($search);

            $filas = array_values(array_filter($filas, function (array $fila) use ($needle) {
                $haystack = Str::lower(implode(' ', [
                    $fila['name'],
                    $fila['group'],
                    $fila['permissions']->pluck('name')->implode(' '),
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        if ($filterType === 'with_permissions') {
            $filas = array_values(array_filter(
                $filas,
                fn (array $fila) => $fila['permissions']->isNotEmpty()
            ));
        }

        if ($filterType === 'permission' && $filterPermission > 0) {
            $filas = array_values(array_filter(
                $filas,
                fn (array $fila) => $fila['permissions']->contains(
                    fn (Permission $permiso) => (int) $permiso->id === $filterPermission
                )
            ));
        }

        $filtrados = count($filas);

        $orden = (int) $request->input('order.0.column', 0) === 1 ? 'group' : 'name';
        $descendente = $request->input('order.0.dir') === 'desc';

        usort($filas, function (array $a, array $b) use ($orden, $descendente) {
            $comparacion = strnatcasecmp($a[$orden], $b[$orden]);

            if ($comparacion === 0) {
                $comparacion = strnatcasecmp($a['name'], $b['name']);
            }

            return $descendente ? -$comparacion : $comparacion;
        });

        if ($length > 0) {
            $filas = array_slice($filas, $start, $length);
        }

        $data = array_map(function (array $fila) {
            $permisos = $fila['permissions']->map(
                fn (Permission $permiso) => '<span class="badge perfil-badge">' . e($permiso->name) . '</span>'
            )->implode('');

            return [
                'module' => '<span class="perfil-module"><i class="fa-solid ' . e($fila['icon'])
                    . '" aria-hidden="true"></i>' . e($fila['name']) . '</span>',
                'group' => e($fila['group']),
                'permissions' => $permisos !== '' ? $permisos : '<span class="text-muted">Sin permisos</span>',
            ];
        }, $filas);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtrados,
            'data' => $data,
        ]);
    }

    /**
     * Filas de la matriz del perfil: módulos accesibles y permisos efectivos.
     *
     * @return array<int, array{id:int,name:string,group:string,icon:string,permissions:Collection<int,Permission>}>
     */
    private function matrizFilas(User $user): array
    {
        return $user->accessibleModules()->map(function (Module $item) use ($user) {
            return [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'group' => (string) $item->group,
                'icon' => (string) ($item->icon ?: 'fa-circle'),
                'permissions' => $user->permissionsForModule($item)->sortBy('name')->values(),
            ];
        })->values()->all();
    }

    /**
     * Permisos presentes en la matriz, para el filtro «Permiso».
     *
     * @param  array<int, array{permissions:Collection<int,Permission>}>  $filas
     * @return Collection<int, Permission>
     */
    private function permisosDisponibles(array $filas): Collection
    {
        return collect($filas)
            ->flatMap(fn (array $fila) => $fila['permissions'])
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Cambia nombre, correo y/o login del usuario autenticado.
     */
    public function cambiarPerfil(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ese correo ya está registrado.',
            'username.required' => 'El usuario es obligatorio.',
            'username.unique' => 'Usuario no disponible.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Refuerzo insensible a mayúsculas/minúsculas.
        if (User::query()->loginExists($data['username'], $user->id)->exists()) {
            return api_response(false, 'Usuario no disponible.', [
                'errors' => ['username' => ['Usuario no disponible.']],
            ], 422);
        }

        try {
            $user->updateProfile($data);

            $request->session()->flash('perfil_status', 'Perfil actualizado correctamente.');

            return api_success('Perfil actualizado correctamente.', [
                'redirect' => module_url('perfil'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al actualizar el perfil', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo actualizar el perfil. Intenta de nuevo.', 500);
        }
    }

    /**
     * Cambia la clave validando la actual y controlando los intentos.
     */
    public function cambiarClave(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'current_password.required' => 'Escribe tu clave actual.',
            'password.required' => 'Escribe la nueva clave.',
            'password.min' => 'La nueva clave debe tener al menos 10 caracteres.',
            'password.confirmed' => 'Las claves no coinciden.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (! Hash::check($data['current_password'], $user->password)) {
            $maxAttempts = (int) config('amigosecreto.login.max_attempts', 3);

            $user->registerFailedLogin($maxAttempts);

            if ($user->isLocked()) {
                // Cierra la sesión con el mismo método del logout.
                app(LogoutController::class)->logout($request);

                return api_error('Tu usuario se encuentra bloqueado. Contacta al administrador.', 423, [
                    'locked' => true,
                    'redirect' => route('login', ['locked' => 1]),
                ]);
            }

            $remaining = max(0, $maxAttempts - (int) $user->failed_login_attempts);

            return api_error("La clave actual es incorrecta. Te quedan {$remaining} intento(s).", 401, [
                'errors' => ['current_password' => ['La clave actual es incorrecta.']],
                'remaining_attempts' => $remaining,
            ]);
        }

        try {
            $user->resetLoginAttempts();
            $user->updatePassword($data['password']);

            $request->session()->flash('perfil_status', 'Contraseña modificada exitosamente.');

            return api_success('Contraseña modificada exitosamente.', [
                'redirect' => module_url('perfil'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al cambiar la contraseña', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo cambiar la contraseña. Intenta de nuevo.', 500);
        }
    }
}
