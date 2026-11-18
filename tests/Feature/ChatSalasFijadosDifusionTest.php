<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatBlock;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatSalasFijadosDifusionTest extends TestCase
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

    private function userWithChat(string $name, UserRole $role = UserRole::Participant): User
    {
        $profile = Profile::create([
            'name' => 'Perfil '.$name,
            'slug' => 'perfil-'.strtolower(str_replace(' ', '-', $name)).'-'.Str::lower(Str::random(5)),
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

    private function directConversation(User $first, User $second): Conversation
    {
        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $first->id]);
        $conversation->users()->attach([$first->id, $second->id]);

        return $conversation;
    }

    public function test_una_sala_cerrada_se_crea_con_al_menos_tres_personas(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $pedro = $this->userWithChat('Pedro');

        $response = $this->actingAs($ana)->postJson('/chat/sala', [
            'name' => 'Un nombre que debe ignorarse',
            'user_ids' => [$luis->id, $pedro->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation.is_closed', true)
            ->assertJsonPath('conversation.is_group', true)
            ->assertJsonPath('conversation.name', null)
            ->assertJsonPath('conversation.title', 'Ana, Luis y Pedro');

        $sala = Conversation::query()->findOrFail($response->json('conversation.id'));

        $this->assertTrue($sala->isClosed());
        $this->assertSame('group', $sala->type);
        $this->assertNull($sala->name);
        $this->assertCount(3, $sala->fresh('users')->users);

        // Con una sola persona no llega a tres contando al creador.
        $this->actingAs($ana)->postJson('/chat/sala', [
            'user_ids' => [$luis->id],
        ])->assertStatus(422);

        // Una sala cerrada no genera invitaciones ni vetos.
        $this->actingAs($ana)
            ->postJson('/chat/grupo/'.$sala->id.'/invitar', [])
            ->assertStatus(422);

        $this->actingAs($ana)
            ->postJson('/chat/grupo/'.$sala->id.'/banear/'.$pedro->id, [])
            ->assertStatus(422);
    }

    public function test_solo_los_miembros_entran_a_la_sala_y_el_dueno_gestiona_sus_usuarios(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $pedro = $this->userWithChat('Pedro');
        $sofia = $this->userWithChat('Sofía');
        $juan = $this->userWithChat('Juan');

        $sala = Conversation::create([
            'type' => 'group',
            'created_by' => $ana->id,
            'is_closed' => true,
        ]);
        $sala->users()->attach([
            $ana->id => ['role' => 'moderator'],
            $luis->id => ['role' => 'member'],
            $pedro->id => ['role' => 'member'],
        ]);

        $this->actingAs($ana)
            ->getJson('/chat/'.$sala->id.'/mensajes')
            ->assertOk();

        $this->actingAs($juan)
            ->getJson('/chat/'.$sala->id.'/mensajes')
            ->assertForbidden();

        // El dueño agrega a otra persona; un miembro normal no puede.
        $this->actingAs($ana)
            ->postJson('/chat/sala/'.$sala->id.'/miembros', ['user_id' => $sofia->id])
            ->assertOk()
            ->assertJsonPath('added', true);

        $this->assertTrue($sala->fresh('users')->users->contains('id', $sofia->id));

        $this->actingAs($luis)
            ->postJson('/chat/sala/'.$sala->id.'/miembros', ['user_id' => $juan->id])
            ->assertForbidden();

        // El dueño puede quitar a un miembro.
        $this->actingAs($ana)
            ->postJson('/chat/'.$sala->id.'/expulsar/'.$sofia->id)
            ->assertOk();

        $this->assertFalse($sala->fresh('users')->users->contains('id', $sofia->id));
    }

    public function test_solo_hay_un_mensaje_fijado_por_chat(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $conversation = $this->directConversation($ana, $luis);

        $primero = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Primer mensaje',
        ]);
        $segundo = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Segundo mensaje',
        ]);
        $tercero = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Tercer mensaje',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$primero->id)
            ->assertOk()
            ->assertJsonPath('pinned_message.id', $primero->id);

        // Al fijar otro, el anterior se suelta.
        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$segundo->id)
            ->assertOk()
            ->assertJsonPath('pinned_message.id', $segundo->id);

        $this->assertNull($primero->fresh()->pinned_at);
        $this->assertNotNull($segundo->fresh()->pinned_at);
        $this->assertSame(1, $conversation->messages()->whereNotNull('pinned_at')->count());

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$tercero->id)
            ->assertOk()
            ->assertJsonPath('pinned_message.id', $tercero->id);

        $historial = $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->json('messages');

        $pines = collect($historial)->where('pinned', true)->pluck('id')->values()->all();

        $this->assertSame([$tercero->id], $pines);

        // Desfijar deja el chat sin mensaje fijado.
        $this->actingAs($ana)
            ->deleteJson('/chat/'.$conversation->id.'/mensaje-fijado/'.$tercero->id)
            ->assertOk()
            ->assertJsonPath('pinned_message', null);

        $this->assertNull($conversation->fresh()->pinned_message_id);

        // La ruta sin mensaje también desfija el único fijado.
        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$primero->id)
            ->assertOk();

        $this->actingAs($ana)
            ->deleteJson('/chat/'.$conversation->id.'/mensaje-fijado')
            ->assertOk()
            ->assertJsonPath('pinned_message', null);
    }

    public function test_eliminar_el_mensaje_fijado_deja_el_chat_sin_fijado(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $conversation = $this->directConversation($ana, $luis);

        $primero = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Fijado uno',
        ]);
        $segundo = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Fijado dos',
        ]);

        $this->actingAs($ana)->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$primero->id)->assertOk();
        $this->actingAs($ana)->postJson('/chat/'.$conversation->id.'/fijar-mensaje/'.$segundo->id)->assertOk();

        $this->actingAs($luis)
            ->postJson('/chat/mensajes/'.$segundo->id.'/eliminar', [])
            ->assertOk();

        $this->assertNull($conversation->fresh()->pinned_message_id);
        $this->assertNull($segundo->fresh()->pinned_at);
        $this->assertNull($primero->fresh()->pinned_at);
    }

    public function test_el_favorito_de_un_mensaje_es_personal(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $conversation = $this->directConversation($ana, $luis);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje para guardar',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$message->id.'/favorito', ['favorite' => 1])
            ->assertOk()
            ->assertJsonPath('favorite', true);

        $this->assertSame(1, DB::table('chat_message_favorites')
            ->where('message_id', $message->id)
            ->where('user_id', $ana->id)
            ->count());

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.favorite', true);

        // Luis ve el mismo mensaje, pero no su marca personal.
        $this->actingAs($luis)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.favorite', false);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$message->id.'/favorito', ['favorite' => 0])
            ->assertOk()
            ->assertJsonPath('favorite', false);

        $this->assertSame(0, DB::table('chat_message_favorites')
            ->where('message_id', $message->id)
            ->count());
    }

    public function test_la_sala_de_difusion_se_crea_con_destinatarios_sin_agregarlos_al_chat(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $pedro = $this->userWithChat('Pedro');

        $response = $this->actingAs($ana)->postJson('/chat/broadcast', [
            'user_ids' => [$luis->id, $pedro->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation.type', 'broadcast')
            ->assertJsonPath('conversation.is_broadcast', true)
            ->assertJsonPath('conversation.title', 'Luis y Pedro')
            ->assertJsonPath('conversation.recipients_count', 2);

        $broadcast = Conversation::query()->findOrFail($response->json('conversation.id'));

        $this->assertSame('broadcast', $broadcast->type);
        $this->assertSame($ana->id, $broadcast->created_by);
        $this->assertCount(1, $broadcast->fresh('users')->users);
        $this->assertSame($ana->id, $broadcast->users->first()->id);

        $this->assertSame(2, DB::table('chat_broadcast_recipients')
            ->where('conversation_id', $broadcast->id)
            ->count());

        // Los destinatarios no entran a la sala de difusión.
        $this->actingAs($luis)
            ->getJson('/chat/'.$broadcast->id.'/mensajes')
            ->assertForbidden();

        // La difusión es privada de su dueño, incluso para la administración.
        $admin = $this->userWithChat('Admin', UserRole::Admin);

        $this->actingAs($admin)
            ->getJson('/chat/'.$broadcast->id.'/mensajes')
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson('/chat/'.$broadcast->id)
            ->assertForbidden();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('broadcasts.0.id', $broadcast->id);
    }

    public function test_al_enviar_en_la_difusion_se_replica_en_los_chats_directos(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $pedro = $this->userWithChat('Pedro');

        $created = $this->actingAs($ana)->postJson('/chat/broadcast', [
            'user_ids' => [$luis->id, $pedro->id],
        ])->assertOk();

        $broadcast = Conversation::query()->findOrFail($created->json('conversation.id'));

        $this->actingAs($ana)
            ->postJson('/chat/'.$broadcast->id.'/mensajes', ['body' => 'Aviso para todos'])
            ->assertOk();

        $roomMessage = $broadcast->messages()->latest('id')->first();

        $this->assertSame('Aviso para todos', $roomMessage->body);
        $this->assertTrue($roomMessage->is_broadcast);

        foreach ([$luis, $pedro] as $recipient) {
            $direct = Conversation::query()
                ->where('type', 'direct')
                ->whereHas('users', fn ($query) => $query->whereKey($ana->id))
                ->whereHas('users', fn ($query) => $query->whereKey($recipient->id))
                ->first();

            $this->assertNotNull($direct, 'No se creó el chat con '.$recipient->name);

            $copy = $direct->messages()->latest('id')->first();

            $this->assertSame('Aviso para todos', $copy->body);
            $this->assertSame($ana->id, $copy->user_id);
            $this->assertTrue($copy->is_broadcast);

            // El destinatario ve el mensaje en su chat individual, no en la sala.
            $this->actingAs($recipient)
                ->getJson('/chat/'.$direct->id.'/mensajes')
                ->assertOk()
                ->assertJsonPath('messages.0.body', 'Aviso para todos')
                ->assertJsonPath('messages.0.broadcast', true);

            $this->assertFalse($broadcast->fresh('users')->users->contains('id', $recipient->id));
        }
    }

    public function test_la_difusion_no_incluye_a_una_persona_bloqueada(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');

        ChatBlock::create([
            'blocker_id' => $luis->id,
            'blocked_id' => $ana->id,
        ]);

        $this->actingAs($ana)->postJson('/chat/broadcast', [
            'user_ids' => [$luis->id],
        ])->assertStatus(422);

        $this->assertSame(0, Conversation::query()->where('type', 'broadcast')->count());
    }

    public function test_el_widget_incluye_salas_fijado_unico_difusion_y_seleccion(): void
    {
        $ana = $this->userWithChat('Ana');

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-tab="rooms"', false)
            ->assertSee('id="chat-rooms"', false)
            ->assertSee('id="chat-room-open"', false)
            ->assertSee('id="modal-chat-room"', false)
            ->assertSee('Crear sala')
            ->assertSee('data-tab="broadcasts"', false)
            ->assertSee('id="chat-broadcasts"', false)
            ->assertSee('id="chat-broadcast-new"', false)
            ->assertSee('id="chat-broadcast-open"', false)
            ->assertSee('id="modal-chat-broadcast"', false)
            ->assertSee('id="chat-selection"', false)
            ->assertSee('data-selection-action="reply"', false)
            ->assertSee('data-selection-action="favorite"', false)
            ->assertSee('data-selection-action="pin"', false)
            ->assertSee('data-selection-action="delete"', false)
            ->assertSee('data-more-action="select"', false)
            ->assertDontSee('id="modal-chat-pins"', false)
            ->assertDontSee('id="chat-pin-all"', false)
            ->assertDontSee('data-more-action="pins"', false);
    }
}
