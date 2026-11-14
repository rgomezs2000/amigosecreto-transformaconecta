<?php

namespace App\Http\Middleware;

use App\Services\SessionSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea una sesión que fue reemplazada por un inicio de sesión más reciente
 * de la misma cuenta. El sondeo del navegador se encarga de mostrar el Bootbox.
 */
class EnsureSingleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $kick = SessionSecurity::pendingKick($request);

        if ($kick !== null) {
            // Solo el sondeo consume el aviso; otros AJAX no deben robárselo
            // antes de que el navegador pueda mostrar el Bootbox.
            if ($request->is('sesion/estado')) {
                SessionSecurity::consumeKick($kick);

                return response()->json([
                    'success' => true,
                    'session_kicked' => true,
                    'kicked' => true,
                    'message' => $kick->message ?: SessionSecurity::KICKED_MESSAGE,
                ]);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'session_kicked' => true,
                    'message' => $kick->message ?: SessionSecurity::KICKED_MESSAGE,
                ], 409);
            }

            if ($request->is('login') && $request->query('sesion') === 'otra') {
                SessionSecurity::consumeKick($kick);

                return $next($request);
            }

            return redirect()->route('login', ['sesion' => 'otra']);
        }

        $user = $request->user();

        if ($user === null || SessionSecurity::isCurrent($user, $request)) {
            return $next($request);
        }

        // Rutas que deben seguir disponibles para poder avisar o volver a
        // autenticarse.
        if ($request->is('sesion/estado')
            || $request->is('login')
            || $request->is('login/*')
            || $request->is('logout')
            || $request->is('auth/google/*')) {
            return $next($request);
        }

        $message = SessionSecurity::KICKED_MESSAGE;

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'session_kicked' => true,
                'message' => $message,
            ], 409);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login', ['sesion' => 'otra']);
    }
}
