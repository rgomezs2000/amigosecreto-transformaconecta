<?php

namespace Database\Seeders;

use App\Models\Profile;
use Illuminate\Database\Seeder;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        Profile::updateOrCreate(
            ['slug' => 'administrador'],
            [
                'name' => 'Administrador',
                'description' => 'Acceso total al sistema.',
                'is_active' => true,
            ],
        );

        Profile::updateOrCreate(
            ['slug' => 'usuario'],
            [
                'name' => 'Usuario',
                'description' => 'Participante del amigo secreto.',
                'is_active' => true,
            ],
        );
    }
}
