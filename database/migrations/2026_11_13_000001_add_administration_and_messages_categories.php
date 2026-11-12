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
    public function up(): void
    {
        $general = Category::query()->where('name', 'General')->first();
        $amigo = Category::query()->where('slug', 'amigo-secreto')->first();

        $administracion = Category::query()->firstOrCreate(
            ['slug' => 'administracion'],
            ['name' => 'Administración', 'sort_order' => 6, 'is_active' => true]
        );

        $mensajes = Category::query()->firstOrCreate(
            ['slug' => 'mensajes'],
            ['name' => 'Mensajes', 'sort_order' => 7, 'is_active' => true]
        );

        $notificacionesCategory = Category::query()->where('slug', 'notificaciones')->first();

        if ($notificacionesCategory !== null) {
            $notificacionesCategory->update(['sort_order' => 8]);
        }

        $sesion = Category::query()->where('name', 'Sesión')->first();

        if ($sesion !== null) {
            $sesion->update(['sort_order' => 9]);
        }

        $control = Module::query()->updateOrCreate(
            ['slug' => 'control-notificaciones'],
            [
                'name' => 'Control de Notificaciones',
                'group' => 'Administración',
                'category_id' => $administracion->id,
                'icon' => 'fa-bell-slash',
                'order' => 10,
                'is_active' => true,
            ]
        );

        $chat = Module::query()->updateOrCreate(
            ['slug' => 'chat'],
            [
                'name' => 'Chat',
                'group' => 'Mensajes',
                'category_id' => $mensajes->id,
                'icon' => 'fa-comments',
                'order' => 10,
                'is_active' => true,
            ]
        );

        if ($amigo !== null) {
            Module::query()->updateOrCreate(
                ['slug' => 'pistas'],
                [
                    'name' => 'Pistas',
                    'group' => 'Amigo secreto',
                    'category_id' => $amigo->id,
                    'icon' => 'fa-lightbulb',
                    'order' => 97,
                    'is_active' => true,
                ]
            );

            Module::query()->where('slug', 'asignar-amigo')->update(['name' => 'Sorteo ciego']);

            $revelacion = Module::query()->where('slug', 'revelacion')->first();
            $gran = Module::query()->where('slug', 'gran-revelacion')->first();

            if ($revelacion !== null && $gran === null) {
                $revelacion->update(['name' => 'Gran revelación']);
            } elseif ($revelacion !== null && $gran !== null) {
                $this->mergeModule($revelacion, $gran);
            }
        }

        $permissions = Permission::query()->pluck('id', 'slug');
        $admin = Profile::query()->where('slug', 'administrador')->first();
        $usuario = Profile::query()->where('slug', 'usuario')->first();

        if ($admin !== null) {
            foreach ([$control, $chat] as $module) {
                foreach (['view', 'edit'] as $slug) {
                    if (isset($permissions[$slug])) {
                        ProfilePermissionModule::firstOrCreate([
                            'profile_id' => $admin->id,
                            'permission_id' => $permissions[$slug],
                            'module_id' => $module->id,
                        ]);
                    }
                }
            }
        }

        if ($usuario !== null && isset($permissions['view'])) {
            ProfilePermissionModule::firstOrCreate([
                'profile_id' => $usuario->id,
                'permission_id' => $permissions['view'],
                'module_id' => $chat->id,
            ]);
        }
    }

    private function mergeModule(Module $from, Module $to): void
    {
        $rows = DB::table('module_permission')->where('module_id', $from->id)->get();

        foreach ($rows as $row) {
            DB::table('module_permission')->updateOrInsert([
                'module_id' => $to->id,
                'permission_id' => $row->permission_id,
            ]);
        }

        $grants = DB::table('profile_permission_module')->where('module_id', $from->id)->get();

        foreach ($grants as $grant) {
            DB::table('profile_permission_module')->updateOrInsert([
                'profile_id' => $grant->profile_id,
                'permission_id' => $grant->permission_id,
                'module_id' => $to->id,
            ]);
        }

        $from->delete();
    }

    public function down(): void
    {
        Module::query()->whereIn('slug', ['control-notificaciones', 'chat', 'pistas'])->delete();

        Category::query()->whereIn('slug', ['administracion', 'mensajes'])->delete();
    }
};
