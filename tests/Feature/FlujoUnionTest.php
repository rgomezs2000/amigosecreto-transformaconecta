<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\ChatMessage;
use App\Models\Clue;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Models\Profile;
use App\Models\User;
use App\Models\WishItem;
use App\Services\InvitacionesEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Flujo reestructurado: Unión con invitaciones, lista de deseos, intercambio
 * de pistas y gran revelación.
 */
class FlujoUnionTest extends TestCase
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
                'fechaActualizacion' => '2026-11-17T00:00:00-04:00',
            ]),
        ]);

        Mail::fake();

        $this->seed();

        $this->admin = User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();
    }

    private function participante(string $name = 'Ana Pérez', string $slug = 'ana'): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => $slug,
            'email' => $slug.'@example.com',
            'role' => UserRole::Participant,
            'profile_id' => Profile::query()->where('slug', 'usuario')->value('id'),
            'is_active' => true,
        ]);
    }

    private function evento(array $attributes = []): Event
    {
        $event = Event::create(array_merge([
            'name' => 'Amigo Secreto 2026',
            'year' => (int) now()->year,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(10),
            'max_value' => 100,
        ], $attributes));

        app(InvitacionesEvento::class)->generar($event);

        return $event;
    }

    private function participacion(Event $event, User $user): EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function path(string $code): string
    {
        return parse_url(Module::activeByCode($code)->url(), PHP_URL_PATH);
    }

    public function test_el_enlace_de_invitacion_lleva_al_login_y_luego_a_ver_tu_amigo(): void
    {
        $ana = $this->participante();
        $event = $this->evento();
        $token = $this->participacion($event, $ana)->invitation_token;

        $this->get('/invitacion/'.$token)
            ->assertRedirect(route('login'));

        $this->actingAs($ana)
            ->get('/invitacion/'.$token)
            ->assertRedirect(module_url('ver-tu-amigo').'?invitacion='.$token);

        $this->actingAs($ana)
            ->get(module_url('ver-tu-amigo').'?invitacion='.$token)
            ->assertOk()
            ->assertSee('Invitación al evento')
            ->assertSee('Responder invitación');
    }

    public function test_aceptar_invitacion_activa_al_participante_y_habilita_el_sorteo(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento();

        foreach ([$ana, $beto] as $user) {
            $token = $this->participacion($event, $user)->invitation_token;

            $this->actingAs($user)
                ->postJson('/invitacion/'.$token.'/aceptar')
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $this->assertSame(2, $event->activeParticipationsCount());

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/iniciar')
            ->assertOk();

        $this->assertSame(EventStatus::DrawEnabled, $event->fresh()->status);
    }

    public function test_no_inicia_con_invitaciones_pendientes_y_descartar_permite_iniciar(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $carla = $this->participante('Carla Mora', 'carla');
        $event = $this->evento();

        foreach ([$ana, $beto] as $user) {
            $this->actingAs($user)
                ->postJson('/invitacion/'.$this->participacion($event, $user)->invitation_token.'/aceptar')
                ->assertOk();
        }

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/iniciar')
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$carla->id.'/descartar', [
                'reason' => 'No respondió la invitación.',
            ])
            ->assertOk();

        $this->assertSame(
            ParticipantStatus::Dismissed,
            $this->participacion($event, $carla)->status
        );

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/iniciar')
            ->assertOk();
    }

    public function test_rechazar_invitacion_exige_motivo_y_queda_declinada(): void
    {
        $ana = $this->participante();
        $event = $this->evento();
        $token = $this->participacion($event, $ana)->invitation_token;

        $this->actingAs($ana)
            ->postJson('/invitacion/'.$token.'/rechazar', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($ana)
            ->postJson('/invitacion/'.$token.'/rechazar', ['reason' => 'No puedo participar este año.'])
            ->assertOk();

        $participacion = $this->participacion($event, $ana);

        $this->assertSame(ParticipantStatus::Declined, $participacion->status);
        $this->assertSame('No puedo participar este año.', $participacion->status_reason);
    }

    public function test_retiro_y_expulsion_guardan_motivo(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento();

        foreach ([$ana, $beto] as $user) {
            $this->actingAs($user)
                ->postJson('/invitacion/'.$this->participacion($event, $user)->invitation_token.'/aceptar')
                ->assertOk();
        }

        $this->actingAs($ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/retirar', ['reason' => 'Motivos personales.'])
            ->assertOk();

        $this->assertSame(ParticipantStatus::Withdrawn, $this->participacion($event, $ana)->status);

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$beto->id.'/expulsar', [
                'reason' => 'Incumplió las normas del evento.',
            ])
            ->assertOk();

        $this->assertSame(ParticipantStatus::Expelled, $this->participacion($event, $beto)->status);
        $this->assertSame('Incumplió las normas del evento.', $this->participacion($event, $beto)->status_reason);
    }

    public function test_lista_de_deseos_solo_para_activos_y_con_crud(): void
    {
        $ana = $this->participante();
        $event = $this->evento();
        $token = $this->participacion($event, $ana)->invitation_token;

        $this->actingAs($ana)
            ->postJson('/lista-de-deseos', ['title' => 'Un libro'])
            ->assertStatus(409);

        $this->actingAs($ana)
            ->postJson('/invitacion/'.$token.'/aceptar')
            ->assertOk();

        $this->actingAs($ana)
            ->postJson('/lista-de-deseos', [
                'title' => 'Un libro de aventuras',
                'description' => 'Tapa dura',
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Un libro de aventuras');

        $item = WishItem::query()->firstOrFail();

        $this->actingAs($ana)
            ->getJson('/lista-de-deseos/datos?draw=1&start=0&length=10&search[value]=libro')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);

        $this->actingAs($ana)
            ->putJson('/lista-de-deseos/'.$item->id, [
                'title' => 'Un libro de misterio',
                'description' => 'Tapa blanda',
            ])
            ->assertOk();

        $this->actingAs($ana)
            ->deleteJson('/lista-de-deseos/'.$item->id)
            ->assertOk();

        $this->assertDatabaseCount('wish_items', 0);
    }

    public function test_intercambio_de_pistas_crud_y_recibidas_solo_lectura(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento(['status' => EventStatus::Drawn]);

        foreach ([$ana, $beto] as $user) {
            $this->participacion($event, $user)->forceFill([
                'status' => ParticipantStatus::Active->value,
                'confirmed_at' => now(),
            ])->save();
        }

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        $this->actingAs($ana)
            ->postJson('/intercambio-de-pistas', ['body' => 'Me gusta el café.'])
            ->assertOk()
            ->assertJsonPath('pista.body', 'Me gusta el café.');

        $clue = Clue::query()->firstOrFail();

        $this->actingAs($beto)
            ->getJson('/intercambio-de-pistas/'.$clue->id)
            ->assertOk()
            ->assertJsonPath('pista.body', 'Me gusta el café.');

        $this->actingAs($beto)
            ->putJson('/intercambio-de-pistas/'.$clue->id, ['body' => 'Cambio'])
            ->assertStatus(409);

        $this->actingAs($ana)
            ->putJson('/intercambio-de-pistas/'.$clue->id, ['body' => 'Me encanta el café.'])
            ->assertOk();

        $this->actingAs($ana)
            ->deleteJson('/intercambio-de-pistas/'.$clue->id)
            ->assertOk();

        $this->assertDatabaseCount('clues', 0);
    }

    public function test_las_paginas_de_lista_de_deseos_y_pistas_renderizan_para_el_activo(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento(['status' => EventStatus::Drawn]);

        foreach ([$ana, $beto] as $user) {
            $this->participacion($event, $user)->forceFill([
                'status' => ParticipantStatus::Active->value,
                'confirmed_at' => now(),
            ])->save();
        }

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        // Beto también le regala a Ana: así Ana recibe una pista incógnita.
        $recibida = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $beto->id,
            'receiver_id' => $ana->id,
            'drawn_at' => now(),
        ]);

        WishItem::create([
            'event_id' => $event->id,
            'user_id' => $beto->id,
            'title' => 'Una bufanda',
            'description' => 'Color verde',
        ]);

        Clue::create([
            'assignment_id' => $recibida->id,
            'body' => 'Me gusta el café',
            'sent_at' => now(),
        ]);

        $this->actingAs($ana)
            ->get($this->path('lista-de-deseos'))
            ->assertOk()
            ->assertSee('Lista de deseos')
            ->assertSee('Agregar un deseo')
            ->assertSee('deseos-table', false);

        $this->actingAs($ana)
            ->get($this->path('intercambio-de-pista'))
            ->assertOk()
            ->assertSee('Intercambio de pistas')
            ->assertSee('Pistas al amigo secreto')
            ->assertSee('Pistas del amigo secreto')
            ->assertSee('pistas-enviadas-table', false);

        $this->actingAs($ana)
            ->get($this->path('ver-tu-amigo'))
            ->assertOk()
            ->assertSee('Mi amigo secreto')
            ->assertSee('Lista de deseos del amigo')
            ->assertSee('Pistas de mi amigo secreto')
            ->assertSee('Una bufanda')
            ->assertSee('Me gusta el café')
            ->assertSee('Beto Ruiz')
            ->assertDontSee('@beto');
    }

    public function test_el_chat_secreto_no_revela_el_nombre_del_remitente_al_receptor(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento(['status' => EventStatus::Drawn]);

        foreach ([$ana, $beto] as $user) {
            $this->participacion($event, $user)->forceFill([
                'status' => ParticipantStatus::Active->value,
                'confirmed_at' => now(),
            ])->save();
        }

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $beto->id,
            'drawn_at' => now(),
        ]);

        $conversation = Conversation::create([
            'type' => 'secret',
            'event_id' => $event->id,
            'assignment_id' => $assignment->id,
            'created_by' => $beto->id,
            'is_moderated' => true,
        ]);

        $conversation->users()->attach([$ana->id, $beto->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Hola, soy tu amigo secreto.',
            'type' => 'text',
            'delivered_at' => now(),
        ]);

        $this->actingAs($beto)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.sender', 'Amigo secreto')
            ->assertJsonPath('messages.0.sender_id', null);

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.sender', 'Ana Pérez');
    }

    public function test_gran_revelacion_se_bloquea_antes_de_la_fecha_y_revela_el_dia(): void
    {
        $ana = $this->participante('Ana Pérez', 'ana');
        $beto = $this->participante('Beto Ruiz', 'beto');
        $event = $this->evento([
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->addDays(3),
        ]);

        foreach ([$ana, $beto] as $user) {
            $this->participacion($event, $user)->forceFill([
                'status' => ParticipantStatus::Active->value,
                'confirmed_at' => now(),
            ])->save();
        }

        // Beto le regala a Ana (el incógnito que Ana debe descubrir).
        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $beto->id,
            'receiver_id' => $ana->id,
            'drawn_at' => now(),
        ]);

        $gift = \App\Models\ExchangeGift::create([
            'assignment_id' => $assignment->id,
            'product' => 'Una taza artesanal',
            'value' => 25,
            'registered_at' => now(),
        ]);

        $this->actingAs($ana)
            ->get($this->path('gran-revelacion'))
            ->assertOk()
            ->assertSee('Aún no es el día de la revelación');

        $event->update(['exchange_at' => now()]);

        $this->actingAs($ana)
            ->get($this->path('gran-revelacion'))
            ->assertOk()
            ->assertSee('Beto Ruiz')
            ->assertSee('Una taza artesanal')
            ->assertSee($gift->product);

        $this->assertSame(EventStatus::Revealed, $event->fresh()->status);
        $this->assertNotNull($assignment->fresh()->revealed_at);
    }

    public function test_el_evento_se_finaliza_cuando_pasa_la_fecha_del_intercambio(): void
    {
        $ana = $this->participante();
        $event = $this->evento([
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->subDay(),
        ]);

        $this->participacion($event, $ana)->forceFill([
            'status' => ParticipantStatus::Active->value,
            'confirmed_at' => now(),
        ])->save();

        $this->actingAs($ana)->get('/dashboard')->assertOk();

        $event->refresh();

        $this->assertSame(EventStatus::Closed, $event->status);
        $this->assertNotNull($event->finalized_at);

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/posponer', [
                'exchange_at' => now()->addDays(10)->format('Y-m-d'),
                'reason' => 'No debería permitirse.',
            ])
            ->assertStatus(409);
    }
}
