<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Support\Str;

trait GrantsModules
{
    /**
     * Crea un usuario con un perfil que tiene acceso a los módulos indicados.
     *
     * @param  array<int, string>  $moduleSlugs
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(string $name, UserRole $role, array $moduleSlugs = [], array $attributes = []): User
    {
        $profile = Profile::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $permission = Permission::query()->firstOrCreate(['slug' => 'view'], ['name' => 'Ver']);

        foreach (array_unique($moduleSlugs) as $moduleSlug) {
            $module = $this->ensureModule($moduleSlug);

            ProfilePermissionModule::firstOrCreate([
                'profile_id' => $profile->id,
                'permission_id' => $permission->id,
                'module_id' => $module->id,
            ]);
        }

        return User::factory()->create(array_merge([
            'name' => $name,
            'profile_id' => $profile->id,
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Asegura que el módulo exista (algunos se crean con seeders).
     */
    protected function ensureModule(string $slug): Module
    {
        return Module::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => Str::headline($slug),
                'group' => 'General',
                'icon' => 'fa-circle',
                'order' => 0,
                'is_active' => true,
            ]
        );
    }
}
