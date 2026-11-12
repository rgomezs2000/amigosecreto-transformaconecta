<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar solo al superadministrador del sistema.
 *
 * El módulo de restablecer la dinámica no está disponible para cualquier
 * administrador: únicamente para la cuenta intocable.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        abort_unless(
            $user->isSuperAdmin(),
            403,
            'Solo el superadministrador puede usar este módulo.'
        );

        return $next($request);
    }
}
