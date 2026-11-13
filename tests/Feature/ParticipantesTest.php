<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Clue;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ExchangeGift;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Módulo Participantes: monitoreo de solo lectura por evento.
 *
 * La tabla principal y las tablas de las fichas (pistas y consumibles) se
 * paginan en el servidor; el evento por defecto es el actual y en curso.
 */
class ParticipantesTest extends TestCase
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
                'promedio' => 911.6200,
                'fechaActualizacion' => '2026-11-13T00:00:00-04:00',
            ]),
        ]);
    }

    private function perfil(string $slug = 'administrador'): Profile
    {
        return Profile::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug === 'administrador' ? 'Administrador' : 'Usuario', 'is_active' => true]
        );
    }

    private function modulo(bool $conceder = true): Module
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'participantes'],
            [
                'name' => 'Participantes',
                'group' => 'Amigo secreto',
                'icon' => 'fa-users',
                'order' => 75,
                'is_active' => true,
            ]
        );

        if ($conceder) {
            $this->conceder($module, $this->perfil());
        }

        return $module;
    }

    private function conceder(Module $module, Profile $profile): void
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
            'profile_id' => $this->perfil()->id,
        ]);
    }

    private function evento(string $estado = 'registration', int $anio = 2026): Event
    {
        return Event::create([
            'name' => 'Amigo Secreto '.$anio,
            'year' => $anio,
            'status' => $estado,
            'exchange_at' => now()->addDays(37),
        ]);
    }

    private function participar(Event $event, User $user, string $estado = 'active'): EventParticipant
    {
        return EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => $estado,
            'confirmed_at' => now(),
        ]);
    }

    private function participante(string $nombre, string $correo, string $usuario): User
    {
        return User::factory()->create([
            'name' => $nombre,
            'email' => $correo,
            'username' => $usuario,
            'profile_id' => $this->perfil('usuario')->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
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

    public function test_la_pagina_muestra_evento_busqueda_limpiar_y_fichas(): void
    {
        $module = $this->modulo();
        $admin = $this->admin();
        $event = $this->evento();
        $this->participar($event, $this->participante('Ana Pérez', 'ana@example.com', 'ana'));

        $path = parse_url($module->url(), PHP_URL_PATH);

        $this->actingAs($admin)
            ->get($path)
            ->assertOk()
            ->assertSee('Volver al panel')
            ->assertSee('Buscar participante')
            ->assertSee('Busca por nombre, correo o usuario...')
            ->assertSee('Limpiar')
            ->assertSee('participantes-evento', false)
            ->assertSee('participantes-table', false)
            ->assertSee('Creación / Unión')
            ->assertSee('Pistas del amigo secreto')
            ->assertSee('Pistas al amigo secreto')
            ->assertSee('Obsequios consumibles');
    }

    public function test_la_pagina_exige_el_modulo_concedido(): void
    {
        $module = $this->modulo(false);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(parse_url($module->url(), PHP_URL_PATH))
            ->assertForbidden();
    }

    public function test_el_modulo_es_solo_para_la_administracion(): void
    {
        // El módulo concedido al perfil participante no basta: la ficha de la
        // lista de deseos y las pistas son información de administración.
        $module = $this->modulo(false);
        $this->conceder($module, $this->perfil('usuario'));

        $participante = $this->participante('Ana Pérez', 'ana@example.com', 'ana');

        $this->actingAs($participante)
            ->get(parse_url($module->url(), PHP_URL_PATH))
            ->assertForbidden();

        $this->actingAs($participante)
            ->getJson('/participantes/datos?'.$this->dataTable())
            ->assertForbidden();
    }

    public function test_datos_devuelve_los_participantes_del_evento_actual(): void
    {
        $this->modulo();
        $admin = $this->admin();

        $actual = $this->evento('registration', 2026);
        $anterior = $this->evento('revealed', 2025);

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto@example.com', 'beto');
        $viejo = $this->participante('Carla Vieja', 'carla@example.com', 'carla');

        $this->participar($actual, $ana);
        $this->participar($actual, $beto);
        $this->participar($anterior, $viejo);

        $response = $this->actingAs($admin)
            ->getJson('/participantes/datos?'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 2)
            ->assertJsonPath('estado.fase', 1)
            ->assertJsonPath('estado.asignados', 0)
            ->assertJsonPath('estado.sin_asignar', 2);

        $json = $response->json();

        $this->assertStringContainsString('Creación / Unión', $json['estado_html']);
        $this->assertStringContainsString('Sin asignar', $json['data'][0]['secret']);
        $this->assertSame($actual->id, $json['estado']['evento']['id']);
    }

    public function test_datos_busca_por_nombre_correo_o_usuario_y_cambia_de_evento(): void
    {
        $this->modulo();
        $admin = $this->admin();

        $actual = $this->evento('registration', 2026);
        $anterior = $this->evento('revealed', 2025);

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto@example.com', 'beto');
        $viejo = $this->participante('Carla Vieja', 'carla@example.com', 'carla');

        $this->participar($actual, $ana);
        $this->participar($actual, $beto);
        $this->participar($anterior, $viejo);

        $this->actingAs($admin)
            ->getJson('/participantes/datos?'.$this->dataTable([
                'search' => ['value' => 'ana'],
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Ana Pérez');

        $this->actingAs($admin)
            ->getJson('/participantes/datos?'.$this->dataTable([
                'search' => ['value' => 'beto@example.com'],
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.username', 'beto');

        $this->actingAs($admin)
            ->getJson('/participantes/datos?'.$this->dataTable([
                'evento' => $anterior->id,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.name', 'Carla Vieja');
    }

    public function test_ficha_devuelve_los_datos_del_participante(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento();

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $participation = $this->participar($event, $ana);
        $participation->forceFill(['notes' => 'Prefiere recibir en la tarde'])->save();

        $response = $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/ficha?evento='.$event->id)
            ->assertOk()
            ->assertJsonPath('participant.name', 'Ana Pérez')
            ->assertJsonPath('estado.asignado', false);

        $json = $response->json();

        $this->assertStringContainsString('ana@example.com', $json['html']);
        $this->assertStringContainsString('Prefiere recibir en la tarde', $json['html']);
        $this->assertStringContainsString('Sin asignar', $json['html']);
    }

    public function test_ficha_de_un_usuario_ajeno_al_evento_da_404(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento();
        $ajeno = $this->participante('Ana Pérez', 'ana@example.com', 'ana');

        $this->actingAs($admin)
            ->getJson('/participantes/'.$ajeno->id.'/ficha?evento='.$event->id)
            ->assertNotFound();
    }

    public function test_lista_de_deseos_devuelve_la_ficha_y_su_vacio(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento();

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto@example.com', 'beto');
        $participation = $this->participar($event, $ana);
        $this->participar($event, $beto);

        Wishlist::create([
            'event_participant_id' => $participation->id,
            'likes' => 'Libros de aventuras y café',
            'avoid' => 'Sin gluten',
            'categories' => ['libros', 'café'],
        ]);

        $conLista = $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/lista-deseos?evento='.$event->id)
            ->assertOk();

        $json = $conLista->json();

        $this->assertStringContainsString('Libros de aventuras y café', $json['html']);
        $this->assertStringContainsString('Sin gluten', $json['html']);
        $this->assertStringContainsString('libros', $json['html']);

        $sinLista = $this->actingAs($admin)
            ->getJson('/participantes/'.$beto->id.'/lista-deseos?evento='.$event->id)
            ->assertOk();

        $this->assertStringContainsString('Sin lista de deseos registrada', $sinLista->json('html'));
    }

    public function test_pistas_recibidas_y_enviadas_se_paginan_por_tipo(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento('drawn');

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto@example.com', 'beto');
        $carla = $this->participante('Carla Mora', 'carla@example.com', 'carla');

        $this->participar($event, $ana);
        $this->participar($event, $beto);
        $this->participar($event, $carla);

        // Ana regala a Beto: las pistas de la pareja las recibe Beto.
        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        Clue::create([
            'assignment_id' => $assignment->id,
            'template_key' => 'color',
            'body' => 'Mi color favorito es el verde',
            'sent_at' => now(),
            'read_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/participantes/'.$beto->id.'/pistas?evento='.$event->id.'&tipo=recibidas&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('tipo', 'recibidas')
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recibidas_count', 1)
            ->assertJsonPath('enviadas_count', 0)
            ->assertJsonPath('estado.recibe', true)
            ->assertJsonPath('estado.consulta', true);

        $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/pistas?evento='.$event->id.'&tipo=enviadas&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('tipo', 'enviadas')
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('enviadas_count', 1)
            ->assertJsonPath('estado.asignado', true)
            ->assertJsonPath('data.0.para', 'Beto Ruiz');
    }

    public function test_pistas_de_un_participante_sin_amigo_secreto_muestran_la_fase(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento('draw_enabled');
        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $this->participar($event, $ana);

        $response = $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/pistas?evento='.$event->id.'&tipo=recibidas&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('estado.asignado', false)
            ->assertJsonPath('estado.fase', 2)
            ->assertJsonPath('recordsTotal', 0);

        $json = $response->json();

        $this->assertStringContainsString('Sorteo ciego', $json['aviso_html']);
        $this->assertStringContainsString('no tiene amigo secreto asignado', $json['aviso_html']);
    }

    public function test_obsequios_devuelve_el_regalo_y_los_consumibles_paginados(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento('drawn');

        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto@example.com', 'beto');
        $this->participar($event, $ana);
        $this->participar($event, $beto);

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        ExchangeGift::create([
            'assignment_id' => $assignment->id,
            'product' => 'Libro de aventuras',
            'description' => 'Tapa dura',
            'value' => 25,
            'registered_at' => now(),
        ]);

        for ($i = 1; $i <= 12; $i++) {
            ConsumableGift::create([
                'assignment_id' => $assignment->id,
                'description' => 'Chocolate '.$i,
                'quantity' => 1,
                'value' => 2.5,
                'given_on' => now()->subDays($i)->toDateString(),
            ]);
        }

        $obsequio = $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/obsequios?evento='.$event->id)
            ->assertOk()
            ->assertJsonPath('estado.asignado', true)
            ->assertJsonPath('totales.cantidad', 12);

        $json = $obsequio->json();

        $this->assertStringContainsString('Libro de aventuras', $json['html']);
        $this->assertStringContainsString('US$ 25,00', $json['html']);
        $this->assertSame('US$ 30,00', $json['totales']['valor']);

        $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/obsequios/consumibles?evento='.$event->id.'&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsTotal', 12)
            ->assertJsonPath('recordsFiltered', 12)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('totales.cantidad', 12);
    }

    public function test_obsequios_sin_amigo_secreto_muestra_el_estado_del_evento(): void
    {
        $this->modulo();
        $admin = $this->admin();
        $event = $this->evento('registration');
        $ana = $this->participante('Ana Pérez', 'ana@example.com', 'ana');
        $this->participar($event, $ana);

        $response = $this->actingAs($admin)
            ->getJson('/participantes/'.$ana->id.'/obsequios?evento='.$event->id)
            ->assertOk()
            ->assertJsonPath('estado.asignado', false)
            ->assertJsonPath('estado.fase', 1);

        $this->assertStringContainsString('Creación / Unión', $response->json('aviso_html'));
    }

    public function test_el_evento_en_curso_queda_seleccionado_y_los_modulos_van_antes_de_lista_de_deseos(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();

        $participantes = Module::query()->where('code', 'participantes')->firstOrFail();
        $asociados = Module::query()->where('code', 'usuarios-asociados')->firstOrFail();
        $deseos = Module::query()->where('code', 'lista-de-deseos')->firstOrFail();

        $this->assertLessThan($deseos->order, $participantes->order);
        $this->assertLessThan($deseos->order, $asociados->order);
        $this->assertLessThan($asociados->order, $participantes->order);

        // En el panel, los dos módulos de monitoreo van delante de la lista
        // de deseos, en este orden: Participantes y después Usuarios asociados.
        $dashboard = $this->actingAs($admin)->get('/dashboard')->assertOk();
        $tiles = $this->tilesOf($dashboard->getContent(), 'amigo-secreto');

        $participantesIndex = array_search('Participantes', $tiles, true);
        $asociadosIndex = array_search('Usuarios asociados', $tiles, true);
        $deseosIndex = array_search('Lista de deseos', $tiles, true);

        $this->assertNotFalse($participantesIndex);
        $this->assertNotFalse($asociadosIndex);
        $this->assertNotFalse($deseosIndex);
        $this->assertLessThan($deseosIndex, $participantesIndex);
        $this->assertLessThan($deseosIndex, $asociadosIndex);
        $this->assertLessThan($asociadosIndex, $participantesIndex);

        // La página abre por defecto con el evento en curso (2026), no con el
        // evento ya revelado del año anterior.
        $anterior = $this->evento('revealed', 2025);
        $actual = $this->evento('registration', 2026);
        $this->participar($actual, $this->participante('Ana Pérez', 'ana@example.com', 'ana'));

        $html = $this->actingAs($admin)
            ->get(parse_url($participantes->url(), PHP_URL_PATH))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('participantes-table', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$actual->id.'"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$anterior->id.'"\s+selected/', $html);
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
}
