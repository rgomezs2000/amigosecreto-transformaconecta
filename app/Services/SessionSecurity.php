<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión única por usuario.
 *
 * La sesión se guarda en la tabla `sessions` (SESSION_DRIVER=database) con su
 * IP. Cada nuevo inicio de sesión elimina todas las sesiones anteriores de esa
 * cuenta, aunque la IP sea la misma. La sesión vieja deja de existir y, en su
 * siguiente petición, el middleware de autenticación la manda al login.
 */
class SessionSecurity
{
    public static function kickOtherSessions(User $user, Request $request): int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return 0;
        }

        $currentId = $request->session()->getId();

        if ($currentId === '') {
            return 0;
        }

        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentId)
            ->delete();
    }
}
