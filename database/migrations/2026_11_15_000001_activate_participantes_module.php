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
     * Activa el módulo «Participantes» para la administración y lo coloca
     * antes de «Lista de deseos» dentro de la categoría Amigo secreto.
     *
     * El orden pedido es: Participantes, Usuarios asociados y después Lista
     * de deseos. Los dos módulos de monitoreo existían al final de la
     * categoría (130 y 140), detrás de los módulos del participante; aquí se
     * mueven delante sin tocar su URL ni sus permisos.
     *
     * En una instalación nueva este paso también deja el módulo y su permiso
     * «Ver» concedidos al perfil Administrador; los seeders vuelven a
     * asegurarlo desde cero.
     */
    public function up(): void
    {
        $category = Category::query()->where('slug', 'amigo-secreto')->first();

        if ($category === null) {
            return;
        }

        // Antes de «Lista de deseos» (orden 80), después de «Ver tu amigo» (70).
        $orders = [
            'participantes' => 75,
            'usuarios-asociados' => 76,
        ];

        foreach ($orders as $code => $order) {
            $module = Module::query()->where('code', $code)->first()
                ?? Module::query()->where('slug', $code)->first();

            if ($module === null) {
                continue;
            }

            $module->update([
                'code' => $code,
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'order' => $order,
                'is_active' => true,
            ]);
        }

        $module = Module::query()->where('code', 'participantes')->first();

        if ($module === null) {
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
     * Al revertir solo se devuelve el orden anterior. El acceso al perfil
     * Administrador no se retira: es una concesión inicial de los seeders.
     */
    public function down(): void
    {
        $orders = [
            'participantes' => 130,
            'usuarios-asociados' => 140,
        ];

        foreach ($orders as $code => $order) {
            Module::query()
                ->where('code', $code)
                ->update(['order' => $order]);
        }
    }
};
