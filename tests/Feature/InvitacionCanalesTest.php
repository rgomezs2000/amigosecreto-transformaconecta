<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use App\Services\InvitacionesEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GrantsModules;
use Tests\TestCase;

class InvitacionCanalesTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'promedio' => 900.00,
                'fechaActualizacion' => '2026-11-18T00:00:00-04:00',
            ]),
        ]);

        Mail::fake();
        $this->seed();

        $this->admin = User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();
    }

    private function participante(string $name = 'Ana Canales', string $username = 'ana-canales'): User
    {
        return $this->makeUser($name, UserRole::Participant, ['chat', 'lista-de-deseos']);
    }

    private function evento(): Event
    {
        $event = Event::create([
            'name' => 'Evento de canales',
            'year' => (int) now()->year,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(10),
            'max_value' => 100,
        ]);

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

    public function test_cada_canal_envia_solo_lo_suyo_y_rota_el_token(): void
    {
        $ana = $this->participante();
        $event = $this->evento();
        $participacion = $this->participacion($event, $ana);
        $original = $participacion->invitation_token;

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'notify',
            ])
            ->assertOk()
            ->assertJsonPath('channel', 'notify');

        $participacion->refresh();
        $this->assertNotSame($original, $participacion->invitation_token);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ana->id,
            'type' => 'evento.invitacion',
        ]);
        Mail::assertNothingSent();
        $this->assertSame(0, ChatMessage::query()->where('type', 'event')->count());

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'email',
            ])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        Mail::assertSentCount(1);
        $this->assertSame(0, ChatMessage::query()->where('type', 'event')->count());

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'chat',
            ])
            ->assertOk()
            ->assertJsonPath('channel', 'chat');

        $mensaje = ChatMessage::query()->where('type', 'event')->firstOrFail();
        $this->assertSame($event->id, $mensaje->event_id);
        $this->assertSame($participacion->fresh()->invitation_token, $mensaje->event_invitation_token);

        $linkResponse = $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'link',
            ])
            ->assertOk()
            ->assertJsonPath('channel', 'link');

        $participacion->refresh();
        $this->assertNotSame($mensaje->event_invitation_token, $participacion->invitation_token);
        $this->assertStringContainsString($participacion->invitation_token, (string) $linkResponse->json('invitation_url'));
    }

    public function test_la_ficha_del_chat_muestra_estado_acciones_y_lista_de_deseos(): void
    {
        $ana = $this->participante();
        $event = $this->evento();

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'chat',
            ])
            ->assertOk();

        $participacion = $this->participacion($event, $ana);
        $token = $participacion->invitation_token;

        $this->actingAs($ana)
            ->getJson('/chat/eventos/'.$event->id.'?invitation_token='.$token)
            ->assertOk()
            ->assertJsonPath('event.phase', 'Unión')
            ->assertJsonPath('event.participation_status', 'En espera')
            ->assertJsonPath('event.can_respond', true)
            ->assertJsonPath('event.can_wishlist', false);

        $this->actingAs($ana)
            ->postJson('/invitacion/'.$token.'/aceptar')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/eventos/'.$event->id.'?invitation_token='.$token)
            ->assertOk()
            ->assertJsonPath('event.participation_status', 'Activo')
            ->assertJsonPath('event.can_respond', false)
            ->assertJsonPath('event.can_wishlist', true);

        $this->actingAs($this->admin)
            ->getJson('/chat/eventos/'.$event->id)
            ->assertOk()
            ->assertJsonPath('event.is_admin', true)
            ->assertJsonPath('event.can_respond', false)
            ->assertJsonPath('event.can_wishlist', false);
    }

    public function test_una_invitacion_anterior_queda_vencida_al_crear_un_enlace_nuevo(): void
    {
        $ana = $this->participante();
        $event = $this->evento();

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'chat',
            ])
            ->assertOk();

        $mensaje = ChatMessage::query()->where('type', 'event')->firstOrFail();
        $conversation = Conversation::query()->findOrFail($mensaje->conversation_id);

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'link',
            ])
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.type', 'event')
            ->assertJsonPath('messages.0.event.can_respond', false)
            ->assertJsonPath('messages.0.event.invitation_token', null);

        $this->actingAs($this->admin)
            ->getJson('/chat/eventos/'.$event->id.'?invitation_token='.$mensaje->event_invitation_token)
            ->assertOk()
            ->assertJsonPath('event.can_respond', false);
    }

    public function test_no_se_pueden_crear_invitaciones_despues_de_responder(): void
    {
        $ana = $this->participante();
        $event = $this->evento();
        $token = $this->participacion($event, $ana)->invitation_token;

        $this->actingAs($ana)
            ->postJson('/invitacion/'.$token.'/aceptar')
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/eventos/'.$event->id.'/participantes/'.$ana->id.'/invitar', [
                'channel' => 'notify',
            ])
            ->assertStatus(409);

        $this->assertSame(ParticipantStatus::Active, $this->participacion($event, $ana)->status);
    }

    public function test_la_administracion_no_acepta_ni_rechaza_invitaciones(): void
    {
        $event = $this->evento();
        $token = 'token-de-prueba-admin';

        EventParticipant::create([
            'event_id' => $event->id,
            'user_id' => $this->admin->id,
            'status' => ParticipantStatus::Pending,
            'invitation_token' => $token,
            'status_changed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/invitacion/'.$token.'/aceptar')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson('/invitacion/'.$token.'/rechazar', ['reason' => 'No aplica.'])
            ->assertForbidden();
    }
}
