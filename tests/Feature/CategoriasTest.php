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

class CategoriasTest extends TestCase
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

    private function crearModuloCategorias(): Module
    {
        return Module::create([
            'name' => 'Categorías',
            'slug' => 'categorias',
            'group' => 'Maestros',
            'icon' => 'fa-tags',
            'order' => 36,
            'is_active' => true,
        ]);
    }

    private function adminWithCategoriasModule(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = $this->crearModuloCategorias();

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

    public function test_la_pagina_de_categorias_muestra_datatable_y_acciones(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $this->actingAs($admin)
            ->get('/maestros/categorias')
            ->assertOk()
            ->assertSee('Categorías')
            ->assertSee('Buscar categoría')
            ->assertSee('Nueva categoría')
            ->assertSee('Eliminar categorías')
            ->assertSee('categorias-table', false);
    }

    public function test_la_pagina_exige_el_modulo_de_categorias(): void
    {
        $this->crearModuloCategorias();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/maestros/categorias')
            ->assertForbidden();
    }

    public function test_datos_devuelve_categorias_paginadas_y_filtradas(): void
    {
        $admin = $this->adminWithCategoriasModule();

        Category::create(['name' => 'General', 'slug' => 'general', 'sort_order' => 10, 'is_active' => true]);
        Category::create(['name' => 'Usuarios', 'slug' => 'usuarios', 'sort_order' => 11, 'is_active' => true]);

        $baseQuery = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/categorias/datos?' . $baseQuery)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 5)
            ->assertJsonPath('recordsFiltered', 5)
            ->assertJsonPath('data.0.name', 'General');

        $filteredQuery = http_build_query([
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'usu'],
            'order' => [['column' => 2, 'dir' => 'asc']],
        ]);

        $this->actingAs($admin)
            ->getJson('/categorias/datos?' . $filteredQuery)
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Usuarios');
    }

    public function test_crear_categoria(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $this->actingAs($admin)
            ->postJson('/categorias', ['name' => 'Temporal', 'slug' => 'temporal'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Categoría creada correctamente.']);

        $this->assertDatabaseHas('categories', [
            'name' => 'Temporal',
            'slug' => 'temporal',
        ]);
    }

    public function test_crear_categoria_valida_obligatorio_y_unico(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $this->actingAs($admin)
            ->postJson('/categorias', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($admin)
            ->postJson('/categorias', ['name' => 'Maestros'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_editar_y_ver_categoria(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/categorias/' . $category->id)
            ->assertOk()
            ->assertJsonPath('category.name', 'General');

        $this->actingAs($admin)
            ->getJson('/categorias/' . $category->id . '/ver')
            ->assertOk()
            ->assertJsonPath('category.name', 'General')
            ->assertJsonPath('category.modules_count', 0);
    }

    public function test_modificar_categoria(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/categorias/' . $category->id, ['name' => 'General actualizada'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('General actualizada', $category->fresh()->name);
    }

    public function test_no_elimina_categoria_con_modulos(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        Module::create([
            'name' => 'Inicio',
            'slug' => 'inicio-test',
            'group' => 'General',
            'icon' => 'fa-house',
            'order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/categorias/' . $category->id)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'blocked_by' => 'modules']);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_elimina_categoria_sin_modulos(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $category = Category::create([
            'name' => 'Temporal',
            'slug' => 'temporal',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/categorias/' . $category->id)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_elimina_varias_y_reporta_las_bloqueadas(): void
    {
        $admin = $this->adminWithCategoriasModule();

        $libre = Category::create([
            'name' => 'Libre',
            'slug' => 'libre',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $usada = Category::create([
            'name' => 'Usada',
            'slug' => 'usada',
            'sort_order' => 21,
            'is_active' => true,
        ]);

        Module::create([
            'name' => 'Módulo de prueba',
            'slug' => 'modulo-prueba',
            'group' => 'Usada',
            'icon' => 'fa-cube',
            'order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/categorias/eliminar-varios', ['ids' => [$libre->id, $usada->id]])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('categories', ['id' => $libre->id]);
        $this->assertDatabaseHas('categories', ['id' => $usada->id]);
    }

    public function test_cada_modulo_queda_asociado_a_una_categoria(): void
    {
        $module = Module::create([
            'name' => 'Eventos',
            'slug' => 'eventos-test',
            'group' => 'Eventos',
            'icon' => 'fa-calendar-days',
            'order' => 50,
            'is_active' => true,
        ]);

        $this->assertNotNull($module->category_id);
        $this->assertSame('Eventos', $module->category->name);
        $this->assertDatabaseHas('categories', ['name' => 'Eventos']);
    }

    public function test_el_menu_agrupa_los_modulos_por_categoria(): void
    {
        $this->seed();

        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Categorías')
            ->assertSee('Maestros')
            ->assertSee('General');
    }
}
