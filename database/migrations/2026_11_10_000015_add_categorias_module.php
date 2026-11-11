<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo "Categorías" a bases ya sembradas.
     * En una instalación nueva lo crean los seeders.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()
            || DB::table('permissions')->doesntExist()
            || DB::table('profiles')->doesntExist()
            || DB::table('categories')->doesntExist()) {
            return;
        }

        $now = now();

        $categoryId = DB::table('categories')->where('name', 'Maestros')->value('id');

        if ($categoryId === null) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => 'Maestros',
                'slug' => 'maestros',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $moduleId = DB::table('modules')->where('slug', 'maestros.categorias')->value('id');

        if ($moduleId === null) {
            $moduleId = DB::table('modules')->insertGetId([
                'name' => 'Categorías',
                'slug' => 'maestros.categorias',
                'group' => 'Maestros',
                'category_id' => $categoryId,
                'icon' => 'fa-tags',
                'order' => 25,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('modules')->where('id', $moduleId)->update([
                'name' => 'Categorías',
                'category_id' => $categoryId,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['view', 'create', 'edit', 'delete'])
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
