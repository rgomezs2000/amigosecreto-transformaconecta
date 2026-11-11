<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo "Matriz de perfiles" a bases ya sembradas.
     * En una instalación nueva lo crean los seeders.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()
            || DB::table('permissions')->doesntExist()
            || DB::table('profiles')->doesntExist()) {
            return;
        }

        $now = now();

        $moduleId = DB::table('modules')->where('slug', 'maestros.matriz')->value('id');

        if ($moduleId === null) {
            $moduleId = DB::table('modules')->insertGetId([
                'name' => 'Matriz de perfiles',
                'slug' => 'maestros.matriz',
                'group' => 'Maestros',
                'icon' => 'fa-table-cells',
                'order' => 15,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['view', 'grant', 'revoke'])
            ->pluck('id', 'slug');

        if ($permissionIds->isEmpty()) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $moduleId,
                'permission_id' => $permissionId,
            ]);
        }

        $adminId = DB::table('profiles')->where('slug', 'administrador')->value('id');

        if ($adminId === null) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('profile_permission_module')->insertOrIgnore([
                'profile_id' => $adminId,
                'module_id' => $moduleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // No se elimina para no perder accesos.
    }
};
