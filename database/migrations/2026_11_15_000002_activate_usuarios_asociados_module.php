<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Activa el módulo «Usuarios asociados» para la administración.
     *
     * Es el monitoreo de las parejas del evento (quién regala a quién). Va
     * después de «Participantes» (75) y antes de «Lista de deseos» (80). El
     * módulo se abre cuando existe un evento y queda disponible en solo
     * lectura cuando el evento termina, para consultar los históricos.
     */
    public function up(): void
    {
        $category = Category::query()->where('slug', 'amigo-secreto')->first();

        if ($category === null) {
            return;
        }

        $module = Module::query()->where('code', 'usuarios-asociados')->first()
            ?? Module::query()->where('slug', 'usuarios-asociados')->first();

        if ($module === null) {
            $module = Module::query()->create([
                'code' => 'usuarios-asociados',
                'name' => 'Usuarios asociados',
                'slug' => 'usuarios-asociados',
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'icon' => 'fa-link',
                'order' => 76,
                'is_active' => true,
            ]);
        } else {
            $module->update([
                'code' => 'usuarios-asociados',
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'order' => 76,
                'is_active' => true,
            ]);
        }

        $view = Permission::query()->where('slug', 'view')->first();

        if ($view === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $admin = Profile::query()->where('slug', 'administrador')->first();

        if ($admin !== null) {
            ProfilePermissionModule::query()->firstOrCreate([
                'profile_id' => $admin->id,
                'permission_id' => $view->id,
                'module_id' => $module->id,
            ]);
        }
    }

    /**
     * Al revertir solo se devuelve el orden anterior; el acceso al perfil
     * Administrador lo siembra AccessSeeder.
     */
    public function down(): void
    {
        Module::query()
            ->where('code', 'usuarios-asociados')
            ->update(['order' => 140]);
    }
};
