<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Event;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Usuarios asociados»: monitoreo de solo lectura de las parejas del evento.
 *
 * La página abre cuando existe un evento, consulta por defecto el actual y en
 * curso y permite elegir eventos históricos (queda en solo lectura).
 */
class UsuariosAsociadosTest extends TestCase
{
    use RefreshDatabase;

    private Module $module;

    private Event $actual;

    private Event $historico;

    private User $carla;

    private User $diego;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 911.62,
                'fechaActualizacion' => '2026-11-13T00:00:00-04:00',
            ]),
        ]);

        $this->module = Module::create([
            'name' => 'Usuarios asociados',
            'slug' => 'usuarios-asociados',
            'group' => 'Amigo secreto',
            'icon' => 'fa-link',
            'order' => 76,
            'is_active' => true,
        ]);

        $this->conceder($this->perfil('administrador'), $this->module);

        $this->actual = Event::create([
            'name' => 'Evento actual 2026',
            'year' => 2026,
            'status' => 'registration',
            'exchange_at' => now()->addDays(37),
        ]);

        $this->historico = Event::create([
            'name' => 'Evento histórico 2025',
            'year' => 2025,
            'status' => 'closed',
            'exchange_at' => now()->subDays(30),
        ]);

        $this->carla = User::factory()->create([
            'name' => 'Carla Mora',
            'username' => 'carla',
            'email' => 'carla@example.com',
            'profile_id' => $this->perfil('usuario')->id,
        ]);

        $this->diego = User::factory()->create([
            'name' => 'Diego Paz',
            'username' => 'diego',
            'email' => 'diego@example.com',
            'profile_id' => $this->perfil('usuario')->id,
        ]);

        Assignment::create([
            'event_id' => $this->historico->id,
            'giver_id' => $this->carla->id,
            'receiver_id' => $this->diego->id,
            'drawn_at' => now(),
        ]);
    }

    private function perfil(string $slug): Profile
    {
        return Profile::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug === 'administrador' ? 'Administrador' : 'Usuario', 'is_active' => true]
        );
    }

    private function conceder(Profile $profile, Module $module): void
    {
        $permission = Permission::query()->firstOrCreate(['slug' => 'view'], ['name' => 'Ver']);

        ProfilePermissionModule::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Administrador',
            'username' => 'administrador',
            'email' => 'administrador@example.com',
            'profile_id' => $this->perfil('administrador')->id,
        ]);
    }

    private function pagina(): string
    {
        return parse_url($this->module->url(), PHP_URL_PATH);
    }

    private function dataTable(array $extra = []): string
    {
        return http_build_query(array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ], $extra));
    }

    public function test_la_pagina_muestra_el_selector_de_evento_la_busqueda_y_la_tabla(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('Buscar usuario')
            ->assertSee('Busca por usuario o amigo secreto...')
            ->assertSee('Limpiar')
            ->assertSee('usuarios-asociados-table', false)
            ->assertSee('usuarios-asociados-evento', false)
            ->assertSee('Evento actual 2026')
            ->assertSee('Evento histórico 2025')
            ->assertSee('usuarios-asociados.js', false);
    }

    public function test_la_pagina_exige_el_modulo_y_es_solo_para_la_administracion(): void
    {
        $sinModulo = User::factory()->admin()->create();

        $this->actingAs($sinModulo)
            ->get($this->pagina())
            ->assertForbidden();

        $this->conceder($this->perfil('usuario'), $this->module);
        $participante = User::factory()->create([
            'profile_id' => $this->perfil('usuario')->id,
        ]);

        $this->actingAs($participante)
            ->get($this->pagina())
            ->assertForbidden();

        $this->actingAs($participante)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable())
            ->assertForbidden();
    }

    public function test_sin_eventos_la_pagina_avisa_que_el_modulo_se_activa_con_un_evento(): void
    {
        Event::query()->delete();

        $this->actingAs($this->admin())
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('El módulo de Usuarios asociados se activa cuando se crea un evento')
            ->assertDontSee('usuarios-asociados-table', false);
    }

    public function test_datos_consulta_por_defecto_el_evento_actual_y_permite_el_historico(): void
    {
        $admin = $this->admin();

        // El evento actual no tiene sorteo: no hay parejas.
        $this->actingAs($admin)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0)
            ->assertJsonCount(0, 'data');

        // El evento histórico sí: las parejas se consultan en solo lectura.
        $response = $this->actingAs($admin)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable([
                'evento' => $this->historico->id,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.nombre', 'Carla Mora')
            ->assertJsonPath('data.0.usuario', 'carla')
            ->assertJsonPath('data.0.amigo', 'Diego Paz');

        $this->assertStringContainsString('El evento terminó', $response->json('aviso_html'));
    }

    public function test_datos_busca_por_usuario_o_por_amigo_secreto(): void
    {
        $admin = $this->admin();

        foreach (['carla', 'diego', 'carla@example.com', 'Carla Mora'] as $termino) {
            $this->actingAs($admin)
                ->getJson('/usuarios-asociados/datos?'.$this->dataTable([
                    'evento' => $this->historico->id,
                    'search' => ['value' => $termino],
                ]))
                ->assertOk()
                ->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.nombre', 'Carla Mora');
        }

        $this->actingAs($admin)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable([
                'evento' => $this->historico->id,
                'search' => ['value' => 'no-existe'],
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_datos_pagina_las_parejas_del_evento(): void
    {
        $event = Event::create([
            'name' => 'Evento con muchas parejas',
            'year' => 2024,
            'status' => 'closed',
            'exchange_at' => now()->subYear(),
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $giver = User::factory()->create(['name' => 'Persona '.$i]);
            $receiver = User::factory()->create(['name' => 'Amigo '.$i]);

            Assignment::create([
                'event_id' => $event->id,
                'giver_id' => $giver->id,
                'receiver_id' => $receiver->id,
                'drawn_at' => now(),
            ]);
        }

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable(['evento' => $event->id]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 12)
            ->assertJsonPath('recordsFiltered', 12)
            ->assertJsonCount(10, 'data');

        $this->actingAs($admin)
            ->getJson('/usuarios-asociados/datos?'.$this->dataTable([
                'evento' => $event->id,
                'start' => 10,
                'length' => 10,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_cuando_todos_los_eventos_terminaron_abre_el_mas_reciente_en_historico(): void
    {
        $this->actual->update(['status' => 'revealed']);

        $this->actingAs($this->admin())
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('El evento terminó: esta consulta es histórica')
            ->assertSee('usuarios-asociados-table', false);
    }
}
