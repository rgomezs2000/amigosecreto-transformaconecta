<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo "Login" en la categoría Sesión, con ruta /login,
     * igual que Salir con /logout.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()
            || DB::table('categories')->doesntExist()
            || DB::table('permissions')->doesntExist()
            || DB::table('profiles')->doesntExist()) {
            return;
        }

        $now = now();

        $categoryId = DB::table('categories')->where('name', 'Sesión')->value('id');

        if ($categoryId === null) {
            return;
        }

        $moduleId = DB::table('modules')->where('slug', 'login')->value('id');

        if ($moduleId === null) {
            $moduleId = DB::table('modules')->insertGetId([
                'name' => 'Login',
                'slug' => 'login',
                'group' => 'Sesión',
                'category_id' => $categoryId,
                'icon' => 'fa-right-to-bracket',
                'order' => 998,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $viewId = DB::table('permissions')->where('slug', 'view')->value('id');

        if ($viewId === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $moduleId,
            'permission_id' => $viewId,
        ]);

        foreach (['administrador', 'usuario'] as $slug) {
            $profileId = DB::table('profiles')->where('slug', $slug)->value('id');

            if ($profileId !== null) {
                DB::table('profile_permission_module')->insertOrIgnore([
                    'profile_id' => $profileId,
                    'module_id' => $moduleId,
                    'permission_id' => $viewId,
                ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
