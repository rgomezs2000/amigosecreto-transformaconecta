<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recupera el acceso del superadministrador cuando su cuenta quedó bloqueada.
 *
 * Es un comando de emergencia para ejecutar a mano (proceso aislado, sin
 * programación): si la cuenta está bloqueada la desbloquea y le devuelve la
 * contraseña original; si no está bloqueada, termina sin tocar nada.
 */
class DesbloquearSuperAdmin extends Command
{
    protected $signature = 'superadmin:desbloquear
        {--password= : Contraseña con la que dejarla (por defecto, la original del .env)}
        {--force : Permite usar la contraseña de ejemplo aunque el entorno sea producción}';

    protected $description = 'Desbloquea al superadministrador, elimina sus intentos fallidos y restablece su contraseña original';

    public function handle(): int
    {
        $user = User::superAdmin();

        if ($user === null) {
            $this->error('No se encontró ninguna cuenta de superadministrador (ADMIN_USERNAME).');

            return self::FAILURE;
        }

        if ($user->role !== UserRole::Admin) {
            $this->error("La cuenta configurada como superadministrador ({$user->username}) no tiene rol de administración.");

            return self::FAILURE;
        }

        $identifier = $user->email
            ? "{$user->username} ({$user->email})"
            : $user->username;

        if (! $this->isBlocked($user)) {
            // Aunque no esté bloqueada, se deja la cuenta sin intentos fallidos
            // pendientes: el comando siempre la devuelve a cero.
            $attempts = $this->clearFailedAttempts($user);

            $this->info("El superadministrador «{$identifier}» no está bloqueado. No se cambia su contraseña.");
            $this->line($attempts > 0
                ? "Se eliminaron {$attempts} intento(s) fallido(s) pendiente(s)."
                : 'No tenía intentos fallidos registrados.');

            return self::SUCCESS;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $attempts = (int) $user->failed_login_attempts;

        try {
            $user->forceFill([
                'is_active' => true,
                'locked_until' => null,
                // Se borran todos los intentos fallidos, no solo el bloqueo.
                'failed_login_attempts' => 0,
                'password' => Hash::make($password),
                'must_change_password' => (bool) config('amigosecreto.admin.force_password_change', false),
                // Cierra las sesiones «recordarme» abiertas con la clave anterior.
                'remember_token' => Str::random(60),
            ])->save();
        } catch (Throwable $exception) {
            $this->error('No se pudo desbloquear la cuenta: '.$exception->getMessage());

            return self::FAILURE;
        }

        Log::warning('Superadministrador desbloqueado desde la consola', [
            'user_id' => $user->id,
            'username' => $user->username,
            'comando' => 'superadmin:desbloquear',
            'intentos_fallidos_eliminados' => $attempts,
        ]);

        $this->info("Cuenta desbloqueada: {$identifier}.");
        $this->line($this->hasCustomPassword()
            ? 'Contraseña restablecida a la indicada con --password.'
            : 'Contraseña restablecida a la original (ADMIN_PASSWORD del .env).');
        $this->line($attempts > 0
            ? "Intentos fallidos eliminados: {$attempts} (la cuenta queda en 0)."
            : 'La cuenta queda sin intentos fallidos.');
        $this->line('Ya puede iniciar sesión con ella.');

        return self::SUCCESS;
    }

    /**
     * Deja el contador de intentos fallidos en cero.
     *
     * @return int Intentos que se eliminaron.
     */
    private function clearFailedAttempts(User $user): int
    {
        $attempts = (int) $user->failed_login_attempts;

        if ($attempts === 0) {
            return 0;
        }

        $user->forceFill(['failed_login_attempts' => 0])->save();

        Log::info('Intentos fallidos eliminados por consola', [
            'user_id' => $user->id,
            'username' => $user->username,
            'intentos' => $attempts,
            'comando' => 'superadmin:desbloquear',
        ]);

        return $attempts;
    }

    /**
     * Un bloqueo es cualquier `locked_until` (por intentos fallidos o por
     * bloqueo manual) o una cuenta desactivada desde administración.
     */
    private function isBlocked(User $user): bool
    {
        return $user->isLocked() || ! $user->is_active;
    }

    private function hasCustomPassword(): bool
    {
        $custom = $this->option('password');

        return is_string($custom) && trim($custom) !== '';
    }

    private function resolvePassword(): ?string
    {
        $password = $this->hasCustomPassword()
            ? (string) $this->option('password')
            : (string) config('amigosecreto.admin.password');

        if (mb_strlen(trim($password)) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres. Use --password=…');

            return null;
        }

        if (app()->environment('production')
            && $password === 'Admin123456'
            && ! $this->option('force')) {
            $this->error('La contraseña es la de ejemplo. Defina ADMIN_PASSWORD en el .env, use --password=… o añada --force.');

            return null;
        }

        return $password;
    }
}
