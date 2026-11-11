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
            'maestros.perfiles' => ['view', 'create', 'edit', 'delete'],
            'maestros.permisos' => ['view', 'create', 'edit', 'delete', 'grant', 'revoke'],
            'maestros.modulos' => ['view', 'create', 'edit', 'delete'],
            'usuarios' => ['view', 'create', 'edit', 'delete', 'unlock', 'block'],
            'eventos' => ['view', 'edit', 'open', 'postpone', 'cancel', 'suspend', 'reset', 'reset_draw'],

            'amigos.asignar' => ['assign'],
            'amigos.ver' => ['view'],
            'amigos.obsequios' => ['gifts'],
            'amigos.deseos' => ['wishlist'],
            'amigos.intercambio' => ['clues'],
            'amigos.revelacion' => ['reveal'],
            'amigos.notificaciones' => ['notifications'],

            'amigo.participantes' => ['view'],
            'amigo.asociado' => ['view'],
            'amigo.deseos' => ['wishlist'],
            'amigo.obsequiados' => ['view'],
            'salir' => ['view'],
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
            'maestros.perfiles',
            'maestros.permisos',
            'maestros.modulos',
            'usuarios',
            'eventos',
            'amigo.participantes',
            'amigo.asociado',
            'amigo.deseos',
            'amigo.obsequiados',
            'salir',
        ];

        $participantModuleSlugs = [
            'amigos.asignar',
            'amigos.ver',
            'amigos.deseos',
            'amigos.intercambio',
            'amigos.revelacion',
            'amigos.notificaciones',
            'salir',
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
