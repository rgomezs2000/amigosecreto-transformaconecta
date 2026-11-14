<?php

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
     * Deja visible «Contacto y soporte» aunque todavía no tenga lógica.
     *
     * El módulo se creó inactivo a propósito para no mostrar una pantalla sin
     * terminar. Ahora debe aparecer en el menú: al abrirlo muestra el marcador
     * de posición («Este módulo está en construcción») hasta que se conecte su
     * controlador y su vista.
     */
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $module = Module::query()->where('code', 'contacto-soporte')->first();

        if ($module === null) {
            return;
        }

        $module->update(['is_active' => true]);

        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('module_permission')
            || ! Schema::hasTable('profile_permission_module')) {
            return;
        }

        $view = Permission::query()->where('slug', 'view')->first();

        if ($view === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $profiles = Profile::query()
            ->whereIn('slug', ['administrador', 'usuario'])
            ->get();

        foreach ($profiles as $profile) {
            ProfilePermissionModule::query()->firstOrCreate([
                'profile_id' => $profile->id,
                'permission_id' => $view->id,
                'module_id' => $module->id,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $module = Module::query()->where('code', 'contacto-soporte')->first();

        if ($module === null) {
            return;
        }

        DB::table('profile_permission_module')->where('module_id', $module->id)->delete();
        DB::table('user_permission_module')->where('module_id', $module->id)->delete();

        $module->update(['is_active' => false]);
    }
};
