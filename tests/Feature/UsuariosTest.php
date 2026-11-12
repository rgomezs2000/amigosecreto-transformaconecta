<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UsuariosTest extends TestCase
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

    private function adminWithUsuariosModule(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Usuarios',
            'slug' => 'usuarios',
            'group' => 'Usuarios',
            'icon' => 'fa-users',
            'order' => 40,
            'is_active' => true,
        ]);

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
            'profile_id' => $profile->id,
        ]);
    }

    public function test_la_pagina_de_usuarios_muestra_datatable_y_acciones(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $this->actingAs($admin)
            ->get('/usuarios/usuarios')
            ->assertOk()
            ->assertSee('Buscar usuario')
            ->assertSee('Nuevo usuario')
            ->assertSee('Eliminar usuarios')
            ->assertSee('usuarios-table', false);
    }

    public function test_la_pagina_exige_el_modulo_de_usuarios(): void
    {
        Module::create([
            'name' => 'Usuarios',
            'slug' => 'usuarios',
            'group' => 'Usuarios',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/usuarios/usuarios')
            ->assertForbidden();
    }

    public function test_datos_devuelve_usuarios_paginados_y_filtrados(): void
    {
        $admin = $this->adminWithUsuariosModule();

        User::factory()->create([
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'username' => 'ana',
            'profile_id' => $admin->profile_id,
        ]);

        $baseQuery = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/datos?' . $baseQuery)
            ->assertOk();

        $filteredQuery = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'ana'],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/datos?' . $filteredQuery)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana Pérez');
    }

    public function test_datos_filtra_por_rol_y_estado(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Ana',
            'profile_id' => $profile->id,
            'is_active' => true,
            'locked_until' => null,
        ]);

        User::factory()->create([
            'name' => 'Bloqueado',
            'profile_id' => $profile->id,
            'is_active' => false,
            'locked_until' => now(),
        ]);

        $roleQuery = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
            'filter_type' => 'role',
            'filter_role' => $profile->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/datos?' . $roleQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 2);

        $activeQuery = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
            'filter_type' => 'status',
            'filter_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/datos?' . $activeQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 2);

        $blockedQuery = http_build_query([
            'draw' => 3,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
            'filter_type' => 'status',
            'filter_status' => 'blocked',
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/datos?' . $blockedQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_crear_usuario_envia_correo_con_datos_y_clave(): void
    {
        Mail::fake();

        $admin = $this->adminWithUsuariosModule();

        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/usuarios', [
                'name' => 'Ana Pérez',
                'email' => 'ana@example.com',
                'username' => 'ana',
                'profile_id' => $profile->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'mail_sent' => true]);

        $user = User::query()->where('username', 'ana')->firstOrFail();

        $this->assertSame('ana@example.com', $user->email);
        $this->assertSame($profile->id, $user->profile_id);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $mail = Mail::sent(NotificationMail::class)->first();
        $line = collect($mail->mailLines)->first(fn ($item) => str_starts_with($item, 'Clave: '));

        $this->assertNotNull($line);

        $password = substr($line, strlen('Clave: '));

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_crear_usuario_valida_campos_y_duplicados(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/usuarios', [
                'name' => '',
                'email' => 'ana@example.com',
                'username' => 'ana',
                'profile_id' => $profile->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($admin)
            ->postJson('/usuarios', [
                'name' => 'Ana',
                'email' => 'no-es-correo',
                'username' => 'ana',
                'profile_id' => $profile->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        User::factory()->create(['username' => 'ana']);

        $this->actingAs($admin)
            ->postJson('/usuarios', [
                'name' => 'Ana',
                'email' => 'ana2@example.com',
                'username' => 'ana',
                'profile_id' => $profile->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_consultar_usuario_ajax_disponible_y_ocupado(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $this->actingAs($admin)
            ->getJson('/perfil/consultar-login?login=nuevo&admin=1')
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->actingAs($admin)
            ->getJson('/perfil/consultar-login?login=' . $admin->username . '&admin=1')
            ->assertOk()
            ->assertJsonPath('available', false);

        $this->actingAs($admin)
            ->getJson('/perfil/consultar-login?login=' . $admin->username . '&ignore=' . $admin->id . '&admin=1')
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_editar_y_ver_usuario(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $user = User::factory()->create([
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'username' => 'ana',
            'profile_id' => $admin->profile_id,
        ]);

        $this->actingAs($admin)
            ->getJson('/usuarios/' . $user->id)
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana Pérez')
            ->assertJsonPath('user.username', 'ana');

        $this->actingAs($admin)
            ->getJson('/usuarios/' . $user->id . '/ver')
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonMissingPath('user.password');
    }

    public function test_modificar_usuario_sin_cambiar_clave_ni_estado(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $profile = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'username' => 'ana',
            'profile_id' => $admin->profile_id,
            'is_active' => true,
            'locked_until' => null,
        ]);

        $originalPassword = $user->password;

        $this->actingAs($admin)
            ->putJson('/usuarios/' . $user->id, [
                'name' => 'Ana María',
                'email' => 'ana.maria@example.com',
                'username' => 'anamaria',
                'profile_id' => $profile->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $user->refresh();

        $this->assertSame('Ana María', $user->name);
        $this->assertSame('anamaria', $user->username);
        $this->assertSame($profile->id, $user->profile_id);
        $this->assertSame($originalPassword, $user->password);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->locked_until);
    }

    public function test_restablecer_contrasena_envia_correo(): void
    {
        Mail::fake();

        $admin = $this->adminWithUsuariosModule();

        $user = User::factory()->create([
            'email' => 'ana@example.com',
            'username' => 'ana',
        ]);

        $this->actingAs($admin)
            ->postJson('/usuarios/' . $user->id . '/restablecer')
            ->assertOk()
            ->assertJson(['success' => true, 'mail_sent' => true]);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_bloquear_y_desbloquear_usuario(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $user = User::factory()->create(['is_active' => true, 'locked_until' => null]);

        $this->actingAs($admin)
            ->postJson('/usuarios/' . $user->id . '/bloquear')
            ->assertOk();

        $user->refresh();

        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->locked_until);

        $this->actingAs($admin)
            ->postJson('/usuarios/' . $user->id . '/desbloquear')
            ->assertOk();

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->locked_until);
    }

    public function test_no_elimina_ni_bloquea_su_propio_usuario(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $this->actingAs($admin)
            ->deleteJson('/usuarios/' . $admin->id)
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson('/usuarios/' . $admin->id . '/bloquear')
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_elimina_usuario_y_varios(): void
    {
        $admin = $this->adminWithUsuariosModule();

        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($admin)
            ->deleteJson('/usuarios/' . $user->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        $this->actingAs($admin)
            ->postJson('/usuarios/eliminar-varios', ['ids' => [$other->id]])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }
}
