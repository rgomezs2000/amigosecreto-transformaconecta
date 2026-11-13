<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Respuestas citadas y reenvíos del chat.
 *
 * Cubre las dos acciones nuevas del clic derecho sobre un mensaje: responder
 * (con la cita dentro de la burbuja) y reenviar (a varios destinos a la vez),
 * además de las reglas de los chats de solo lectura.
 */
class ChatRespuestasReenviosTest extends TestCase
{
    use GrantsModules;
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

    private function usuario(string $name, UserRole $role = UserRole::Participant): User
    {
        return $this->makeUser($name, $role, ['chat']);
    }

    private function directa(User $first, User $second): Conversation
    {
        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $first->id]);
        $conversation->users()->attach([$first->id, $second->id]);

        return $conversation->load('users');
    }

    private function canalDeEvento(User ...$members): Conversation
    {
        $conversation = Conversation::create([
            'name' => 'Evento: Prueba',
            'type' => 'event',
            'is_read_only' => true,
        ]);

        $conversation->users()->attach(collect($members)->pluck('id')->all());

        return $conversation->load('users');
    }

    public function test_responder_cita_el_mensaje_original_dentro_de_la_burbuja(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $conversation = $this->directa($ana, $luis);

        $original = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Hola Luis',
        ]);

        $this->actingAs($luis)
            ->postJson('/chat/'.$conversation->id.'/mensajes', [
                'body' => 'Hola Ana, ¿cómo estás?',
                'reply_to_id' => $original->id,
            ])
            ->assertOk();

        $reply = ChatMessage::query()->latest('id')->firstOrFail();

        $this->assertSame($original->id, $reply->reply_to_id);
        $this->assertFalse($reply->isForwarded());

        $payload = collect(
            $this->actingAs($ana)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('id', $reply->id);

        $this->assertSame($original->id, $payload['reply']['id']);
        $this->assertSame('Ana', $payload['reply']['sender']);
        $this->assertSame('Hola Luis', $payload['reply']['body']);
        $this->assertFalse($payload['reply']['deleted']);
        $this->assertFalse($payload['forwarded']);
    }

    public function test_no_se_puede_responder_a_un_mensaje_de_otra_conversacion(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $pedro = $this->usuario('Pedro');

        $propia = $this->directa($ana, $luis);
        $ajena = $this->directa($ana, $pedro);

        $ajeno = ChatMessage::create([
            'conversation_id' => $ajena->id,
            'user_id' => $pedro->id,
            'body' => 'Mensaje de otro chat',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$propia->id.'/mensajes', [
                'body' => 'Respuesta cruzada',
                'reply_to_id' => $ajeno->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChatMessage::query()->where('conversation_id', $propia->id)->count());
    }

    public function test_no_se_puede_responder_a_un_mensaje_eliminado(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $conversation = $this->directa($ana, $luis);

        $borrado = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Ya no está',
            'deleted_at' => now(),
            'deleted_by' => $ana->id,
        ]);

        $this->actingAs($luis)
            ->postJson('/chat/'.$conversation->id.'/mensajes', [
                'body' => 'Respuesta a algo borrado',
                'reply_to_id' => $borrado->id,
            ])
            ->assertStatus(422);
    }

    public function test_una_respuesta_a_un_mensaje_vaciado_no_revela_su_texto(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $conversation = $this->directa($ana, $luis);

        $original = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Texto que Luis ya había vaciado',
        ]);

        $original->forceFill(['created_at' => now()->subHour()])->save();

        // Luis vació el chat antes de que llegara la respuesta.
        $conversation->users()->updateExistingPivot($luis->id, ['cleared_at' => now()->subMinutes(5)]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', [
                'body' => 'Respuesta nueva',
                'reply_to_id' => $original->id,
            ])
            ->assertOk();

        $payload = collect(
            $this->actingAs($luis)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('body', 'Respuesta nueva');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['reply']['deleted']);
        $this->assertNull($payload['reply']['id']);
        $this->assertStringNotContainsString('Texto que Luis', (string) $payload['reply']['body']);

        // Ana, que no vació nada, sigue viendo la cita completa.
        $anaPayload = collect(
            $this->actingAs($ana)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('body', 'Respuesta nueva');

        $this->assertSame('Texto que Luis ya había vaciado', $anaPayload['reply']['body']);
    }

    public function test_una_respuesta_notifica_aunque_el_destinatario_haya_silenciado_la_conversacion(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $conversation = $this->directa($ana, $luis);

        $conversation->users()->updateExistingPivot($luis->id, ['muted_at' => now()]);

        // Un mensaje normal sigue respetando el silencio.
        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Mensaje normal'])
            ->assertOk();

        $this->assertSame(0, Notification::query()
            ->where('notifiable_id', $luis->id)
            ->where('type', 'chat.mensaje')
            ->count());

        $original = ChatMessage::query()->latest('id')->firstOrFail();

        // Una respuesta atraviesa el silencio: el remitente queda silenciado,
        // pero la carga de notificación llega igual.
        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', [
                'body' => 'Te respondo',
                'reply_to_id' => $original->id,
            ])
            ->assertOk();

        $notification = Notification::query()
            ->where('notifiable_id', $luis->id)
            ->where('type', 'chat.mensaje')
            ->get()
            ->first(fn (Notification $item) => (bool) ($item->data['is_reply'] ?? false));

        $this->assertNotNull($notification);
        $this->assertSame($original->id, $notification->data['reply_to_id']);
        $this->assertStringContainsString('respondió', $notification->title());
    }

    public function test_reenviar_copia_el_mensaje_a_varias_conversaciones(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $pedro = $this->usuario('Pedro');

        $origen = $this->directa($ana, $luis);
        $otra = $this->directa($ana, $pedro);

        $grupo = Conversation::create(['name' => 'Cumpleaños', 'type' => 'group', 'created_by' => $ana->id]);
        $grupo->users()->attach([$ana->id, $luis->id]);

        $original = ChatMessage::create([
            'conversation_id' => $origen->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje para reenviar',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$original->id.'/reenviar', [
                'conversation_ids' => [$otra->id, $grupo->id],
            ])
            ->assertOk()
            ->assertJsonPath('count', 2);

        foreach ([$otra, $grupo] as $destino) {
            $copia = ChatMessage::query()->where('conversation_id', $destino->id)->firstOrFail();

            $this->assertSame('Mensaje para reenviar', $copia->body);
            $this->assertSame($original->id, $copia->forwarded_from_id);
            $this->assertSame($ana->id, $copia->user_id);
        }

        $payload = collect(
            $this->actingAs($pedro)
                ->getJson('/chat/'.$otra->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('body', 'Mensaje para reenviar');

        $this->assertTrue($payload['forwarded']);

        // El destinatario recibe su aviso de mensaje.
        $this->assertTrue(Notification::query()
            ->where('notifiable_id', $pedro->id)
            ->where('type', 'chat.mensaje')
            ->exists());
    }

    public function test_reenviar_a_una_persona_sin_chat_abre_la_conversacion_directa(): void
    {
        $ana = $this->usuario('Ana');
        $admin = $this->usuario('Admin', UserRole::Admin);

        $origen = $this->directa($ana, $this->usuario('Luis'));

        $original = ChatMessage::create([
            'conversation_id' => $origen->id,
            'user_id' => $ana->id,
            'body' => 'Aviso para la administración',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$original->id.'/reenviar', [
                'user_ids' => [$admin->id],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $directa = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('users', fn ($query) => $query->whereKey($ana->id))
            ->whereHas('users', fn ($query) => $query->whereKey($admin->id))
            ->firstOrFail();

        $this->assertTrue($directa->messages()->where('forwarded_from_id', $original->id)->exists());
        $this->assertTrue(Notification::query()
            ->where('notifiable_id', $admin->id)
            ->where('type', 'chat.mensaje')
            ->exists());
    }

    public function test_se_puede_reenviar_desde_el_chat_de_solo_lectura_pero_no_hacia_el(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $canal = $this->canalDeEvento($ana, $luis);

        $aviso = ChatMessage::create([
            'conversation_id' => $canal->id,
            'user_id' => null,
            'body' => 'El evento empieza a las 8:00',
            'type' => 'text',
            'delivered_at' => now(),
        ]);

        $destino = $this->directa($ana, $luis);

        // Desde el canal (solo lectura) sí se reenvía.
        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$aviso->id.'/reenviar', [
                'conversation_ids' => [$destino->id],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertTrue(
            $destino->messages()->where('body', 'El evento empieza a las 8:00')->whereNotNull('forwarded_from_id')->exists()
        );

        // Hacia el canal no: no recibe mensajes reenviados.
        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$aviso->id.'/reenviar', [
                'conversation_ids' => [$canal->id],
            ])
            ->assertStatus(422);

        // Y tampoco se responde dentro del canal.
        $this->actingAs($ana)
            ->postJson('/chat/'.$canal->id.'/mensajes', [
                'body' => 'Respuesta',
                'reply_to_id' => $aviso->id,
            ])
            ->assertForbidden();
    }

    public function test_no_se_reenvia_a_un_grupo_del_que_no_soy_miembro(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $origen = $this->directa($ana, $luis);

        $ajeno = Conversation::create(['name' => 'Grupo ajeno', 'type' => 'group', 'created_by' => $ana->id]);
        $ajeno->users()->attach([$ana->id]);

        $original = ChatMessage::create([
            'conversation_id' => $origen->id,
            'user_id' => $ana->id,
            'body' => 'No debe llegar',
        ]);

        $this->actingAs($luis)
            ->postJson('/chat/mensajes/'.$original->id.'/reenviar', [
                'conversation_ids' => [$ajeno->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, $ajeno->messages()->count());
    }

    public function test_no_se_reenvia_ni_una_persona_bloqueada(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $pedro = $this->usuario('Pedro');

        $origen = $this->directa($ana, $luis);

        $this->actingAs($ana)
            ->postJson('/chat/usuarios/'.$pedro->id.'/bloquear')
            ->assertOk();

        $original = ChatMessage::create([
            'conversation_id' => $origen->id,
            'user_id' => $ana->id,
            'body' => 'Mensaje',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$original->id.'/reenviar', [
                'user_ids' => [$pedro->id],
            ])
            ->assertStatus(422);
    }

    public function test_reenviar_un_adjunto_duplica_el_archivo_en_el_destino(): void
    {
        Storage::fake('public');

        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $origen = $this->directa($ana, $luis);

        $grupo = Conversation::create(['name' => 'Sala', 'type' => 'group', 'created_by' => $ana->id]);
        $grupo->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$origen->id.'/mensajes', ['file' => UploadedFile::fake()->image('foto.jpg')])
            ->assertOk();

        $original = ChatMessage::query()->latest('id')->firstOrFail();
        $originalPath = $original->attachment_path;

        Storage::disk('public')->assertExists($originalPath);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$original->id.'/reenviar', [
                'conversation_ids' => [$grupo->id],
            ])
            ->assertOk();

        $copia = ChatMessage::query()->where('conversation_id', $grupo->id)->firstOrFail();

        $this->assertNotSame($originalPath, $copia->attachment_path);
        Storage::disk('public')->assertExists($copia->attachment_path);
        Storage::disk('public')->assertExists($originalPath);

        $this->assertSame(1, $copia->attachments()->count());

        // Borrar el reenvío no puede llevarse por delante el adjunto original.
        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$copia->id.'/eliminar')
            ->assertOk();

        Storage::disk('public')->assertMissing($copia->attachment_path);
        Storage::disk('public')->assertExists($originalPath);
    }

    public function test_destinatarios_agrupa_grupos_administradores_amigos_y_personas(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');
        $pedro = $this->usuario('Pedro');
        $admin = $this->usuario('Admin', UserRole::Admin);

        $grupo = Conversation::create(['name' => 'Cumpleaños', 'type' => 'group', 'created_by' => $ana->id]);
        $grupo->users()->attach([$ana->id]);

        $secreto = Conversation::create(['type' => 'secret', 'created_by' => $ana->id]);
        $secreto->users()->attach([$ana->id, $luis->id]);

        $canal = $this->canalDeEvento($ana);

        $response = $this->actingAs($ana)
            ->getJson('/chat/destinatarios')
            ->assertOk();

        $groups = collect($response->json('groups'));
        $admins = collect($response->json('admins'));
        $secrets = collect($response->json('secrets'));
        $users = collect($response->json('users'));

        $this->assertSame([$grupo->id], $groups->pluck('id')->all());
        $this->assertSame('conversation', $groups->first()['target']);

        $this->assertSame([$admin->id], $admins->pluck('id')->all());
        $this->assertSame('user', $admins->first()['target']);

        $this->assertSame([$secreto->id], $secrets->pluck('id')->all());

        // Pedro aparece como persona; el canal de solo lectura no aparece.
        $this->assertTrue($users->pluck('id')->contains($pedro->id));
        $this->assertFalse($groups->pluck('id')->contains($canal->id));
    }

    public function test_el_menu_del_mensaje_y_el_modal_de_reenvio_estan_en_el_widget(): void
    {
        $ana = $this->usuario('Ana');

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-action="reply"', false)
            ->assertSee('data-action="forward"', false)
            ->assertSee('id="chat-reply"', false)
            ->assertSee('id="chat-reply-cancel"', false)
            ->assertSee('id="modal-chat-forward"', false)
            ->assertSee('id="chat-forward-send"', false);
    }
}
