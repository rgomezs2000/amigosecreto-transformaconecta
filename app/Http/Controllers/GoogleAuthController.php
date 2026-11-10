<?php

namespace App\Http\Controllers;

use App\Models\User;
use Google\Client;
use Google\Service\Oauth2;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Inicia el flujo OAuth de Google.
     */
    public function googleLogin(Client $client): RedirectResponse
    {
        try {
            if (! $this->isConfigured()) {
                return redirect()->route('login', ['google' => 'not_configured']);
            }

            return redirect()->away($client->createAuthUrl());
        } catch (Throwable $exception) {
            Log::error('Google: no se pudo iniciar el flujo', [
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('login', ['google' => 'error']);
        }
    }

    /**
     * Recibe el código de Google, lee el correo y busca la cuenta.
     */
    public function handleCallback(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        try {
            if (! $request->filled('code')) {
                return $this->result($request, 'cancelled');
            }

            $client->authenticate($request->query('code'));

            $oauth2 = new Oauth2($client);
            $googleUser = $oauth2->userinfo->get();

            $email = $googleUser->getEmail();

            if (! $email) {
                return $this->result($request, 'error');
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();

            if ($user === null) {
                return $this->result($request, 'not_found');
            }

            if (! $user->is_active) {
                return $this->result($request, 'inactive');
            }

            if ($user->isLocked()) {
                return $this->result($request, 'locked');
            }

            $user->resetLoginAttempts();

            Auth::login($user);
            $request->session()->regenerate();

            return $this->result($request, 'ok', ['name' => $user->name], route('dashboard'));
        } catch (Throwable $exception) {
            Log::error('Google: error en el callback', [
                'error' => $exception->getMessage(),
            ]);

            return $this->result($request, 'error');
        }
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * Responde JSON si la petición lo pide; si no, vuelve al login con el
     * resultado en la URL para que el frontend muestre el Bootbox.
     *
     * @param  array<string, mixed>  $data
     */
    private function result(Request $request, string $status, array $data = [], ?string $redirect = null): RedirectResponse|JsonResponse
    {
        $messages = [
            'ok' => 'Acceso correcto con Google.',
            'not_found' => 'No existe una cuenta con ese correo. Pide al administrador que te cree una.',
            'inactive' => 'La cuenta está inactiva. Contacta al administrador.',
            'locked' => 'La cuenta está bloqueada. Contacta al administrador.',
            'cancelled' => 'Cancelaste el acceso con Google.',
            'error' => 'No se pudo completar el acceso con Google. Intenta de nuevo.',
            'not_configured' => 'Google no está configurado en este entorno.',
        ];

        $message = $messages[$status] ?? $messages['error'];
        $success = $status === 'ok';

        if ($success && $redirect !== null) {
            if ($request->expectsJson()) {
                return api_success($message, array_merge([
                    'google' => $status,
                ], $data, ['redirect' => $redirect]));
            }

            return redirect()->to($redirect);
        }

        if ($request->expectsJson()) {
            return api_response($success, $message, array_merge([
                'google' => $status,
            ], $data), $success ? 200 : 401);
        }

        return redirect()->route('login', array_merge([
            'google' => $status,
        ], $data));
    }
}
