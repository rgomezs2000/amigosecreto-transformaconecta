<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirige a la pantalla de compatibilidad los motores históricos que no
 * pueden ejecutar el front-end actual, incluso si JavaScript está desactivado.
 *
 * La detección fina de sistemas operativos y versiones se completa en el
 * navegador con public/js/browser-support.js.
 */
class EnsureSupportedBrowser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('compatibilidad') || $request->is('compatibilidad/*')) {
            return $next($request);
        }

        $userAgent = (string) $request->userAgent();

        if (preg_match('/MSIE\s|Trident\/|Edge\/|Presto\/|Konqueror\//i', $userAgent)) {
            if (Auth::check()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este navegador no es compatible con el sistema. Consulta la pantalla de compatibilidad.',
                    'compatibility_url' => route('compatibilidad'),
                    'motivo' => 'incompatible',
                ], 426);
            }

            return redirect()->route('compatibilidad', [
                'from' => $request->fullUrl(),
                'motivo' => 'incompatible',
            ]);
        }

        return $next($request);
    }
}
