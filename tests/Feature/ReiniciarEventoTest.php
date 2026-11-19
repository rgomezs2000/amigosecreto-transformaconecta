<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «evento:reiniciar» limpia únicamente el flujo principal del evento.
 *
 * No debe tocar usuarios, reportes de moderación ni chats generales.
 */
class ReiniciarEventoTest extends TestCase
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
                'promedio' => 900.00,
                'fechaActualizacion' => '2026-11-17T00:00:00-04:00',
            ]),
        ]);

        Storage::fake('public');
    }

    private function participante(string $name, string $slug): User
    {
        return User::factory()->create([
            'name' => $name,
            'username' => $slug,
            'email' => $slug.'@example.com',
            'profile_id' => Profile::query()->where('slug', 'usuario')->value('id'),
        ]);
    }

    public function test_reinicia_solo_el_flujo_del_evento_y_conserva_usuarios_reportes_y_chats_generales(): void
    {
        $this->seed();

        $ana = $this->participante('Ana Pérez', 'ana');
        $luis = $this->participante('Luis Gómez', 'luis');

        $event = Event::create([
            'name' => 'Evento de prueba',
            'year' => (int) now()->year,
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->addDays(10),
            'max_value' => 100,
        ]);

        $participacionAna = DB::table('event_participants')->insertGetId([
            'event_id' => $event->id,
            'user_id' => $ana->id,
            'status' => ParticipantStatus::Active->value,
            'confirmed_at' => now(),
            'invitation_token' => Str::random(64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('event_participants')->insert([
            'event_id' => $event->id,
            'user_id' => $luis->id,
            'status' => ParticipantStatus::Active->value,
            'confirmed_at' => now(),
            'invitation_token' => Str::random(64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = DB::table('assignments')->insertGetId([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $luis->id,
            'drawn_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clues')->insert([
            'assignment_id' => $assignmentId,
            'body' => 'Pista del evento',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exchange_gifts')->insert([
            'assignment_id' => $assignmentId,
            'product' => 'Regalo',
            'description' => 'Detalle',
            'value' => 50,
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('consumable_gifts')->insert([
            'assignment_id' => $assignmentId,
            'name' => 'Chocolate',
            'description' => 'Oscuro',
            'quantity' => 1,
            'value' => 10,
            'given_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wishlists')->insert([
            'event_participant_id' => $participacionAna,
            'likes' => 'Antiguo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wish_items')->insert([
            'event_id' => $event->id,
            'user_id' => $ana->id,
            'title' => 'Deseo del evento',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secretConversation = DB::table('chat_conversations')->insertGetId([
            'type' => 'secret',
            'event_id' => $event->id,
            'assignment_id' => $assignmentId,
            'created_by' => $luis->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventConversation = DB::table('chat_conversations')->insertGetId([
            'name' => 'Evento: '.$event->name,
            'type' => 'event',
            'event_id' => $event->id,
            'is_read_only' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $systemConversation = DB::table('chat_conversations')->insertGetId([
            'name' => 'Sistema · '.$event->name,
            'type' => 'system',
            'event_id' => $event->id,
            'is_read_only' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$secretConversation, $eventConversation, $systemConversation] as $conversationId) {
            DB::table('chat_conversation_user')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $ana->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('chat_conversation_user')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $luis->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $eventMessage = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $eventConversation,
            'user_id' => $ana->id,
            'body' => 'Mensaje del evento',
            'type' => 'text',
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $systemMessage = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $systemConversation,
            'user_id' => null,
            'body' => 'Aviso del sistema',
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_message_attachments')->insert([
            'message_id' => $eventMessage,
            'type' => 'image',
            'path' => 'chat/eventos/evento.png',
            'name' => 'evento.png',
            'mime' => 'image/png',
            'size' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('public')->put('chat/eventos/evento.png', 'imagen');

        DB::table('chat_message_favorites')->insert([
            'message_id' => $eventMessage,
            'user_id' => $ana->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pollId = DB::table('chat_polls')->insertGetId([
            'message_id' => $eventMessage,
            'conversation_id' => $eventConversation,
            'created_by' => $ana->id,
            'question' => '¿Regalo?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $optionId = DB::table('chat_poll_options')->insertGetId([
            'poll_id' => $pollId,
            'label' => 'Sí',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_poll_votes')->insert([
            'poll_id' => $pollId,
            'option_id' => $optionId,
            'user_id' => $luis->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_broadcast_recipients')->insert([
            'conversation_id' => $eventConversation,
            'user_id' => $luis->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Conversación y mensaje generales, ajenos al evento.
        $generalConversation = DB::table('chat_conversations')->insertGetId([
            'type' => 'direct',
            'created_by' => $ana->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_conversation_user')->insert([
            'conversation_id' => $generalConversation,
            'user_id' => $ana->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_conversation_user')->insert([
            'conversation_id' => $generalConversation,
            'user_id' => $luis->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $generalMessage = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $generalConversation,
            'user_id' => $ana->id,
            'body' => 'Mensaje general',
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reportes: uno del mensaje del evento (se conserva sin mensaje) y otro
        // de un mensaje general (se conserva intacto).
        $eventReport = DB::table('chat_reports')->insertGetId([
            'message_id' => $eventMessage,
            'reporter_id' => $luis->id,
            'reason' => 'Reporte del evento',
            'status' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $generalReport = DB::table('chat_reports')->insertGetId([
            'message_id' => $generalMessage,
            'reporter_id' => $luis->id,
            'reason' => 'Reporte general',
            'status' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_report_attachments')->insert([
            'report_id' => $eventReport,
            'kind' => 'image',
            'path' => 'reportes/evento.png',
            'name' => 'evento.png',
            'mime' => 'image/png',
            'size' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'evento.invitacion',
            'notifiable_type' => User::class,
            'notifiable_id' => $ana->id,
            'data' => json_encode(['event_id' => $event->id, 'title' => 'Evento']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'chat.mensaje',
            'notifiable_type' => User::class,
            'notifiable_id' => $ana->id,
            'data' => json_encode([
                'conversation_id' => $eventConversation,
                'message_id' => $eventMessage,
                'title' => 'Mensaje del evento',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'general',
            'notifiable_type' => User::class,
            'notifiable_id' => $ana->id,
            'data' => json_encode(['title' => 'Aviso general']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'event_id' => $event->id,
            'user_id' => $ana->id,
            'action' => 'event.created',
        ]);

        DB::table('session_kicks')->insert([
            'session_id' => 'sesion-general',
            'user_id' => $ana->id,
            'message' => 'Aviso',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('evento:reiniciar', ['year' => (int) $event->year])
            ->expectsOutputToContain('Reinicio completado')
            ->assertSuccessful();

        // Fuera el flujo principal.
        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->assertSoftDeleted('event_participants', ['event_id' => $event->id]);
        $this->assertSoftDeleted('assignments', ['event_id' => $event->id]);
        $this->assertSoftDeleted('clues', ['assignment_id' => $assignmentId]);
        $this->assertSoftDeleted('exchange_gifts', ['assignment_id' => $assignmentId]);
        $this->assertSoftDeleted('consumable_gifts', ['assignment_id' => $assignmentId]);
        $this->assertSoftDeleted('wishlists', ['event_participant_id' => $participacionAna]);
        $this->assertSoftDeleted('wish_items', ['event_id' => $event->id]);
        $this->assertSoftDeleted('chat_conversations', ['event_id' => $event->id]);
        $this->assertSoftDeleted('chat_messages', ['id' => $eventMessage]);
        $this->assertSoftDeleted('chat_messages', ['id' => $systemMessage]);
        $this->assertDatabaseMissing('chat_polls', ['id' => $pollId]);
        $this->assertDatabaseCount('chat_poll_options', 0);
        $this->assertDatabaseCount('chat_poll_votes', 0);
        $this->assertDatabaseMissing('chat_message_favorites', ['message_id' => $eventMessage]);
        $this->assertSoftDeleted('chat_message_attachments', ['message_id' => $eventMessage]);
        $this->assertDatabaseMissing('chat_broadcast_recipients', ['conversation_id' => $eventConversation]);
        $this->assertDatabaseMissing('notifications', ['type' => 'evento.invitacion']);
        $this->assertDatabaseMissing('notifications', ['type' => 'chat.mensaje']);
        $this->assertDatabaseMissing('audit_logs', ['event_id' => $event->id]);
        Storage::disk('public')->assertMissing('chat/eventos/evento.png');

        // Lo ajeno al evento se conserva.
        $this->assertDatabaseHas('users', ['id' => $ana->id]);
        $this->assertDatabaseHas('users', ['id' => $luis->id]);
        $this->assertDatabaseHas('chat_conversations', ['id' => $generalConversation]);
        $this->assertDatabaseHas('chat_messages', ['id' => $generalMessage]);
        $this->assertDatabaseHas('chat_reports', [
            'id' => $eventReport,
            'message_id' => null,
            'reason' => 'Reporte del evento',
        ]);
        $this->assertDatabaseHas('chat_reports', [
            'id' => $generalReport,
            'message_id' => $generalMessage,
            'reason' => 'Reporte general',
        ]);
        $this->assertDatabaseHas('chat_report_attachments', ['report_id' => $eventReport]);
        $this->assertDatabaseHas('notifications', ['type' => 'general']);
        $this->assertDatabaseHas('session_kicks', ['session_id' => 'sesion-general']);

        // El evento anterior queda con borrado lógico y se puede crear otro
        // para el mismo año.
        $nuevo = Event::create([
            'name' => 'Evento nuevo',
            'year' => (int) $event->year,
            'status' => EventStatus::Draft,
        ]);

        $this->assertNotSame($event->id, $nuevo->id);
    }

    public function test_no_reinicia_un_evento_ya_finalizado(): void
    {
        $this->seed();

        $event = Event::create([
            'name' => 'Evento finalizado',
            'year' => (int) now()->year,
            'status' => EventStatus::Closed,
            'exchange_at' => now()->subDay(),
            'max_value' => 100,
        ]);

        $this->artisan('evento:reiniciar', ['year' => (int) $event->year])
            ->expectsOutputToContain('no se puede reiniciar')
            ->assertExitCode(1);

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }
}


