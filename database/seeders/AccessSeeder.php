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
            'amigos.notificaciones' => ['notifications'],
            'amigo.participantes' => ['view'],
            'amigo.asociado' => ['view'],
            'amigo.deseos' => ['wishlist'],
            'amigo.obsequiados' => ['view'],
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

        $pairs = DB::table('module_permission')->get();

        // El administrador recibe todos los permisos de todos los módulos.
        foreach ($pairs as $pair) {
            DB::table('profile_permission_module')->updateOrInsert([
                'profile_id' => $admin->id,
                'permission_id' => $pair->permission_id,
                'module_id' => $pair->module_id,
            ]);
        }

        // El perfil usuario recibe los módulos de participación.
        $participantModuleIds = Module::where('group', 'Amigos secreto')->pluck('id');

        foreach ($pairs->whereIn('module_id', $participantModuleIds) as $pair) {
            DB::table('profile_permission_module')->updateOrInsert([
                'profile_id' => $usuario->id,
                'permission_id' => $pair->permission_id,
                'module_id' => $pair->module_id,
            ]);
        }

        // Los usuarios que ya existían sin perfil pasan a "usuario".
        User::whereNull('profile_id')->update(['profile_id' => $usuario->id]);
    }
}
