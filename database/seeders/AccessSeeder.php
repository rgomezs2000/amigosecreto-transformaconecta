<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessSeeder extends Seeder
{
    public function run(): void
    {
        // Solo primera instalación: si ya hay accesos, no se tocan.
        if (DB::table('profile_permission_module')->exists()) {
            return;
        }

        $moduleIds = Module::pluck('id', 'slug');
        $permissionIds = Permission::pluck('id', 'slug');

        $map = [
            '' => ['view'],
            'perfiles' => ['view'],
            'roles' => ['view', 'create', 'edit', 'delete'],
            'matriz' => ['view', 'grant', 'revoke'],
            'permisos' => ['view', 'create', 'edit', 'delete', 'grant', 'revoke'],
            'modulos' => ['view', 'create', 'edit', 'delete'],
            'categorias' => ['view', 'create', 'edit', 'delete'],
            'usuarios' => ['view', 'create', 'edit', 'delete', 'unlock', 'block'],
            'eventos' => ['view', 'edit', 'open', 'postpone', 'cancel', 'suspend', 'reset', 'reset_draw'],
            'control-notificaciones' => ['view', 'edit'],
            'moderacion' => ['view'],
            // Sin perfil: el SuperAdminAccessSeeder lo concede al usuario.
            'restablecer-dinamica' => ['view'],
            'chat' => ['view'],

            'asignar-amigo' => ['assign'],
            'ver-tu-amigo' => ['view'],
            'obsequios' => ['gifts'],
            'lista-de-deseos' => ['wishlist'],
            'intercambio-de-pista' => ['clues'],
            'gran-revelacion' => ['reveal'],
            'notificaciones' => ['notifications'],

            'participantes' => ['view'],
            'usuarios-asociados' => ['view'],
            'usuarios-obsequiados' => ['view'],
            'login' => ['view'],
            'logout' => ['view'],
        ];

        foreach ($map as $moduleSlug => $permissionSlugs) {
            foreach ($permissionSlugs as $permissionSlug) {
                DB::table('module_permission')->updateOrInsert([
                    'module_id' => $moduleIds[$moduleSlug],
                    'permission_id' => $permissionIds[$permissionSlug],
                ]);
            }
        }

        $admin = Profile::where('slug', 'administrador')->firstOrFail();
        $usuario = Profile::where('slug', 'usuario')->firstOrFail();

        $adminModuleSlugs = [
            '',
            'perfiles',
            'roles',
            'matriz',
            'permisos',
            'modulos',
            'categorias',
            'usuarios',
            'eventos',
            'control-notificaciones',
            'moderacion',
            'notificaciones',
            'chat',
            'participantes',
            'usuarios-asociados',
            'lista-de-deseos',
            'usuarios-obsequiados',
            'login',
            'logout',
        ];

        $participantModuleSlugs = [
            '',
            'perfiles',
            'chat',
            'asignar-amigo',
            'ver-tu-amigo',
            'lista-de-deseos',
            'intercambio-de-pista',
            'gran-revelacion',
            'notificaciones',
            'login',
            'logout',
        ];

        $adminModuleIds = Module::whereIn('slug', $adminModuleSlugs)->pluck('id');
        $participantModuleIds = Module::whereIn('slug', $participantModuleSlugs)->pluck('id');

        $pairs = DB::table('module_permission')->get();

        $adminHasGrants = DB::table('profile_permission_module')
            ->where('profile_id', $admin->id)
            ->exists();

        $usuarioHasGrants = DB::table('profile_permission_module')
            ->where('profile_id', $usuario->id)
            ->exists();

        // Solo se siembran los accesos iniciales. Si el perfil ya tiene
        // concesiones, la base de datos manda y el seeder no las toca.
        if (! $adminHasGrants) {
            foreach ($pairs->whereIn('module_id', $adminModuleIds) as $pair) {
                DB::table('profile_permission_module')->insertOrIgnore([
                    'profile_id' => $admin->id,
                    'permission_id' => $pair->permission_id,
                    'module_id' => $pair->module_id,
                ]);
            }
        }

        if (! $usuarioHasGrants) {
            foreach ($pairs->whereIn('module_id', $participantModuleIds) as $pair) {
                DB::table('profile_permission_module')->insertOrIgnore([
                    'profile_id' => $usuario->id,
                    'permission_id' => $pair->permission_id,
                    'module_id' => $pair->module_id,
                ]);
            }
        }

        // Los usuarios que ya existían sin perfil pasan a "usuario".
        User::whereNull('profile_id')->update(['profile_id' => $usuario->id]);
    }
}
