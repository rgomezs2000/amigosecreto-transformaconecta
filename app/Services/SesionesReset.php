<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cierre de sesiones durante el restablecimiento de la dinámica.
 *
 * No se borra la fila de la sesión: se le quita la autenticación y se guarda
 * un aviso. Así la persona queda fuera del sistema (logout real) y, la próxima
 * vez que abra el sistema, ve en la pantalla de acceso el mensaje del
 * restablecimiento.
 */
class SesionesReset
{
    /** Clave con la que el aviso viaja en la sesión hasta la próxima visita. */
    public const CLAVE_AVISO = 'restablecimiento_aviso';

    /**
     * Cierra todas las sesiones abiertas de un usuario y deja el aviso.
     */
    public function desconectar(int $userId, string $mensaje): int
    {
        $sesiones = DB::table('sessions')->where('user_id', $userId)->get();

        if ($sesiones->isEmpty()) {
            return 0;
        }

        $clave = $this->claveDeAutenticacion();
        $cerradas = 0;

        foreach ($sesiones as $sesion) {
            $datos = $this->decodificar((string) $sesion->payload);

            // El guard deja de encontrar al usuario: sesión cerrada.
            unset($datos[$clave]);

            $datos[self::CLAVE_AVISO] = $mensaje;
            $datos['_flash'] = ['old' => [], 'new' => [self::CLAVE_AVISO]];

            DB::table('sessions')->where('id', $sesion->id)->update([
                'user_id' => null,
                'payload' => $this->codificar($datos),
                'last_activity' => now()->getTimestamp(),
            ]);

            $cerradas++;
        }

        return $cerradas;
    }

    /**
     * Borra las sesiones de invitados: no hay a quién avisar.
     */
    public function limpiarInvitadas(): int
    {
        return DB::table('sessions')->whereNull('user_id')->delete();
    }

    /**
     * Nombre de la clave con la que el guard guarda el usuario en la sesión.
     */
    private function claveDeAutenticacion(): string
    {
        return auth('web')->getName();
    }

    /**
     * El driver de sesiones en base de datos guarda base64(serialize( datos )).
     *
     * @return array<string, mixed>
     */
    private function decodificar(string $payload): array
    {
        $decodificado = base64_decode($payload, true);

        if ($decodificado === false) {
            return [];
        }

        $datos = @unserialize($decodificado);

        return is_array($datos) ? $datos : [];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function codificar(array $datos): string
    {
        return base64_encode(serialize($datos));
    }
}
