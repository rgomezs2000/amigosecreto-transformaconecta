<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categoría «Ayuda» con sus cuatro módulos.
     *
     * Los tres primeros (Acerca de, Ayuda y FAQ) quedan activos y disponibles
     * para los perfiles Administrador y Usuario. «Contacto y soporte» se crea
     * inactivo: su lógica se implementará más adelante y no debe aparecer en
     * el menú mientras no tenga pantalla.
     *
     * La categoría se coloca justo antes de «Sesión». En una instalación nueva
     * la base está vacía y este paso no hace nada: los seeders crean lo mismo.
     */
    public function up(): void
    {
        if (! Schema::hasTable('categories')
            || ! Schema::hasTable('modules')
            || DB::table('categories')->doesntExist()
            || DB::table('modules')->doesntExist()) {
            return;
        }

        $this->crearCategoria();
        $this->crearModulos();
        $this->concederAccesos();
    }

    /**
     * Crea la categoría y la coloca antes de «Sesión» sin desordenar el resto.
     */
    private function crearCategoria(): void
    {
        $ayuda = Category::query()->where('name', 'Ayuda')->first();

        if ($ayuda === null) {
            // Abre un espacio en el orden actual: Notificaciones y Sesión
            // pasan un puesto adelante y Ayuda ocupa el puesto 8.
            DB::table('categories')
                ->where('sort_order', '>=', 8)
                ->increment('sort_order');

            Category::query()->create([
                'name' => 'Ayuda',
                'slug' => 'ayuda',
                'sort_order' => 8,
                'is_active' => true,
            ]);

            return;
        }

        $ayuda->update([
            'slug' => 'ayuda',
            'sort_order' => 8,
            'is_active' => true,
        ]);
    }

    /**
     * Crea los cuatro módulos del centro de ayuda.
     */
    private function crearModulos(): void
    {
        $category = Category::query()->where('slug', 'ayuda')->first();

        if ($category === null) {
            return;
        }

        $modulos = [
            [
                'code' => 'acerca-de',
                'name' => 'Acerca de',
                'slug' => 'acerca-de',
                'icon' => 'fa-circle-info',
                'order' => 10,
                'is_active' => true,
            ],
            [
                'code' => 'ayuda',
                'name' => 'Ayuda',
                // La URL es /ayuda/guia: evita /ayuda/ayuda.
                'slug' => 'guia',
                'icon' => 'fa-book-open',
                'order' => 20,
                'is_active' => true,
            ],
            [
                'code' => 'faq',
                'name' => 'FAQ',
                'slug' => 'faq',
                'icon' => 'fa-circle-question',
                'order' => 30,
                'is_active' => true,
            ],
            [
                'code' => 'contacto-soporte',
                'name' => 'Contacto y soporte',
                'slug' => 'contacto-soporte',
                'icon' => 'fa-headset',
                'order' => 40,
                // Sin lógica por ahora: no se muestra ni se puede abrir.
                'is_active' => false,
            ],
        ];

        foreach ($modulos as $modulo) {
            Module::query()->firstOrCreate(
                ['code' => $modulo['code']],
                $modulo + [
                    'group' => 'Ayuda',
                    'category_id' => $category->id,
                ]
            );
        }
    }

    /**
     * Da el permiso de ver a los tres módulos activos para los dos perfiles
     * base del sistema.
     */
    private function concederAccesos(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('module_permission')
            || ! Schema::hasTable('profile_permission_module')) {
            return;
        }

        $view = Permission::query()->where('slug', 'view')->first();

        if ($view === null) {
            return;
        }

        $modules = Module::query()
            ->whereIn('code', ['acerca-de', 'ayuda', 'faq'])
            ->get();

        $profiles = Profile::query()
            ->whereIn('slug', ['administrador', 'usuario'])
            ->get();

        foreach ($modules as $module) {
            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $module->id,
                'permission_id' => $view->id,
            ]);

            foreach ($profiles as $profile) {
                ProfilePermissionModule::query()->firstOrCreate([
                    'profile_id' => $profile->id,
                    'permission_id' => $view->id,
                    'module_id' => $module->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $modules = Module::query()
            ->whereIn('code', ['acerca-de', 'ayuda', 'faq', 'contacto-soporte'])
            ->get();

        foreach ($modules as $module) {
            DB::table('module_permission')->where('module_id', $module->id)->delete();
            DB::table('profile_permission_module')->where('module_id', $module->id)->delete();
            DB::table('user_permission_module')->where('module_id', $module->id)->delete();

            $module->delete();
        }

        $category = Category::query()->where('name', 'Ayuda')->first();

        if ($category !== null && ! $category->modules()->exists()) {
            $category->delete();
        }
    }
};
