<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Module;
use App\Models\ModuleRedirect;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las rutas de los módulos salen del maestro de Módulos: /[categoria]/[modulo].
 *
 * Estas pruebas fijan ese contrato: el slug actual atiende la página, cambiar
 * el slug mueve la ruta (y la anterior redirige), eliminar el módulo borra la
 * ruta y un módulo nuevo queda disponible sin tocar routes/web.php.
 */
class ModuleRoutesTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function module(string $slug, string $group = 'General', array $attributes = []): Module
    {
        return Module::create(array_merge([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'group' => $group,
            'icon' => 'fa-circle',
            'order' => 10,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<int, Module>  $modules
     * @return array{0: User, 1: Profile}
     */
    private function userWith(array $modules): array
    {
        $profile = Profile::create([
            'name' => 'Participante',
            'slug' => 'participante-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $permission = Permission::query()->firstOrCreate(['slug' => 'view'], ['name' => 'Ver']);

        foreach ($modules as $module) {
            ProfilePermissionModule::create([
                'profile_id' => $profile->id,
                'permission_id' => $permission->id,
                'module_id' => $module->id,
            ]);
        }

        $user = User::factory()->create([
            'name' => 'Ana Pérez',
            'profile_id' => $profile->id,
        ]);

        return [$user, $profile];
    }

    public function test_la_pagina_del_modulo_responde_en_su_slug_actual(): void
    {
        $module = $this->module('perfil');
        [$user] = $this->userWith([$module]);

        $this->actingAs($user)
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Matriz del perfil');
    }

    public function test_cambiar_el_slug_mueve_la_ruta_y_la_anterior_redirige(): void
    {
        $module = $this->module('perfil');
        [$user] = $this->userWith([$module]);

        $this->actingAs($user)->get('/perfil')->assertOk();

        // Cambiar el nombre no toca la URL: la URL sale del slug.
        $module->update(['name' => 'Mi perfil']);

        $this->assertSame('/perfil', parse_url($module->fresh()->url(), PHP_URL_PATH));

        // Cambiar el slug sí mueve la ruta, y la antigua responde 301.
        $module->update(['slug' => 'mi-perfil']);

        $this->assertSame('mi-perfil', $module->fresh()->slug);

        $this->actingAs($user)->get('/mi-perfil')->assertOk();

        $this->actingAs($user)
            ->get('/perfil')
            ->assertStatus(301)
            ->assertRedirect('/mi-perfil');
    }

    public function test_la_ruta_historica_redirige_a_la_actual(): void
    {
        $module = $this->module('perfil');
        [$user] = $this->userWith([$module]);

        ModuleRedirect::create([
            'path' => 'perfiles',
            'module_id' => $module->id,
        ]);

        $this->actingAs($user)
            ->get('/perfiles')
            ->assertStatus(301)
            ->assertRedirect('/perfil');
    }

    public function test_un_modulo_nuevo_expone_su_pagina(): void
    {
        $category = Category::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        $module = $this->module('resumen', 'Reportes', ['category_id' => $category->id]);
        [$user] = $this->userWith([$module]);

        $this->assertSame('/reportes/resumen', parse_url($module->url(), PHP_URL_PATH));

        $this->actingAs($user)
            ->get('/reportes/resumen')
            ->assertOk()
            ->assertSee('Este módulo está en construcción.');
    }

    public function test_eliminar_el_modulo_elimina_su_ruta(): void
    {
        $category = Category::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        $module = $this->module('resumen', 'Reportes', ['category_id' => $category->id]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reportes/resumen')->assertForbidden();

        $module->delete();

        $this->actingAs($user)->get('/reportes/resumen')->assertNotFound();
    }

    public function test_un_modulo_inactivo_no_tiene_pagina(): void
    {
        $module = $this->module('perfil', 'General', ['is_active' => false]);
        [$user] = $this->userWith([$module]);

        $this->actingAs($user)->get('/perfil')->assertNotFound();
    }

    public function test_el_modulo_sin_acceso_da_403(): void
    {
        $this->module('perfil');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/perfil')->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->module('perfil');

        $this->get('/perfil')->assertRedirect(route('login'));
    }

    public function test_cambiar_el_slug_de_la_categoria_mueve_las_rutas(): void
    {
        $category = Category::create([
            'name' => 'Reportes',
            'slug' => 'reportes',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        $module = $this->module('resumen', 'Reportes', ['category_id' => $category->id]);
        [$user] = $this->userWith([$module]);

        $this->actingAs($user)->get('/reportes/resumen')->assertOk();

        $category->update(['slug' => 'informes']);

        $this->actingAs($user)->get('/informes/resumen')->assertOk();

        $this->actingAs($user)
            ->get('/reportes/resumen')
            ->assertStatus(301)
            ->assertRedirect('/informes/resumen');
    }

    public function test_el_modulo_de_perfil_acepta_su_slug_historico(): void
    {
        // Bases de datos anteriores sembraban el módulo como «perfiles».
        $module = $this->module('perfiles');
        [$user] = $this->userWith([$module]);

        $this->actingAs($user)
            ->get('/perfiles')
            ->assertOk()
            ->assertSee('Matriz del perfil');
    }
}
