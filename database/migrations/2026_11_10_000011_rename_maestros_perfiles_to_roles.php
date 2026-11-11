<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El módulo de maestros "Perfiles" pasa a llamarse "Roles".
     * Los accesos se guardan por module_id, así que se conservan.
     */
    public function up(): void
    {
        DB::table('modules')
            ->where('slug', 'maestros.perfiles')
            ->update([
                'name' => 'Roles',
                'slug' => 'maestros.roles',
                'icon' => 'fa-user-shield',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('modules')
            ->where('slug', 'maestros.roles')
            ->update([
                'name' => 'Perfiles',
                'slug' => 'maestros.perfiles',
                'icon' => 'fa-id-card',
                'updated_at' => now(),
            ]);
    }
};
