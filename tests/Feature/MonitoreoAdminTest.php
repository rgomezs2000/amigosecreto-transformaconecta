<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Clue;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ExchangeGift;
use App\Models\Module;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Módulos de consulta de la administración: Lista de deseos, Usuarios
 * obsequiados y Pistas.
 */
class MonitoreoAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 900.00,
                'fechaActualizacion' => '2026-11-19T00:00:00-04:00',
            ]),
        ]);

        $this->seed();

        $this->admin = User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();
    }

    private function evento(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'name' => 'Evento de monitoreo',
            'year' => 2026,
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->addDays(10),
            'max_value' => 100,
        ], $attributes));
    }

    private function participante(
        Event $event,
        string $name,
        string $slug,
        ParticipantStatus $status = ParticipantStatus::Active
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'username' => $slug,
            'email' => $slug.'@example.com',
            'role' => UserRole::Participant,
            'profile_id' => Profile::query()->where('slug', 'usuario')->value('id'),
            'is_active' => true,
        ]);

        EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => $status->value,
        ]);

        return $user;
    }

    private function pagina(string $code): string
    {
        return parse_url(Module::activeByCode($code)->url(), PHP_URL_PATH);
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

    public function test_la_lista_de_deseos_del_administrador_muestra_usuarios_y_estatus(): void
    {
        $event = $this->evento(['status' => EventStatus::Registration]);
        $this->participante($event, 'Ana Activa', 'ana');

        $this->actingAs($this->admin)
            ->get($this->pagina('lista-de-deseos'))
            ->assertOk()
            ->assertSee('Lista de deseos')
            ->assertSee('Estatus de participación')
            ->assertSee('monitoreo-table', false)
            ->assertSee('monitoreo.js', false);
    }

    public function test_la_tabla_de_monitoreo_filtra_por_estatus_y_busqueda(): void
    {
        $event = $this->evento();
        $this->participante($event, 'Ana Activa', 'ana');
        $this->participante($event, 'Beto Descartado', 'beto', ParticipantStatus::Dismissed);

        $this->actingAs($this->admin)
            ->getJson('/monitoreo/deseos/datos?'.$this->dataTable([
                'evento' => $event->id,
                'estado' => ParticipantStatus::Dismissed->value,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Beto Descartado')
            ->assertJsonPath('data.0.status_label', 'Descartado');

        $this->actingAs($this->admin)
            ->getJson('/monitoreo/deseos/datos?'.$this->dataTable([
                'evento' => $event->id,
                'search' => ['value' => 'Ana'],
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Ana Activa');

        $this->assertStringContainsString(
            'Intercambio de pistas',
            $this->actingAs($this->admin)
                ->getJson('/monitoreo/deseos/datos?'.$this->dataTable(['evento' => $event->id]))
                ->json('estado_html')
        );
    }

    public function test_usuarios_obsequiados_avisa_antes_del_sorteo(): void
    {
        $event = $this->evento(['status' => EventStatus::Registration]);
        $this->participante($event, 'Ana Activa', 'ana');

        $this->actingAs($this->admin)
            ->get($this->pagina('usuarios-obsequiados'))
            ->assertOk()
            ->assertSee('Usuarios obsequiados')
            ->assertSee('Los obsequios se activan después del sorteo ciego');
    }

    public function test_usuarios_obsequiados_y_pistas_se_habilitan_despues_del_sorteo(): void
    {
        $event = $this->evento();
        $ana = $this->participante($event, 'Ana Activa', 'ana');
        $beto = $this->participante($event, 'Beto Activo', 'beto');

        Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get($this->pagina('usuarios-obsequiados'))
            ->assertOk()
            ->assertSee('monitoreo-table', false);

        $this->actingAs($this->admin)
            ->get($this->pagina('pistas'))
            ->assertOk()
            ->assertSee('Pistas del amigo secreto')
            ->assertSee('Pistas al amigo secreto')
            ->assertSee('monitoreo-table', false);
    }

    public function test_la_ficha_de_obsequiados_muestra_el_obsequio_recibido(): void
    {
        $event = $this->evento();
        $giver = $this->participante($event, 'Ana Da', 'ana');
        $receiver = $this->participante($event, 'Beto Recibe', 'beto');

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $giver->id,
            'receiver_id' => $receiver->id,
            'drawn_at' => now(),
        ]);

        ExchangeGift::create([
            'assignment_id' => $assignment->id,
            'product' => 'Café',
            'value' => 10,
            'registered_at' => now(),
        ]);

        ConsumableGift::create([
            'assignment_id' => $assignment->id,
            'description' => 'Chocolate',
            'quantity' => 2,
            'value' => 5,
            'given_on' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/participantes/'.$receiver->id.'/obsequios?evento='.$event->id.'&rol=recibido')
            ->assertOk()
            ->assertJsonPath('gift.product', 'Café');

        $this->assertNotNull($response->json('gift.value_bs'));
        $this->assertStringContainsString('Valor en Bs.', $response->json('html'));

        $this->actingAs($this->admin)
            ->getJson('/participantes/'.$receiver->id.'/obsequios/consumibles?'.$this->dataTable([
                'evento' => $event->id,
                'rol' => 'recibido',
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.nombre', 'Chocolate');
    }

    public function test_las_pistas_del_administrador_separan_recibidas_y_enviadas(): void
    {
        $event = $this->evento();
        $giver = $this->participante($event, 'Ana Da', 'ana');
        $receiver = $this->participante($event, 'Beto Recibe', 'beto');

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $giver->id,
            'receiver_id' => $receiver->id,
            'drawn_at' => now(),
        ]);

        Clue::create([
            'assignment_id' => $assignment->id,
            'body' => 'Me gusta el café',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson('/participantes/'.$receiver->id.'/pistas?evento='.$event->id.'&tipo=recibidas&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.pista', 'Me gusta el café');

        $this->actingAs($this->admin)
            ->getJson('/participantes/'.$giver->id.'/pistas?evento='.$event->id.'&tipo=enviadas&'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.para', 'Beto Recibe');
    }

    public function test_los_modulos_de_monitoreo_son_solo_para_administracion(): void
    {
        $event = $this->evento();
        $ana = $this->participante($event, 'Ana Activa', 'ana');

        $this->actingAs($ana)
            ->get($this->pagina('pistas'))
            ->assertForbidden();

        $this->actingAs($ana)
            ->getJson('/monitoreo/deseos/datos?'.$this->dataTable(['evento' => $event->id]))
            ->assertForbidden();

        $this->actingAs($ana)
            ->getJson('/participantes/'.$ana->id.'/ficha?evento='.$event->id)
            ->assertForbidden();
    }

    public function test_la_fase_se_muestra_como_etiqueta_de_color_en_eventos(): void
    {
        $this->evento(['status' => EventStatus::Registration]);

        $this->actingAs($this->admin)
            ->get($this->pagina('eventos'))
            ->assertOk()
            ->assertSee('fase-badge fase-badge--2', false);
    }
}
