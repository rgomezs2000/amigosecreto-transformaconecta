<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionModule;
use Illuminate\Database\Seeder;

/**
 * Concede al superadministrador los módulos que no van por perfil.
 *
 * Son módulos exclusivos de la cuenta intocable: si se concedieran al perfil
 * Administrador, cualquier otro administrador los vería.
 */
class SuperAdminAccessSeeder extends Seeder
{
    /**
     * Módulos que solo tiene el superadministrador.
     *
     * @var list<string>
     */
    private const MODULES = [
        'restablecer-dinamica',
    ];

    public function run(): void
    {
        $user = User::superAdmin();

        if ($user === null) {
            return;
        }

        $permission = Permission::query()->where('slug', 'view')->first();

        if ($permission === null) {
            return;
        }

        $modules = Module::query()->whereIn('code', self::MODULES)->get();

        foreach ($modules as $module) {
            UserPermissionModule::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'permission_id' => $permission->id,
                    'module_id' => $module->id,
                ],
                ['allowed' => true]
            );
        }
    }
}
