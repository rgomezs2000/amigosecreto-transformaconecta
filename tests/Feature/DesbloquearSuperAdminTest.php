<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DesbloquearSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_PASSWORD = 'Original123';

    private const CURRENT_PASSWORD = 'OtraClave123';

    /**
     * Se configura en cada prueba y no en setUp(): el refresco de la base
     * recarga la configuración después de setUp.
     */
    private function configureSuperAdmin(): void
    {
        config()->set('amigosecreto.admin.username', 'admin');
        config()->set('amigosecreto.admin.password', self::ORIGINAL_PASSWORD);
        config()->set('amigosecreto.admin.force_password_change', false);
    }

    private function superAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'admin',
            'name' => 'Administrador',
            'role' => UserRole::Admin,
            'is_active' => true,
            'locked_until' => null,
            'failed_login_attempts' => 0,
            'must_change_password' => false,
            'password' => self::CURRENT_PASSWORD,
        ], $attributes));
    }

    public function test_no_cambia_nada_si_el_superadministrador_no_esta_bloqueado(): void
    {
        $this->configureSuperAdmin();

        $user = $this->superAdmin();
        $hash = $user->password;

        $this->artisan('superadmin:desbloquear')
            ->expectsOutputToContain('no está bloqueado')
            ->assertSuccessful();

        $user->refresh();

        $this->assertSame($hash, $user->password);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->locked_until);
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->password));
    }

    public function test_elimina_los_intentos_fallidos_aunque_no_este_bloqueado(): void
    {
        $this->configureSuperAdmin();

        $user = $this->superAdmin([
            'is_active' => true,
            'locked_until' => null,
            'failed_login_attempts' => 2,
        ]);

        $hash = $user->password;

        $this->artisan('superadmin:desbloquear')
            ->expectsOutputToContain('Se eliminaron 2 intento')
            ->assertSuccessful();

        $user->refresh();

        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertSame($hash, $user->password);
    }

    public function test_desbloquea_una_cuenta_bloqueada_y_restablece_la_contrasena_original(): void
    {
        $this->configureSuperAdmin();

        $user = $this->superAdmin([
            'is_active' => false,
            'locked_until' => now(),
            'failed_login_attempts' => 3,
        ]);

        $this->artisan('superadmin:desbloquear')
            ->expectsOutputToContain('Cuenta desbloqueada')
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->locked_until);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $user->password));
    }

    public function test_desbloquea_un_bloqueo_por_intentos_fallidos(): void
    {
        $this->configureSuperAdmin();

        $user = $this->superAdmin([
            'is_active' => true,
            'locked_until' => now(),
            'failed_login_attempts' => 3,
        ]);

        $this->artisan('superadmin:desbloquear')->assertSuccessful();

        $user->refresh();

        $this->assertNull($user->locked_until);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $user->password));
    }

    public function test_acepta_una_contrasena_indicada(): void
    {
        $this->configureSuperAdmin();

        $user = $this->superAdmin([
            'is_active' => false,
            'locked_until' => now(),
        ]);

        $this->artisan('superadmin:desbloquear', ['--password' => 'NuevaClave987'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->locked_until);
        $this->assertTrue(Hash::check('NuevaClave987', $user->password));
    }

    public function test_no_toca_a_los_demas_usuarios(): void
    {
        $this->configureSuperAdmin();

        $admin = $this->superAdmin([
            'is_active' => false,
            'locked_until' => now(),
        ]);

        $otro = User::factory()->create([
            'username' => 'otro',
            'role' => UserRole::Participant,
            'is_active' => false,
            'locked_until' => now(),
            'failed_login_attempts' => 2,
            'password' => 'ClaveOtro123',
        ]);

        $hashOtro = $otro->password;

        $this->artisan('superadmin:desbloquear')->assertSuccessful();

        $otro->refresh();

        $this->assertFalse($otro->is_active);
        $this->assertNotNull($otro->locked_until);
        $this->assertSame(2, $otro->failed_login_attempts);
        $this->assertSame($hashOtro, $otro->password);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_falla_si_no_existe_el_superadministrador(): void
    {
        $this->configureSuperAdmin();

        $this->artisan('superadmin:desbloquear')
            ->expectsOutputToContain('No se encontró')
            ->assertFailed();
    }
}
