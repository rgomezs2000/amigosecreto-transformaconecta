<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo «Restablecer dinámica» y lo concede solo al
     * superadministrador.
     *
     * El acceso va por usuario (user_permission_module), nunca por perfil:
     * ese es el mecanismo que deja el módulo fuera del alcance de cualquier
     * otro administrador. El middleware `superadmin` lo vuelve a comprobar.
     *
     * En una instalación nueva la tabla está vacía: los seeders crean el
     * módulo y la concesión al usuario administrador.
     */
    public function up(): void
    {
        $administracion = Category::query()->where('slug', 'administracion')->first();

        if ($administracion === null) {
            return;
        }

        $module = Module::query()->updateOrCreate(
            ['slug' => 'restablecer-dinamica'],
            [
                'name' => 'Restablecer dinámica',
                'group' => 'Administración',
                'category_id' => $administracion->id,
                'icon' => 'fa-arrows-rotate',
                'order' => 12,
                'is_active' => true,
            ]
        );

        $view = Permission::query()->where('slug', 'view')->first();

        if ($view === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        // Ningún perfil lo recibe: solo el superadministrador, a mano.
        DB::table('profile_permission_module')->where('module_id', $module->id)->delete();

        $superAdmin = User::superAdmin();

        if ($superAdmin === null) {
            return;
        }

        UserPermissionModule::query()->updateOrCreate(
            [
                'user_id' => $superAdmin->id,
                'permission_id' => $view->id,
                'module_id' => $module->id,
            ],
            ['allowed' => true]
        );
    }

    public function down(): void
    {
        $module = Module::query()->where('slug', 'restablecer-dinamica')->first();

        if ($module === null) {
            return;
        }

        DB::table('module_permission')->where('module_id', $module->id)->delete();
        DB::table('profile_permission_module')->where('module_id', $module->id)->delete();
        DB::table('user_permission_module')->where('module_id', $module->id)->delete();

        $module->delete();
    }
};
