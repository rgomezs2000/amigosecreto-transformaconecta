<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
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
     * @return array{0: User, 1: Module}
     */
    private function userWithModule(): array
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
            'order' => 10,
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

        $user = User::factory()->create([
            'name' => 'Ana Pérez',
            'profile_id' => $profile->id,
        ]);

        return [$user, $module];
    }

    public function test_dashboard_muestra_saludo_y_menu(): void
    {
        [$user, $module] = $this->userWithModule();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Hola: ' . $user->name)
            ->assertSee($module->name)
            ->assertSee('dashboard-clock', false)
            ->assertSee('US$/Bs (BCV)')
            ->assertSee('899,27');
    }

    public function test_modulo_permitido_se_muestra(): void
    {
        [$user, $module] = $this->userWithModule();

        $this->actingAs($user)
            ->get($module->url())
            ->assertOk()
            ->assertSee($module->name);
    }

    public function test_modulo_no_permitido_da_403(): void
    {
        $module = Module::create([
            'name' => 'Usuarios',
            'slug' => 'usuarios',
            'group' => 'Usuarios',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($module->url())
            ->assertForbidden()
            ->assertSee('Error 403')
            ->assertSee('Acceso denegado');
    }

    public function test_modulo_inexistente_da_404(): void
    {
        [$user] = $this->userWithModule();

        $this->actingAs($user)
            ->get('/no-existe/no-existe')
            ->assertNotFound()
            ->assertSee('Error 404')
            ->assertSee('Página no encontrada');
    }

    public function test_ruta_inexistente_sin_sesion_da_404(): void
    {
        $this->get('/ruta-inexistente')
            ->assertNotFound()
            ->assertSee('Error 404')
            ->assertSee('Ir al acceso');
    }

    public function test_ruta_inexistente_con_sesion_muestra_el_shell(): void
    {
        [$user] = $this->userWithModule();

        $this->actingAs($user)
            ->get('/ruta-inexistente')
            ->assertNotFound()
            ->assertSee('Error 404')
            ->assertSee('Página no encontrada');
    }

    public function test_la_pagina_de_error_500_se_puede_renderizar(): void
    {
        $this->view('errors.500')
            ->assertSee('Error 500')
            ->assertSee('Error del servidor');
    }

    public function test_dashboard_muestra_la_fase_activa_y_la_cuenta_regresiva(): void
    {
        [$user] = $this->userWithModule();

        Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->addDays(12),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Amigo Secreto 2026')
            ->assertSee('Lista de deseos')
            ->assertSee('event-countdown', false)
            ->assertSee('data-exchange-at', false)
            ->assertSee('data-server-now', false)
            ->assertSee('aria-current="step"', false);
    }

    public function test_dashboard_sin_modulos_muestra_aviso(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No tienes módulos asignados');
    }

    public function test_inicio_aparece_solo_en_el_menu(): void
    {
        [$user] = $this->userWithModule();

        $inicio = Module::create([
            'name' => 'Inicio',
            'slug' => '',
            'group' => 'General',
            'icon' => 'fa-house',
            'order' => 1,
            'is_active' => true,
        ]);

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $user->profile_id,
            'permission_id' => $permission->id,
            'module_id' => $inicio->id,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Inicio')
            ->assertSee('href="' . route('home') . '"', false)
            ->assertDontSee('module-tile__name">Inicio', false);
    }
}
