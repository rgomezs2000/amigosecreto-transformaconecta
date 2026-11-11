<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModulosTest extends TestCase
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

    private function adminWithModulosModule(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Módulos',
            'slug' => 'modulos',
            'group' => 'Maestros',
            'icon' => 'fa-cubes',
            'order' => 30,
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

    public function test_la_pagina_de_modulos_muestra_datatable_y_acciones(): void
    {
        $admin = $this->adminWithModulosModule();

        $this->actingAs($admin)
            ->get('/maestros/modulos')
            ->assertOk()
            ->assertSee('Módulos')
            ->assertSee('Buscar módulo')
            ->assertSee('Nuevo módulo')
            ->assertSee('Eliminar módulos')
            ->assertSee('modulos-table', false);
    }

    public function test_la_pagina_exige_el_modulo_de_modulos(): void
    {
        Module::create([
            'name' => 'Módulos',
            'slug' => 'modulos',
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/maestros/modulos')
            ->assertForbidden();
    }

    public function test_datos_devuelve_modulos_paginados_y_filtrados(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        Module::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $baseQuery = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/modulos/datos?' . $baseQuery)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 2);

        $filteredQuery = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'report'],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/modulos/datos?' . $filteredQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Reportes');
    }

    public function test_crear_modulo_con_categoria_y_slug(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/modulos', [
                'name' => 'Reportes',
                'category_id' => $category->id,
                'slug' => 'reportes',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Módulo creado correctamente.']);

        $this->assertDatabaseHas('modules', [
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
        ]);

        $module = Module::query()->where('slug', 'reportes')->firstOrFail();

        $this->assertSame('/maestros/reportes', parse_url($module->url(), PHP_URL_PATH));
    }

    public function test_crear_modulo_valida_campos_y_slug_unico(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/modulos', [
                'name' => '',
                'category_id' => $category->id,
                'slug' => 'reportes',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($admin)
            ->postJson('/modulos', [
                'name' => 'Reportes',
                'category_id' => $category->id,
                'slug' => 'modulos',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_editar_y_ver_modulo(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $module = Module::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/modulos/' . $module->id)
            ->assertOk()
            ->assertJsonPath('module.name', 'Reportes')
            ->assertJsonPath('module.category_id', $category->id);

        $this->actingAs($admin)
            ->getJson('/modulos/' . $module->id . '/ver')
            ->assertOk()
            ->assertJsonPath('module.slug', 'reportes')
            ->assertJsonPath('module.url', $module->url());
    }

    public function test_modificar_modulo(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $module = Module::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/modulos/' . $module->id, [
                'name' => 'Reportes avanzados',
                'category_id' => $category->id,
                'slug' => 'reportes-avanzados',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('Reportes avanzados', $module->fresh()->name);
        $this->assertSame('reportes-avanzados', $module->fresh()->slug);
    }

    public function test_no_elimina_modulo_con_accesos(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $module = Module::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $admin->profile_id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/modulos/' . $module->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'access']);

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
    }

    public function test_no_elimina_modulo_con_permisos(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $module = Module::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        $module->permissions()->attach($permission->id);

        $this->actingAs($admin)
            ->deleteJson('/modulos/' . $module->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'permissions']);

        $this->assertDatabaseHas('modules', ['id' => $module->id]);
    }

    public function test_elimina_modulo_sin_accesos_ni_permisos(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $module = Module::create([
            'name' => 'Temporal',
            'slug' => 'temporal',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/modulos/' . $module->id)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('modules', ['id' => $module->id]);
    }

    public function test_elimina_varios_y_reporta_los_bloqueados(): void
    {
        $admin = $this->adminWithModulosModule();

        $category = Category::query()->where('name', 'Maestros')->firstOrFail();

        $libre = Module::create([
            'name' => 'Libre',
            'slug' => 'libre',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $usado = Module::create([
            'name' => 'Usado',
            'slug' => 'usado',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $admin->profile_id,
            'permission_id' => $permission->id,
            'module_id' => $usado->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/modulos/eliminar-varios', ['ids' => [$libre->id, $usado->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('modules', ['id' => $libre->id]);
        $this->assertDatabaseHas('modules', ['id' => $usado->id]);
    }

    public function test_la_ruta_del_modulo_usa_categoria_y_slug(): void
    {
        $category = Category::create([
            'name' => 'Maestros',
            'slug' => 'maestros',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Roles',
            'slug' => 'roles',
            'category_id' => $category->id,
            'group' => 'Maestros',
            'is_active' => true,
        ]);

        $this->assertSame('/maestros/roles', parse_url($module->url(), PHP_URL_PATH));
    }

    public function test_inicio_queda_en_la_raiz(): void
    {
        $category = Category::create([
            'name' => 'General',
            'slug' => null,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Inicio',
            'slug' => '',
            'category_id' => $category->id,
            'group' => 'General',
            'is_active' => true,
        ]);

        $this->assertSame(route('home'), $module->url());
    }

    public function test_login_queda_en_su_ruta_como_logout(): void
    {
        $category = Category::create([
            'name' => 'Sesión',
            'slug' => null,
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Login',
            'slug' => 'login',
            'category_id' => $category->id,
            'group' => 'Sesión',
            'is_active' => true,
        ]);

        $this->assertSame(route('login'), $module->url());
    }
}
