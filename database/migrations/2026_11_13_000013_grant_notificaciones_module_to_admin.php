<?php

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * La campana de notificaciones también debe estar disponible para el
     * administrador: recibe los avisos de reportes y necesita verlos y
     * marcarlos como leídos igual que el resto de usuarios.
     */
    public function up(): void
    {
        $admin = Profile::query()->where('slug', 'administrador')->first();
        $module = Module::query()->where('slug', 'notificaciones')->first();

        if ($admin === null || $module === null) {
            return;
        }

        $permission = Permission::query()->where('slug', 'notifications')->first()
            ?? Permission::query()->where('slug', 'view')->first();

        if ($permission === null) {
            return;
        }

        ProfilePermissionModule::query()->firstOrCreate([
            'profile_id' => $admin->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);
    }

    public function down(): void
    {
        $admin = Profile::query()->where('slug', 'administrador')->first();
        $module = Module::query()->where('slug', 'notificaciones')->first();

        if ($admin === null || $module === null) {
            return;
        }

        ProfilePermissionModule::query()
            ->where('profile_id', $admin->id)
            ->where('module_id', $module->id)
            ->delete();
    }
};
