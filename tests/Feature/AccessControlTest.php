<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_conceder_y_revocar_queda_en_bd_y_en_auditoria(): void
    {
        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Usuarios',
            'slug' => 'usuarios',
            'group' => 'Usuarios',
            'is_active' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Ver',
            'slug' => 'view',
        ]);

        $this->artisan('access:grant', [
            'profile' => $profile->slug,
            'module' => $module->slug,
            'permission' => $permission->slug,
        ])->assertSuccessful();

        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.granted',
        ]);

        $this->artisan('access:show', ['profile' => $profile->slug])
            ->expectsOutputToContain('Usuarios')
            ->assertSuccessful();

        $this->artisan('access:revoke', [
            'profile' => $profile->slug,
            'module' => $module->slug,
            'permission' => $permission->slug,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.revoked',
        ]);
    }
}
