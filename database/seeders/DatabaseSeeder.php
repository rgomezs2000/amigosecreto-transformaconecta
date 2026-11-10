<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Solo el administrador: los participantes y el evento los crea él desde
     * el panel. No se siembran datos de ejemplo.
     */
    public function run(): void
    {
        $this->call([
            ProfileSeeder::class,
            ModuleSeeder::class,
            PermissionSeeder::class,
            AccessSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
