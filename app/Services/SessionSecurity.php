<?php

namespace App\Services;

use App\Models\SessionKick;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión única por usuario.
 *
 * Al iniciar una sesión nueva se eliminan de inmediato las sesiones anteriores
 * de esa cuenta, sin importar la IP. Antes de borrarlas se guarda un aviso por
 * session_id para que el navegador viejo pueda mostrar el Bootbox y volver al
 * login.
 */
class SessionSecurity
{
    public const KICKED_MESSAGE = 'Tu sesión se cerró porque la misma cuenta inició sesión en otro dispositivo o ubicación.';

    public static function activate(User $user, Request $request): int
    {
        $currentId = $request->session()->getId();
        $kicked = 0;

        if (Schema::hasTable('sessions')) {
            $oldIds = DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $currentId)
                ->pluck('id');

            if ($oldIds->isNotEmpty()) {
                if (Schema::hasTable('session_kicks')) {
                    $now = now();

                    SessionKick::query()->insertOrIgnore(
                        $oldIds->map(fn ($id) => [
                            'session_id' => (string) $id,
                            'user_id' => $user->getKey(),
                            'message' => self::KICKED_MESSAGE,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                }

                // Destruye el contenido autenticado de la sesión anterior,
                // pero conserva su ID como lápida para poder avisar al
                // navegador viejo sin que Laravel le rote la cookie.
                DB::table('sessions')->whereIn('id', $oldIds->all())->update([
                    'user_id' => null,
                    'payload' => '',
                    'last_activity' => now()->timestamp,
                ]);
                $kicked = $oldIds->count();
            }
        }

        if (Schema::hasColumn('users', 'active_session_id')) {
            $user->forceFill(['active_session_id' => $currentId])->save();
        }

        return $kicked;
    }

    public static function isCurrent(User $user, Request $request): bool
    {
        return blank($user->active_session_id)
            || $user->active_session_id === $request->session()->getId();
    }

    public static function clear(User $user, Request $request): void
    {
        if (Schema::hasColumn('users', 'active_session_id')
            && $user->active_session_id === $request->session()->getId()) {
            $user->forceFill(['active_session_id' => null])->save();
        }
    }

    /**
     * Aviso pendiente para la cookie de sesión que acaba de ser reemplazada.
     */
    public static function pendingKick(Request $request): ?SessionKick
    {
        if (! Schema::hasTable('session_kicks')) {
            return null;
        }

        $sessionId = (string) $request->cookies->get((string) config('session.cookie'));

        if ($sessionId === '') {
            return null;
        }

        return SessionKick::query()
            ->where('session_id', $sessionId)
            ->whereNull('consumed_at')
            ->first();
    }

    public static function consumeKick(SessionKick $kick): void
    {
        $kick->forceFill(['consumed_at' => now()])->save();
    }
}
