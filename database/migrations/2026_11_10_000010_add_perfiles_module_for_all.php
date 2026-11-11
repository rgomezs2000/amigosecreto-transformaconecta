<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo compartido "Perfiles" a bases ya sembradas.
     *
     * En una instalación nueva las tablas están vacías y este paso no hace
     * nada: los seeders crean el módulo y sus accesos. En una base existente
     * agrega el módulo, el permiso de ver y las concesiones para los dos
     * perfiles.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()
            || DB::table('permissions')->doesntExist()
            || DB::table('profiles')->doesntExist()) {
            return;
        }

        $now = now();

        $moduleId = DB::table('modules')->where('slug', 'perfiles')->value('id');

        if ($moduleId === null) {
            $moduleId = DB::table('modules')->insertGetId([
                'name' => 'Perfiles',
                'slug' => 'perfiles',
                'group' => 'General',
                'icon' => 'fa-id-card',
                'order' => 5,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionId = DB::table('permissions')->where('slug', 'view')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $moduleId,
            'permission_id' => $permissionId,
        ]);

        foreach (['administrador', 'usuario'] as $profileSlug) {
            $profileId = DB::table('profiles')->where('slug', $profileSlug)->value('id');

            if ($profileId === null) {
                continue;
            }

            DB::table('profile_permission_module')->insertOrIgnore([
                'profile_id' => $profileId,
                'module_id' => $moduleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // No se elimina el módulo para no perder accesos ya usados.
    }
};
