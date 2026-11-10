<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UnlockUser extends Command
{
    protected $signature = 'user:unlock {login : Usuario o correo de la cuenta}';

    protected $description = 'Desbloquea una cuenta bloqueada por intentos fallidos';

    public function handle(): int
    {
        $login = (string) $this->argument('login');

        $user = User::query()->findByLogin($login)->first();

        if ($user === null) {
            $this->error("No existe una cuenta con «{$login}».");

            return self::FAILURE;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $identifier = $user->email
            ? "{$user->username} ({$user->email})"
            : $user->username;

        $this->info("Cuenta desbloqueada: {$identifier}.");

        return self::SUCCESS;
    }
}
