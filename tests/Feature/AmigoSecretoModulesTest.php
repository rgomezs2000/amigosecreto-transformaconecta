<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmigoSecretoModulesTest extends TestCase
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
                'promedio' => 904.4500,
                'fechaActualizacion' => '2026-11-12T00:00:00-04:00',
            ]),
        ]);
    }

    /**
     * Nombres de las fichas del panel, en el orden en que se pintan.
     *
     * @return list<string>
     */
    private function tilesOf(string $html, string $groupSlug): array
    {
        if (! preg_match('/module-panel-group--'.$groupSlug.'".*?<\/section>/s', $html, $group)) {
            return [];
        }

        preg_match_all('/module-tile__name">([^<]+)</', $group[0], $matches);

        return array_map('trim', $matches[1]);
    }

    private function participant(): User
    {
        return User::factory()->create([
            'name' => 'Roger Gomez',
            'profile_id' => Profile::query()->where('slug', 'usuario')->value('id'),
        ]);
    }

    private function administrator(): User
    {
        return User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();
    }

    public function test_el_participante_ve_los_modulos_de_amigo_secreto_en_el_orden_pedido(): void
    {
        $this->seed();

        $response = $this->actingAs($this->participant())->get('/dashboard');

        $response->assertOk();

        $this->assertSame([
            'Sorteo ciego',
            'Ver tu amigo',
            'Lista de deseos',
            'Intercambio de pista',
            'Gran revelación',
        ], $this->tilesOf($response->getContent(), 'amigo-secreto'));
    }

    public function test_ver_tu_amigo_no_se_muestra_al_administrador(): void
    {
        $this->seed();

        $response = $this->actingAs($this->administrator())->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Ver tu amigo');

        $this->assertNotContains('Ver tu amigo', $this->tilesOf($response->getContent(), 'amigo-secreto'));
    }

    public function test_el_modulo_pistas_es_solo_de_la_administracion_y_va_al_final(): void
    {
        $this->seed();

        $admin = $this->administrator();

        $dashboard = $this->actingAs($admin)->get('/dashboard')->assertOk();

        $tiles = $this->tilesOf($dashboard->getContent(), 'amigo-secreto');

        $this->assertSame('Pistas', end($tiles));

        // El módulo abre vacío: el marcador de posición.
        $this->actingAs($admin)
            ->get('/amigo-secreto/pistas')
            ->assertOk()
            ->assertSee('Pistas')
            ->assertSee('Este módulo está en construcción.');

        // El participante no lo ve ni puede entrar.
        $participant = $this->participant();

        $this->actingAs($participant)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Pistas');

        $this->actingAs($participant)
            ->get('/amigo-secreto/pistas')
            ->assertForbidden();
    }

    public function test_ver_tu_amigo_abre_su_pagina_para_el_participante(): void
    {
        $this->seed();

        $this->actingAs($this->participant())
            ->get('/amigo-secreto/ver-tu-amigo')
            ->assertOk()
            ->assertSee('Tu amigo secreto')
            ->assertSee('Ir al Sorteo ciego');
    }

    public function test_el_sorteo_ciego_abre_su_pagina_para_el_participante(): void
    {
        $this->seed();

        $this->actingAs($this->participant())
            ->get('/amigo-secreto/asignar-amigo')
            ->assertOk()
            ->assertSee('Sorteo ciego');
    }

    public function test_ver_tu_amigo_queda_fuera_del_alcance_del_administrador(): void
    {
        $this->seed();

        $this->actingAs($this->administrator())
            ->get('/amigo-secreto/ver-tu-amigo')
            ->assertForbidden();
    }

    public function test_los_seeders_no_dan_ver_tu_amigo_al_perfil_administrador(): void
    {
        $this->seed();

        $module = Module::query()->where('slug', 'ver-tu-amigo')->firstOrFail();
        $permission = Permission::query()->where('slug', 'view')->firstOrFail();
        $admin = Profile::query()->where('slug', 'administrador')->firstOrFail();
        $usuario = Profile::query()->where('slug', 'usuario')->firstOrFail();

        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $usuario->id,
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $admin->id,
            'module_id' => $module->id,
        ]);
    }
}
