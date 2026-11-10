<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LoginController extends Controller
{
    /**
     * Muestra la pantalla de acceso.
     */
    public function index(): View
    {
        return view('auth.login');
    }

    /**
     * Valida usuario/correo y clave. Siempre responde JSON para el AJAX.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'login.required' => 'Escribe tu usuario o correo.',
            'password.required' => 'Escribe tu clave.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $user = User::query()->findByLogin($data['login'])->first();

            if ($user === null) {
                return api_error('Usuario o clave incorrectos.', 401);
            }

            if (! $user->is_active) {
                return api_error('La cuenta está inactiva. Contacta al administrador.', 403);
            }

            if ($user->isLocked()) {
                return api_error('La cuenta está bloqueada por demasiados intentos. Contacta al administrador.', 423, [
                    'locked' => true,
                ]);
            }

            if (! $user->hasPassword()) {
                return api_error('Esta cuenta se creó con Google. Ingresa con el botón de Google.', 401);
            }

            if (! Hash::check($data['password'], $user->password)) {
                $maxAttempts = (int) config('amigosecreto.login.max_attempts', 3);

                $user->registerFailedLogin($maxAttempts);

                if ($user->isLocked()) {
                    return api_error('La cuenta quedó bloqueada por demasiados intentos. Contacta al administrador.', 423, [
                        'locked' => true,
                    ]);
                }

                $remaining = max(0, $maxAttempts - (int) $user->failed_login_attempts);

                return api_error("Usuario o clave incorrectos. Te quedan {$remaining} intento(s).", 401, [
                    'locked' => false,
                    'remaining_attempts' => $remaining,
                ]);
            }

            $user->resetLoginAttempts();

            Auth::login($user);
            $request->session()->regenerate();

            return api_success('Acceso correcto.', [
                'redirect' => route('dashboard'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al validar el acceso', [
                'login' => $data['login'],
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo completar el acceso. Intenta de nuevo.', 500);
        }
    }
}
