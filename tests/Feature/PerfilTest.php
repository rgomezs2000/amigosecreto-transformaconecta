<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 899.2705,
                'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
            ]),
        ]);
    }

    private function crearModuloPerfiles(): Module
    {
        return Module::create([
            'name' => 'Perfiles',
            'slug' => 'perfiles',
            'group' => 'General',
            'icon' => 'fa-id-card',
            'order' => 5,
            'is_active' => true,
        ]);
    }

    private function userWithPerfilModule(string $password = 'Actual12345'): User
    {
        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $module = $this->crearModuloPerfiles();

        $permission = Permission::create([
            'name' => 'Ver',
            'slug' => 'view',
        ]);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        return User::factory()->create([
            'name' => 'Ana Pérez',
            'username' => 'ana',
            'email' => 'ana@example.com',
            'password' => $password,
            'profile_id' => $profile->id,
        ]);
    }

    public function test_el_perfil_muestra_datos_rol_modulos_y_permisos(): void
    {
        $user = $this->userWithPerfilModule();

        $this->actingAs($user)
            ->get('/perfiles')
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('ana@example.com')
            ->assertSee('ana')
            ->assertSee('Participante')
            ->assertSee('Perfiles')
            ->assertSee('Ver')
            ->assertSee('Modificar perfil')
            ->assertSee('Cambiar contraseña');
    }

    public function test_el_perfil_exige_el_modulo(): void
    {
        $this->crearModuloPerfiles();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/perfiles')
            ->assertForbidden();
    }

    public function test_consultar_login_existente_y_disponible(): void
    {
        $user = $this->userWithPerfilModule();

        User::factory()->create(['username' => 'ocupado']);

        $this->actingAs($user)
            ->getJson('/perfil/consultar-login?login=ocupado')
            ->assertOk()
            ->assertJson(['available' => false, 'message' => 'Usuario no disponible.']);

        $this->actingAs($user)
            ->getJson('/perfil/consultar-login?login=libre')
            ->assertOk()
            ->assertJson(['available' => true]);

        $this->actingAs($user)
            ->getJson('/perfil/consultar-login?login=ana')
            ->assertOk()
            ->assertJson(['available' => true]);
    }

    public function test_cambiar_perfil_actualiza_los_datos(): void
    {
        $user = $this->userWithPerfilModule();

        $this->actingAs($user)
            ->postJson('/perfil', [
                'name' => 'Ana María',
                'email' => 'ana.maria@example.com',
                'username' => 'anamaria',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $user->refresh();

        $this->assertSame('Ana María', $user->name);
        $this->assertSame('ana.maria@example.com', $user->email);
        $this->assertSame('anamaria', $user->username);
    }

    public function test_cambiar_perfil_valida_campos_obligatorios(): void
    {
        $user = $this->userWithPerfilModule();

        $this->actingAs($user)
            ->postJson('/perfil', ['name' => '', 'email' => '', 'username' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'username']);
    }

    public function test_cambiar_perfil_rechaza_login_ocupado(): void
    {
        $user = $this->userWithPerfilModule();

        User::factory()->create(['username' => 'ocupado']);

        $this->actingAs($user)
            ->postJson('/perfil', [
                'name' => 'Ana Pérez',
                'email' => 'ana@example.com',
                'username' => 'ocupado',
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Usuario no disponible.']);
    }

    public function test_cambiar_clave_correcta(): void
    {
        $user = $this->userWithPerfilModule('Actual12345');

        $this->actingAs($user)
            ->postJson('/perfil/clave', [
                'current_password' => 'Actual12345',
                'password' => 'NuevaClave123',
                'password_confirmation' => 'NuevaClave123',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $user->refresh();

        $this->assertTrue(Hash::check('NuevaClave123', $user->password));
        $this->assertSame(0, $user->failed_login_attempts);
    }

    public function test_cambiar_clave_actual_incorrecta_suma_intento(): void
    {
        $user = $this->userWithPerfilModule('Actual12345');

        $this->actingAs($user)
            ->postJson('/perfil/clave', [
                'current_password' => 'Incorrecta123',
                'password' => 'NuevaClave123',
                'password_confirmation' => 'NuevaClave123',
            ])
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        $user->refresh();

        $this->assertSame(1, $user->failed_login_attempts);
        $this->assertFalse($user->isLocked());
    }

    public function test_cambiar_clave_bloquea_y_cierra_sesion(): void
    {
        $user = $this->userWithPerfilModule('Actual12345');
        $user->forceFill(['failed_login_attempts' => 2])->save();

        $this->actingAs($user)
            ->postJson('/perfil/clave', [
                'current_password' => 'Incorrecta123',
                'password' => 'NuevaClave123',
                'password_confirmation' => 'NuevaClave123',
            ])
            ->assertStatus(423)
            ->assertJson(['locked' => true]);

        $this->assertTrue($user->fresh()->isLocked());
        $this->assertGuest();
    }

    public function test_cambiar_clave_valida_longitud_y_confirmacion(): void
    {
        $user = $this->userWithPerfilModule('Actual12345');

        $this->actingAs($user)
            ->postJson('/perfil/clave', [
                'current_password' => 'Actual12345',
                'password' => 'corta',
                'password_confirmation' => 'corta',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->actingAs($user)
            ->postJson('/perfil/clave', [
                'current_password' => 'Actual12345',
                'password' => 'NuevaClave123',
                'password_confirmation' => 'OtraClave123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
