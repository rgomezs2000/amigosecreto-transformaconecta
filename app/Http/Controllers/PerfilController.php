<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PerfilController extends Controller
{
    /**
     * Muestra el perfil propio: datos, rol, módulos y permisos.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $module = Module::query()
            ->where('slug', 'perfiles')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! $user->hasModuleAccess($module)) {
            abort(403);
        }

        $modules = $user->accessibleModules()->map(function (Module $item) use ($user) {
            return [
                'module' => $item,
                'permissions' => $user->permissionsForModule($item),
            ];
        });

        return view('perfil.index', [
            'user' => $user,
            'module' => $module,
            'modules' => $modules,
            'perfil' => $user->profile,
        ]);
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
                'redirect' => route('perfil.index'),
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
                'redirect' => route('perfil.index'),
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
