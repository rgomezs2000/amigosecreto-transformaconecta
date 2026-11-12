<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $administracion = Category::query()->where('slug', 'administracion')->first();

        if ($administracion === null) {
            return;
        }

        $module = Module::query()->updateOrCreate(
            ['slug' => 'moderacion'],
            [
                'name' => 'Moderación',
                'group' => 'Administración',
                'category_id' => $administracion->id,
                'icon' => 'fa-shield-halved',
                'order' => 11,
                'is_active' => true,
            ]
        );

        $permissions = Permission::query()->pluck('id', 'slug');
        $admin = Profile::query()->where('slug', 'administrador')->first();

        if ($admin !== null && isset($permissions['view'])) {
            ProfilePermissionModule::query()->firstOrCreate([
                'profile_id' => $admin->id,
                'permission_id' => $permissions['view'],
                'module_id' => $module->id,
            ]);
        }
    }

    public function down(): void
    {
        Module::query()->where('slug', 'moderacion')->delete();
    }
};
