<?php

namespace Database\Seeders;

use App\Models\Profile;
use Illuminate\Database\Seeder;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        // Solo primera instalación: si ya hay perfiles, no se tocan.
        if (Profile::query()->exists()) {
            return;
        }

        Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'description' => 'Acceso total al sistema.',
            'is_active' => true,
        ]);

        Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'description' => 'Participante del amigo secreto.',
            'is_active' => true,
        ]);
    }
}
