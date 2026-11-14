<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatPollVote;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatPollEventTest extends TestCase
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

    private function userWithChat(string $name, UserRole $role): User
    {
        $profile = Profile::create([
            'name' => 'Perfil '.$name,
            'slug' => 'perfil-'.strtolower(str_replace(' ', '-', $name)),
            'is_active' => true,
        ]);

        $module = Module::query()->firstOrCreate(
            ['slug' => 'chat'],
            [
                'name' => 'Chat',
                'group' => 'Mensajes',
                'icon' => 'fa-comments',
                'order' => 10,
                'is_active' => true,
            ]
        );

        $permission = Permission::query()->firstOrCreate(['slug' => 'view'], ['name' => 'Ver']);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        return User::factory()->create([
            'name' => $name,
            'username' => strtolower(str_replace(' ', '-', $name)).'-'.Str::lower(Str::random(5)),
            'profile_id' => $profile->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function directChat(): array
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        return [$ana, $luis, $conversation];
    }

    private function createPoll(User $author, Conversation $conversation, array $overrides = []): ChatPoll
    {
        $response = $this->actingAs($author)->postJson('/chat/'.$conversation->id.'/encuestas', array_merge([
            'question' => '¿Qué hacemos el sábado?',
            'options' => ['Cine', 'Cena', 'Juegos'],
            'multiple' => false,
            'hide_voters' => false,
        ], $overrides));

        $response->assertOk();

        return ChatPoll::query()->latest('id')->firstOrFail();
    }

    public function test_la_encuesta_exige_pregunta_y_dos_respuestas_rellenas(): void
    {
        [$ana, , $conversation] = $this->directChat();

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/encuestas', [
                'question' => '',
                'options' => ['Cine', 'Cena'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escribe la pregunta de la encuesta.');

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/encuestas', [
                'question' => '¿Qué hacemos?',
                'options' => ['Cine'],
            ])
            ->assertStatus(422);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/encuestas', [
                'question' => '¿Qué hacemos?',
                'options' => ['Cine', '   '],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Las respuestas no pueden estar en blanco.');

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/encuestas', [
                'question' => '¿Qué hacemos?',
                'options' => ['Cine', 'cine'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Las respuestas deben ser diferentes entre sí.');

        $this->assertSame(0, ChatMessage::query()->where('type', 'poll')->count());
    }

    public function test_crea_la_encuesta_dentro_de_un_mensaje_y_dura_24_horas(): void
    {
        [$ana, , $conversation] = $this->directChat();

        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));

        $poll = $this->createPoll($ana, $conversation);

        $this->assertSame('¿Qué hacemos el sábado?', $poll->question);
        $this->assertFalse($poll->multiple);
        $this->assertFalse($poll->hide_voters);
        $this->assertSame('2026-11-21 10:00:00', $poll->ends_at?->format('Y-m-d H:i:s'));
        $this->assertSame(3, $poll->options()->count());
        $this->assertSame('Cine', $poll->options()->orderBy('position')->first()->label);

        $message = ChatMessage::query()->findOrFail($poll->message_id);
        $this->assertSame('poll', $message->type);
        $this->assertSame($conversation->id, $message->conversation_id);

        // El historial trae la encuesta lista para pintarse.
        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.poll.question', '¿Qué hacemos el sábado?')
            ->assertJsonPath('messages.0.poll.can_vote', true)
            ->assertJsonPath('messages.0.poll.total_votes', 0);
    }

    public function test_acepta_fecha_de_finalizacion_personalizada(): void
    {
        [$ana, , $conversation] = $this->directChat();

        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));

        $poll = $this->createPoll($ana, $conversation, [
            'ends_at' => '2026-11-25 18:30:00',
        ]);

        $this->assertSame('2026-11-25 18:30:00', $poll->ends_at?->format('Y-m-d H:i:s'));
    }

    public function test_voto_unico_reemplaza_la_respuesta_y_calcula_porcentajes(): void
    {
        [$ana, $luis, $conversation] = $this->directChat();

        $poll = $this->createPoll($ana, $conversation);
        $options = $poll->options()->orderBy('position')->get();

        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$options[0]->id]])
            ->assertOk()
            ->assertJsonPath('poll.total_votes', 1)
            ->assertJsonPath('poll.options.0.votes', 1)
            ->assertJsonPath('poll.options.0.percent', 100)
            ->assertJsonPath('poll.options.1.percent', 0);

        $this->actingAs($luis)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$options[1]->id]])
            ->assertOk()
            ->assertJsonPath('poll.total_votes', 2)
            ->assertJsonPath('poll.options.0.percent', 50)
            ->assertJsonPath('poll.options.1.percent', 50);

        // Ana cambia su voto: solo puede tener uno.
        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$options[1]->id]])
            ->assertOk()
            ->assertJsonPath('poll.total_votes', 2)
            ->assertJsonPath('poll.options.0.percent', 0)
            ->assertJsonPath('poll.options.1.percent', 100);

        $this->assertSame(2, ChatPollVote::query()->where('poll_id', $poll->id)->count());
    }

    public function test_voto_unico_rechaza_varias_respuestas(): void
    {
        [$ana, , $conversation] = $this->directChat();

        $poll = $this->createPoll($ana, $conversation);
        $ids = $poll->options()->pluck('id')->all();

        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$ids[0], $ids[1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta encuesta admite una sola respuesta.');
    }

    public function test_encuesta_multiple_permite_varias_respuestas_y_las_reemplaza(): void
    {
        [$ana, $luis, $conversation] = $this->directChat();

        $poll = $this->createPoll($ana, $conversation, ['multiple' => true]);
        $ids = $poll->options()->orderBy('position')->pluck('id')->all();

        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$ids[0], $ids[2]]])
            ->assertOk()
            ->assertJsonPath('poll.total_votes', 1)
            ->assertJsonPath('poll.options.0.mine', true)
            ->assertJsonPath('poll.options.2.mine', true)
            ->assertJsonPath('poll.options.1.mine', false);

        $this->actingAs($luis)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$ids[0]]])
            ->assertOk()
            ->assertJsonPath('poll.total_votes', 2);

        // Reemplaza la selección completa.
        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$ids[1]]])
            ->assertOk()
            ->assertJsonPath('poll.options.1.mine', true)
            ->assertJsonPath('poll.options.0.mine', false)
            ->assertJsonPath('poll.options.0.votes', 1);

        $this->assertSame(2, ChatPollVote::query()->where('poll_id', $poll->id)->count());
    }

    public function test_los_votantes_se_ocultan_cuando_la_encuesta_lo_pide(): void
    {
        [$ana, $luis, $conversation] = $this->directChat();

        $visible = $this->createPoll($ana, $conversation);
        $ids = $visible->options()->orderBy('position')->pluck('id')->all();

        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$visible->id.'/votar', ['options' => [$ids[0]]])
            ->assertOk()
            ->assertJsonPath('poll.options.0.voters.0', 'Ana');

        $hidden = $this->createPoll($ana, $conversation, [
            'question' => '¿Encuesta secreta?',
            'hide_voters' => true,
        ]);
        $hiddenIds = $hidden->options()->orderBy('position')->pluck('id')->all();

        $this->actingAs($luis)
            ->postJson('/chat/encuestas/'.$hidden->id.'/votar', ['options' => [$hiddenIds[0]]])
            ->assertOk()
            ->assertJsonPath('poll.hide_voters', true)
            ->assertJsonPath('poll.options.0.voters', []);
    }

    public function test_una_encuesta_cerrada_no_admite_votos(): void
    {
        [$ana, , $conversation] = $this->directChat();

        $poll = $this->createPoll($ana, $conversation);
        $poll->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->actingAs($ana)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$poll->options()->first()->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La encuesta ya está cerrada.');

        // Aunque esté cerrada, el historial cuenta lo que había.
        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.poll.closed', true)
            ->assertJsonPath('messages.0.poll.can_vote', false);
    }

    public function test_la_moderacion_no_crea_encuestas_ni_vota(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        [$ana, $luis, $conversation] = $this->directChat();

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/encuestas', [
                'question' => '¿Moderación?',
                'options' => ['Sí', 'No'],
            ])
            ->assertStatus(403);

        $poll = $this->createPoll($ana, $conversation);

        $this->actingAs($admin)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$poll->options()->first()->id]])
            ->assertStatus(403);

        // El historial moderado no ofrece votar.
        $this->actingAs($admin)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.poll.can_vote', false);
    }

    public function test_las_encuestas_no_aplican_en_el_amigo_secreto(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $secret = Conversation::create(['type' => 'secret', 'created_by' => $ana->id]);
        $secret->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$secret->id.'/encuestas', [
                'question' => '¿Amigo secreto?',
                'options' => ['Sí', 'No'],
            ])
            ->assertStatus(422);
    }

    public function test_adjunta_un_evento_y_cualquiera_puede_ver_su_ficha(): void
    {
        [$ana, $luis, $conversation] = $this->directChat();

        $event = Event::create([
            'name' => 'Amigo secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(20),
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/eventos', ['event_id' => $event->id])
            ->assertOk()
            ->assertJsonPath('message.type', 'event')
            ->assertJsonPath('message.event.name', 'Amigo secreto 2026')
            ->assertJsonPath('message.event.status', 'Inscripción');

        $message = ChatMessage::query()->where('type', 'event')->firstOrFail();
        $this->assertSame($event->id, $message->event_id);

        // Cualquier participante (y también quien llegue después) ve la ficha.
        $this->actingAs($luis)
            ->getJson('/chat/eventos/'.$event->id)
            ->assertOk()
            ->assertJsonPath('event.name', 'Amigo secreto 2026')
            ->assertJsonPath('event.year', 2026);

        $this->actingAs($luis)
            ->getJson('/chat/eventos')
            ->assertOk()
            ->assertJsonPath('events.0.id', $event->id);
    }

    public function test_el_historial_incluye_el_evento_adjunto(): void
    {
        [$ana, , $conversation] = $this->directChat();

        $event = Event::create([
            'name' => 'Evento borrador',
            'year' => 2027,
            'status' => EventStatus::Draft,
            'exchange_at' => now()->addDays(30),
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/eventos', ['event_id' => $event->id])
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.type', 'event')
            ->assertJsonPath('messages.0.event.name', 'Evento borrador')
            ->assertJsonPath('messages.0.event.status', 'Borrador');
    }

    public function test_eliminar_el_mensaje_elimina_tambien_la_encuesta(): void
    {
        [$ana, $luis, $conversation] = $this->directChat();

        $poll = $this->createPoll($ana, $conversation);
        $option = $poll->options()->firstOrFail();

        $this->actingAs($luis)
            ->postJson('/chat/encuestas/'.$poll->id.'/votar', ['options' => [$option->id]])
            ->assertOk();

        $this->assertSame(1, ChatPollVote::query()->where('poll_id', $poll->id)->count());

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$poll->message_id.'/eliminar')
            ->assertOk();

        // La encuesta vive dentro del mensaje: se va con él, con sus opciones y votos.
        $this->assertDatabaseMissing('chat_polls', ['id' => $poll->id]);
        $this->assertDatabaseMissing('chat_poll_options', ['poll_id' => $poll->id]);
        $this->assertDatabaseMissing('chat_poll_votes', ['poll_id' => $poll->id]);

        $message = ChatMessage::query()->findOrFail($poll->message_id);
        $this->assertNotNull($message->deleted_at);

        $this->actingAs($luis)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.deleted', true)
            ->assertJsonPath('messages.0.poll', null);
    }

    public function test_eliminar_el_mensaje_con_evento_no_borra_el_evento(): void
    {
        [$ana, , $conversation] = $this->directChat();

        $event = Event::create([
            'name' => 'Evento que debe seguir',
            'year' => 2026,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(15),
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/eventos', ['event_id' => $event->id])
            ->assertOk();

        $message = ChatMessage::query()->where('type', 'event')->firstOrFail();

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$message->id.'/eliminar')
            ->assertOk();

        // El evento sigue existiendo: solo se quitó la ficha de la burbuja.
        $this->assertDatabaseHas('events', ['id' => $event->id]);

        $message->refresh();
        $this->assertNull($message->event_id);
        $this->assertNotNull($message->deleted_at);

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.deleted', true)
            ->assertJsonPath('messages.0.event', null);

        // La ficha del evento se sigue pudiendo abrir desde el chat.
        $this->actingAs($ana)
            ->getJson('/chat/eventos/'.$event->id)
            ->assertOk()
            ->assertJsonPath('event.name', 'Evento que debe seguir');
    }

    public function test_la_moderacion_no_adjunta_eventos(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        [, , $conversation] = $this->directChat();

        $event = Event::create([
            'name' => 'Solo lectura',
            'year' => 2027,
            'status' => EventStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/eventos', ['event_id' => $event->id])
            ->assertStatus(403);
    }
}
