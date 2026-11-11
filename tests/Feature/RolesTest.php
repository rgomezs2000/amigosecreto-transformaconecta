<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RolesTest extends TestCase
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

    private function crearModuloRoles(): Module
    {
        return Module::create([
            'name' => 'Roles',
            'slug' => 'roles',
            'group' => 'Maestros',
            'icon' => 'fa-user-shield',
            'order' => 10,
            'is_active' => true,
        ]);
    }

    private function adminWithRolesModule(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = $this->crearModuloRoles();

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

    public function test_la_pagina_de_roles_muestra_datatable_y_acciones(): void
    {
        $admin = $this->adminWithRolesModule();

        $this->actingAs($admin)
            ->get('/maestros/roles')
            ->assertOk()
            ->assertSee('Roles')
            ->assertSee('Buscar rol')
            ->assertSee('Nuevo rol')
            ->assertSee('Eliminar roles')
            ->assertSee('roles-table', false);
    }

    public function test_la_pagina_exige_el_modulo_de_roles(): void
    {
        $this->crearModuloRoles();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/maestros/roles')
            ->assertForbidden();
    }

    public function test_datos_devuelve_roles_paginados_y_filtrados(): void
    {
        $admin = $this->adminWithRolesModule();

        Profile::create(['name' => 'Editor', 'slug' => 'editor', 'is_active' => true]);
        Profile::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => true]);

        $baseQuery = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/roles/datos?' . $baseQuery)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 3)
            ->assertJsonPath('data.0.name', 'Administrador');

        $filteredQuery = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'edit'],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/roles/datos?' . $filteredQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Editor');
    }

    public function test_crear_rol(): void
    {
        $admin = $this->adminWithRolesModule();

        $this->actingAs($admin)
            ->postJson('/roles', ['name' => 'Editor'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Rol creado correctamente.']);

        $this->assertDatabaseHas('profiles', [
            'name' => 'Editor',
            'slug' => 'editor',
        ]);
    }

    public function test_crear_rol_valida_obligatorio_y_unico(): void
    {
        $admin = $this->adminWithRolesModule();

        $this->actingAs($admin)
            ->postJson('/roles', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($admin)
            ->postJson('/roles', ['name' => 'Administrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_editar_y_ver_rol(): void
    {
        $admin = $this->adminWithRolesModule();

        $role = Profile::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/roles/' . $role->id)
            ->assertOk()
            ->assertJsonPath('role.name', 'Editor');

        $this->actingAs($admin)
            ->getJson('/roles/' . $role->id . '/ver')
            ->assertOk()
            ->assertJsonPath('role.name', 'Editor');
    }

    public function test_modificar_rol(): void
    {
        $admin = $this->adminWithRolesModule();

        $role = Profile::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/roles/' . $role->id, ['name' => 'Editor senior'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Editor senior', $role->fresh()->name);
    }

    public function test_no_elimina_rol_con_usuarios(): void
    {
        $admin = $this->adminWithRolesModule();

        $role = Profile::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);

        User::factory()->create(['profile_id' => $role->id]);

        $this->actingAs($admin)
            ->deleteJson('/roles/' . $role->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'users']);

        $this->assertDatabaseHas('profiles', ['id' => $role->id]);
    }

    public function test_no_elimina_rol_con_accesos(): void
    {
        $admin = $this->adminWithRolesModule();

        $role = Profile::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);

        $module = Module::query()->where('slug', 'roles')->firstOrFail();

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/roles/' . $role->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'access']);

        $this->assertDatabaseHas('profiles', ['id' => $role->id]);
        $this->assertDatabaseHas('profile_permission_module', ['profile_id' => $role->id]);
    }

    public function test_elimina_rol_sin_usuarios_y_sin_accesos(): void
    {
        $admin = $this->adminWithRolesModule();

        $role = Profile::create([
            'name' => 'Editor',
            'slug' => 'editor',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/roles/' . $role->id)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('profiles', ['id' => $role->id]);
    }

    public function test_elimina_varios_y_reporta_los_bloqueados(): void
    {
        $admin = $this->adminWithRolesModule();

        $libre = Profile::create([
            'name' => 'Libre',
            'slug' => 'libre',
            'is_active' => true,
        ]);

        $usado = Profile::create([
            'name' => 'Usado',
            'slug' => 'usado',
            'is_active' => true,
        ]);

        User::factory()->create(['profile_id' => $usado->id]);

        $this->actingAs($admin)
            ->postJson('/roles/eliminar-varios', ['ids' => [$libre->id, $usado->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('profiles', ['id' => $libre->id]);
        $this->assertDatabaseHas('profiles', ['id' => $usado->id]);
    }
}
