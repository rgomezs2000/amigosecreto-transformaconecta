<?php

use App\Enums\UserRole;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use App\Models\UserPermissionModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Contacto y soporte» es un canal para participantes: el perfil
     * Administrador no debe verlo ni entrar.
     *
     * El módulo sigue activo y visible en el menú del perfil Usuario. Al
     * Administrador se le quita la concesión del perfil y cualquier permiso
     * individual que hubiera recibido.
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

        $admin = Profile::query()->where('slug', 'administrador')->first();

        if ($admin !== null && Schema::hasTable('profile_permission_module')) {
            ProfilePermissionModule::query()
                ->where('profile_id', $admin->id)
                ->where('module_id', $module->id)
                ->delete();
        }

        if (! Schema::hasTable('user_permission_module') || ! Schema::hasTable('users')) {
            return;
        }

        $adminUserIds = User::query()
            ->where(function ($query) use ($admin) {
                $query->where('role', UserRole::Admin->value);

                if ($admin !== null) {
                    $query->orWhere('profile_id', $admin->id);
                }
            })
            ->pluck('id');

        if ($adminUserIds->isNotEmpty()) {
            UserPermissionModule::query()
                ->whereIn('user_id', $adminUserIds)
                ->where('module_id', $module->id)
                ->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('profile_permission_module')) {
            return;
        }

        $module = Module::query()->where('code', 'contacto-soporte')->first();
        $admin = Profile::query()->where('slug', 'administrador')->first();
        $view = Permission::query()->where('slug', 'view')->first();

        if ($module === null || $admin === null || $view === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        ProfilePermissionModule::query()->firstOrCreate([
            'profile_id' => $admin->id,
            'permission_id' => $view->id,
            'module_id' => $module->id,
        ]);
    }
};
