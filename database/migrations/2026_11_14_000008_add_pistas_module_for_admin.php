<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deja el módulo «Pistas» al final de Amigo secreto y solo para la
     * administración.
     *
     * El contexto es monitorear las pistas que cada participante emite a su
     * amigo secreto asignado (y que recibe el amigo secreto emisor). Todavía no
     * tiene contenido: abre el marcador de posición, como cualquier módulo que
     * aún no se desarrolla.
     *
     * En una instalación nueva la tabla está vacía y este paso no hace nada:
     * los seeders crean el módulo y la concesión al perfil Administrador.
     */
    public function up(): void
    {
        $category = Category::query()->where('slug', 'amigo-secreto')->first();

        if ($category === null) {
            return;
        }

        // El módulo ya existía en el catálogo: se busca por su código interno
        // y, si no lo tiene, por el slug histórico.
        $module = Module::query()->where('code', 'pistas')->first()
            ?? Module::query()->where('slug', 'pistas')->first();

        if ($module === null) {
            $module = Module::query()->create([
                'code' => 'pistas',
                'name' => 'Pistas',
                'slug' => 'pistas',
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'icon' => 'fa-lightbulb',
                // Al final de la categoría, detrás de los módulos de gestión.
                'order' => 160,
                'is_active' => true,
            ]);
        } else {
            $module->update([
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'icon' => 'fa-lightbulb',
                'order' => 160,
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

        // Nadie más lo ve: es monitoreo de la administración.
        DB::table('profile_permission_module')
            ->where('module_id', $module->id)
            ->when($admin !== null, fn ($query) => $query->where('profile_id', '!=', $admin->id))
            ->delete();

        $adminIds = $admin !== null
            ? User::query()->where('profile_id', $admin->id)->pluck('id')->all()
            : [];

        DB::table('user_permission_module')
            ->where('module_id', $module->id)
            ->whereNotIn('user_id', $adminIds !== [] ? $adminIds : [0])
            ->delete();
    }

    /**
     * El módulo ya existía en el catálogo: al revertir solo se le quitan los
     * accesos y se devuelve su orden anterior.
     */
    public function down(): void
    {
        $module = Module::query()->where('code', 'pistas')->first();

        if ($module === null) {
            return;
        }

        DB::table('profile_permission_module')->where('module_id', $module->id)->delete();
        DB::table('user_permission_module')->where('module_id', $module->id)->delete();
        DB::table('module_permission')->where('module_id', $module->id)->delete();

        $module->update(['order' => 120]);
    }
};
