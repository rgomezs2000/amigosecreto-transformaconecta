<?php

namespace App\Http\Controllers;

use App\Services\SessionSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class SessionController extends Controller
{
    /**
     * Sondeo de la sesión activa. Si otra sesión reemplazó a esta, avisa y
     * destruye la sesión vieja.
     */
    public function estado(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return api_error('Sin sesión.', 401);
        }

        if (! SessionSecurity::isCurrent($user, $request)) {
            $message = SessionSecurity::KICKED_MESSAGE;

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return api_success($message, [
                'kicked' => true,
                'message' => $message,
            ]);
        }

        // Limpieza oportunista: cada 30 s, al recibir el latido de una sesión
        // activa, se destruyen las sesiones que ya dejaron de responder.
        try {
            if (Cache::add('session-orphan-cleanup', true, 30)) {
                SessionSecurity::cleanupOrphans(
                    null,
                    $request->session()->getId()
                );
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return api_success('Sesión activa.', ['kicked' => false]);
    }
}
