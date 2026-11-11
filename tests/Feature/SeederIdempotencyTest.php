<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_seeders_no_sobrescriben_datos_existentes(): void
    {
        $this->seed();

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $admin->forceFill([
            'name' => 'Administrador Personalizado',
            'password' => 'OtraClave123',
        ])->save();

        $module = Module::query()->where('slug', 'usuarios')->firstOrFail();
        $module->update(['name' => 'Usuarios Personalizados']);

        $this->seed();

        $this->assertSame('Administrador Personalizado', $admin->fresh()->name);
        $this->assertTrue(Hash::check('OtraClave123', $admin->fresh()->password));
        $this->assertSame('Usuarios Personalizados', $module->fresh()->name);
    }

    public function test_el_modulo_perfiles_queda_para_administrador_y_usuario(): void
    {
        $this->seed();

        $module = Module::query()->where('slug', 'perfiles')->firstOrFail();
        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        foreach (['administrador', 'usuario'] as $slug) {
            $profile = Profile::query()->where('slug', $slug)->firstOrFail();

            $this->assertDatabaseHas('profile_permission_module', [
                'profile_id' => $profile->id,
                'module_id' => $module->id,
                'permission_id' => $permission->id,
            ]);
        }
    }
}
