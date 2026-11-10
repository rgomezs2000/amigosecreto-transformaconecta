<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'ana',
            'email' => 'ana@example.com',
            'password' => 'ClaveSegura123',
            'is_active' => true,
        ], $attributes));
    }

    public function test_login_requiere_usuario_y_clave(): void
    {
        $this->postJson('/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Escribe tu usuario o correo.')
            ->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_login_rechaza_credenciales_incorrectas(): void
    {
        $this->user();

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'incorrecta',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('remaining_attempts', 2);
    }

    public function test_login_bloquea_al_tercer_intento(): void
    {
        $user = $this->user();

        $this->postJson('/login', ['login' => 'ana', 'password' => 'mal'])
            ->assertStatus(401);

        $this->postJson('/login', ['login' => 'ana', 'password' => 'mal'])
            ->assertStatus(401);

        $this->postJson('/login', ['login' => 'ana', 'password' => 'mal'])
            ->assertStatus(423)
            ->assertJsonPath('locked', true);

        $user->refresh();

        $this->assertNotNull($user->locked_until);
        $this->assertSame(3, $user->failed_login_attempts);

        // Aun con la clave correcta, sigue bloqueada.
        $this->postJson('/login', ['login' => 'ana', 'password' => 'ClaveSegura123'])
            ->assertStatus(423);
    }

    public function test_login_correcto_no_inicia_sesion(): void
    {
        $this->user();

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'ClaveSegura123',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('redirect', null);

        $this->assertGuest();
    }

    public function test_login_acepta_el_correo_sin_distinguir_mayusculas(): void
    {
        $this->user();

        $this->postJson('/login', [
            'login' => 'ANA@EXAMPLE.COM',
            'password' => 'ClaveSegura123',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_login_rechaza_cuenta_inactiva(): void
    {
        $this->user(['is_active' => false]);

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'ClaveSegura123',
        ])->assertStatus(403);
    }

    public function test_login_rechaza_cuenta_sin_clave(): void
    {
        $this->user(['password' => null]);

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'ClaveSegura123',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Esta cuenta se creó con Google. Ingresa con el botón de Google.');
    }

    public function test_login_correcto_reinicia_intentos(): void
    {
        $user = $this->user();

        $user->forceFill(['failed_login_attempts' => 2])->save();

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'ClaveSegura123',
        ])->assertOk();

        $user->refresh();

        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_el_administrador_puede_desbloquear_la_cuenta(): void
    {
        $user = $this->user();

        $user->forceFill([
            'failed_login_attempts' => 3,
            'locked_until' => now(),
        ])->save();

        $this->artisan('user:unlock', ['login' => 'ana'])->assertSuccessful();

        $user->refresh();

        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_login_respeta_el_bloqueo_vigente(): void
    {
        $user = $this->user();

        $user->forceFill([
            'failed_login_attempts' => 3,
            'locked_until' => now()->subMinute(),
        ])->save();

        $this->postJson('/login', [
            'login' => 'ana',
            'password' => 'ClaveSegura123',
        ])->assertStatus(423);
    }
}
