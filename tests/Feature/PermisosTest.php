<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PermisosTest extends TestCase
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

    private function permiso(string $name, string $slug): Permission
    {
        return Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }

    private function permisosModule(): Module
    {
        return Module::create([
            'name' => 'Permisos',
            'slug' => 'permisos',
            'group' => 'Maestros',
            'icon' => 'fa-key',
            'order' => 20,
            'is_active' => true,
        ]);
    }

    private function adminWithPermisos(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = $this->permisosModule();
        $view = $this->permiso('Ver', 'view');

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        return User::factory()->create(['profile_id' => $profile->id]);
    }

    public function test_la_pagina_de_permisos_muestra_datatable_y_acciones(): void
    {
        $admin = $this->adminWithPermisos();

        $this->actingAs($admin)
            ->get('/maestros/permisos')
            ->assertOk()
            ->assertSee('Permisos')
            ->assertSee('Buscar permiso')
            ->assertSee('Nuevo permiso')
            ->assertSee('Eliminar permisos')
            ->assertSee('permisos-table', false);
    }

    public function test_la_pagina_exige_el_modulo_de_permisos(): void
    {
        $this->permisosModule();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/maestros/permisos')
            ->assertForbidden();
    }

    public function test_datos_devuelve_permisos_paginados_y_filtrados(): void
    {
        $admin = $this->adminWithPermisos();

        $this->permiso('Editar', 'edit');
        $this->permiso('Aprobar', 'approve');

        $query = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/permisos/datos?' . $query)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 3);

        $filtered = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'aprob'],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/permisos/datos?' . $filtered)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Aprobar');
    }

    public function test_crear_permiso(): void
    {
        $admin = $this->adminWithPermisos();

        $this->actingAs($admin)
            ->postJson('/permisos', ['name' => 'Aprobar'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Permiso creado correctamente.']);

        $this->assertDatabaseHas('permissions', [
            'name' => 'Aprobar',
            'slug' => 'aprobar',
        ]);
    }

    public function test_crear_permiso_valida_obligatorio_y_unico(): void
    {
        $admin = $this->adminWithPermisos();

        $this->actingAs($admin)
            ->postJson('/permisos', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($admin)
            ->postJson('/permisos', ['name' => 'Ver'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_editar_y_ver_permiso(): void
    {
        $admin = $this->adminWithPermisos();
        $permission = $this->permiso('Aprobar', 'approve');

        $this->actingAs($admin)
            ->getJson('/permisos/' . $permission->id)
            ->assertOk()
            ->assertJsonPath('permission.name', 'Aprobar');

        $this->actingAs($admin)
            ->getJson('/permisos/' . $permission->id . '/ver')
            ->assertOk()
            ->assertJsonPath('permission.name', 'Aprobar');
    }

    public function test_modificar_permiso(): void
    {
        $admin = $this->adminWithPermisos();
        $permission = $this->permiso('Aprobar', 'approve');

        $this->actingAs($admin)
            ->putJson('/permisos/' . $permission->id, ['name' => 'Aprobar solicitudes'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Aprobar solicitudes', $permission->fresh()->name);
    }

    public function test_no_elimina_permiso_asociado_a_modulos(): void
    {
        $admin = $this->adminWithPermisos();
        $permission = $this->permiso('Aprobar', 'approve');
        $module = Module::query()->where('slug', 'permisos')->firstOrFail();

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/permisos/' . $permission->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'modules']);

        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_no_elimina_permiso_asignado_a_roles(): void
    {
        $admin = $this->adminWithPermisos();
        $permission = $this->permiso('Aprobar', 'approve');
        $profile = Profile::query()->where('slug', 'administrador')->firstOrFail();
        $module = Module::query()->where('slug', 'permisos')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/permisos/' . $permission->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'roles']);

        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_elimina_permiso_sin_asociaciones(): void
    {
        $admin = $this->adminWithPermisos();
        $permission = $this->permiso('Aprobar', 'approve');

        $this->actingAs($admin)
            ->deleteJson('/permisos/' . $permission->id)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
    }

    public function test_elimina_varios_y_reporta_los_bloqueados(): void
    {
        $admin = $this->adminWithPermisos();
        $libre = $this->permiso('Libre', 'libre');
        $usado = $this->permiso('Usado', 'usado');
        $module = Module::query()->where('slug', 'permisos')->firstOrFail();

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $usado->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/permisos/eliminar-varios', ['ids' => [$libre->id, $usado->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('permissions', ['id' => $libre->id]);
        $this->assertDatabaseHas('permissions', ['id' => $usado->id]);
    }
}
