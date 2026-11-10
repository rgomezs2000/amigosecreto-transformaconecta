<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('amigosecreto.admin');
        $password = (string) $config['password'];

        if (app()->environment('production') && $password === 'Admin123456') {
            throw new RuntimeException(
                'Define ADMIN_PASSWORD en el .env antes de sembrar el administrador en producción.'
            );
        }

        User::updateOrCreate(
            ['username' => strtolower((string) $config['username'])],
            [
                'name' => $config['name'],
                'email' => $config['email'],
                'password' => $password,
                'role' => UserRole::Admin,
                'profile_id' => Profile::query()->where('slug', 'administrador')->value('id'),
                'is_active' => true,
                'must_change_password' => (bool) $config['force_password_change'],
            ],
        );
    }
}
