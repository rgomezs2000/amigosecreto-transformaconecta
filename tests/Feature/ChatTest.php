<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\Module;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTest extends TestCase
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
            'name' => 'Perfil ' . $name,
            'slug' => 'perfil-' . strtolower(str_replace(' ', '-', $name)),
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
            'username' => strtolower(str_replace(' ', '-', $name)) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5)),
            'profile_id' => $profile->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_el_administrador_puede_moderar_conversaciones_ajenas(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('moderation.0.id', $conversation->id);

        $this->actingAs($admin)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => 'Mensaje de moderación'])
            ->assertOk();

        $message = ChatMessage::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertTrue($message->is_moderation);
        $this->assertSame($admin->id, $message->user_id);
    }

    public function test_el_administrador_ve_grupos_sin_participar(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $group = Conversation::create([
            'name' => 'Grupo Ana y Luis',
            'type' => 'group',
            'created_by' => $ana->id,
        ]);
        $group->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('moderation.0.id', $group->id);

        $this->actingAs($admin)
            ->getJson('/chat/' . $group->id . '/mensajes')
            ->assertOk();
    }

    public function test_editar_eliminar_y_reaccionar(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Hola',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/' . $message->id . '/editar', ['body' => 'Hola editado'])
            ->assertOk();

        $message->refresh();

        $this->assertSame('Hola editado', $message->body);
        $this->assertNotNull($message->edited_at);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/' . $message->id . '/reaccionar', ['emoji' => '👍'])
            ->assertOk();

        $this->actingAs($luis)
            ->postJson('/chat/mensajes/' . $message->id . '/reaccionar', ['emoji' => '👍'])
            ->assertOk();

        $this->assertSame(2, $message->reactions()->count());

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/' . $message->id . '/reaccionar', ['emoji' => '❤️'])
            ->assertOk();

        $this->assertSame(2, $message->reactions()->count());
        $this->assertSame('❤️', $message->reactions()->where('user_id', $ana->id)->value('emoji'));

        $this->actingAs($admin)
            ->postJson('/chat/mensajes/' . $message->id . '/eliminar')
            ->assertOk();

        $message->refresh();

        $this->assertNotNull($message->deleted_at);
        $this->assertTrue($message->deleted_by_admin);
        $this->assertSame($admin->id, $message->deleted_by);
    }

    public function test_adjunto_se_guarda_en_carpeta_de_la_conversacion(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('foto.jpg');

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['file' => $file])
            ->assertOk();

        $message = ChatMessage::query()->latest('id')->firstOrFail();

        $this->assertStringStartsWith(
            'chat/conversations/' . $conversation->id . '/image/',
            $message->attachment_path
        );

        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($message->attachment_path);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $admin->id]);
        $group->users()->attach([$admin->id, $ana->id]);

        $this->actingAs($admin)
            ->postJson('/chat/' . $group->id . '/mensajes', ['file' => \Illuminate\Http\UploadedFile::fake()->image('grupo.jpg')])
            ->assertOk();

        $groupMessage = ChatMessage::query()->latest('id')->firstOrFail();

        $this->assertStringStartsWith('chat/groups/' . $group->id . '/image/', $groupMessage->attachment_path);
    }

    public function test_mensajes_con_lectura_registrada_no_falla(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Mensaje con lectura',
        ]);

        $conversation->users()->updateExistingPivot($luis->id, ['last_read_at' => now()]);

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk()
            ->assertJsonPath('messages.0.read', true);
    }

    public function test_reportar_mensaje_crea_reporte_y_avisa_al_administrador(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje ofensivo',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/' . $message->id . '/reportar', ['reason' => 'Lenguaje ofensivo'])
            ->assertOk();

        $this->assertDatabaseHas('chat_reports', [
            'message_id' => $message->id,
            'reporter_id' => $ana->id,
            'reason' => 'Lenguaje ofensivo',
        ]);

        $reports = Conversation::query()->where('type', 'reports')->firstOrFail();

        $this->assertTrue($reports->messages()->where('body', 'like', '%Lenguaje ofensivo%')->exists());

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\NotificationMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email);
        });
    }

    public function test_silenciar_marca_la_conversacion_y_la_excluye_del_contador_global(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Hola Luis',
        ]);

        $this->actingAs($luis)
            ->postJson('/chat/' . $conversation->id . '/silenciar')
            ->assertOk()
            ->assertJsonPath('muted', true);

        $this->actingAs($luis)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.muted', true)
            ->assertJsonPath('conversations.0.unread', 1)
            ->assertJsonPath('unread', 0);

        $this->actingAs($luis)
            ->postJson('/chat/' . $conversation->id . '/silenciar')
            ->assertOk()
            ->assertJsonPath('muted', false);

        $this->actingAs($luis)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.muted', false)
            ->assertJsonPath('unread', 1);
    }

    public function test_solo_los_participantes_pueden_silenciar_o_borrar_del_lado_propio(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($admin)
            ->postJson('/chat/' . $conversation->id . '/silenciar')
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/chat/' . $conversation->id . '/limpiar')
            ->assertForbidden();
    }

    public function test_borrar_del_lado_propio_oculta_el_historial_y_el_contador(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $old = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Mensaje anterior',
        ]);

        $old->forceFill(['created_at' => now()->subHour()])->save();

        $this->actingAs($luis)
            ->postJson('/chat/' . $conversation->id . '/limpiar')
            ->assertOk();

        $this->actingAs($luis)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->actingAs($luis)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.last_message', null)
            ->assertJsonPath('conversations.0.unread', 0);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Mensaje nuevo',
        ]);

        $this->actingAs($luis)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Mensaje nuevo');

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk()
            ->assertJsonCount(2, 'messages');
    }

    public function test_bloquear_y_desbloquear_a_un_usuario(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($luis)
            ->postJson('/chat/usuarios/' . $ana->id . '/bloquear')
            ->assertOk()
            ->assertJsonPath('blocked', true);

        $this->assertDatabaseHas('chat_blocks', [
            'blocker_id' => $luis->id,
            'blocked_id' => $ana->id,
        ]);

        $this->actingAs($luis)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.blocked', true)
            ->assertJsonPath('conversations.0.other_user_id', $ana->id);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => '¿Estás ahí?'])
            ->assertForbidden();

        $this->actingAs($luis)
            ->deleteJson('/chat/usuarios/' . $ana->id . '/bloquear')
            ->assertOk()
            ->assertJsonPath('blocked', false);

        $this->assertDatabaseMissing('chat_blocks', [
            'blocker_id' => $luis->id,
            'blocked_id' => $ana->id,
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => 'Ya puedes escribir'])
            ->assertOk();
    }

    public function test_el_widget_del_chat_incluye_el_menu_de_la_lista(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $this->actingAs($ana)
            ->get('/mensajes/chat')
            ->assertOk()
            ->assertSee('id="chat-list-menu"', false)
            ->assertSee('data-list-action="mute"', false)
            ->assertSee('data-list-action="unmute"', false)
            ->assertSee('data-list-action="clear"', false)
            ->assertSee('data-list-action="block"', false)
            ->assertSee('data-list-action="unblock"', false);
    }

    public function test_el_silencio_respeta_las_notificaciones_del_chat(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);
        $pedro = $this->userWithChat('Pedro', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $otra = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $otra->users()->attach([$ana->id, $pedro->id]);

        $this->actingAs($luis)
            ->postJson('/chat/' . $conversation->id . '/silenciar')
            ->assertOk();

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => 'Mensaje silenciado'])
            ->assertOk();

        $this->assertSame(0, Notification::query()
            ->where('notifiable_id', $luis->id)
            ->where('type', 'chat.mensaje')
            ->count());

        $this->actingAs($ana)
            ->postJson('/chat/' . $otra->id . '/mensajes', ['body' => 'Mensaje normal'])
            ->assertOk();

        $this->assertSame(1, Notification::query()
            ->where('notifiable_id', $pedro->id)
            ->where('type', 'chat.mensaje')
            ->count());
    }

    public function test_etiquetar_al_administrador_crea_notificacion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => 'Hola @Administrador'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'type' => 'chat.mention',
            'notifiable_id' => $admin->id,
            'notifiable_type' => $admin::class,
        ]);
    }

    public function test_un_participante_sale_del_grupo(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $admin->id]);
        $group->users()->attach([$admin->id, $ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $group->id . '/salir')
            ->assertOk();

        $this->assertFalse($group->users()->whereKey($ana->id)->exists());
        $this->assertTrue($group->users()->whereKey($luis->id)->exists());
    }

    public function test_el_administrador_elimina_una_conversacion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $ana->id,
            'body' => 'Mensaje a borrar',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/chat/' . $conversation->id)
            ->assertOk();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_messages', ['conversation_id' => $conversation->id]);
    }

    public function test_un_participante_no_puede_eliminar_la_conversacion(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->deleteJson('/chat/' . $conversation->id)
            ->assertForbidden();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_el_dueno_invita_y_el_usuario_acepta(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id, $ana->id]);

        $response = $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/invitar', [])
            ->assertOk();

        $token = $response->json('token');

        $this->actingAs($luis)
            ->postJson('/chat/grupo/invitacion/' . $token . '/aceptar')
            ->assertOk();

        $this->assertTrue($group->users()->whereKey($luis->id)->exists());
    }

    public function test_el_dueno_suspende_y_el_miembro_no_puede_enviar(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id, $ana->id]);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/suspender/' . $ana->id, ['days' => 3])
            ->assertOk();

        $this->actingAs($ana)
            ->postJson('/chat/' . $group->id . '/mensajes', ['body' => 'Hola'])
            ->assertForbidden();
    }

    public function test_el_dueno_banea_y_expulsa_al_miembro(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id, $ana->id]);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/banear/' . $ana->id)
            ->assertOk();

        $this->assertFalse($group->users()->whereKey($ana->id)->exists());
    }

    public function test_el_dueno_no_puede_salir_de_su_grupo(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id, $ana->id]);

        $this->actingAs($owner)
            ->postJson('/chat/' . $group->id . '/salir')
            ->assertStatus(422);

        $this->assertTrue($group->users()->whereKey($owner->id)->exists());
    }

    public function test_las_etiquetas_de_un_grupo_traen_su_color(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Grupo', 'created_by' => $ana->id]);
        $group->users()->attach([$ana->id, $luis->id, $admin->id]);

        $response = $this->actingAs($ana)
            ->getJson('/chat/' . $group->id . '/mensajes')
            ->assertOk();

        $items = collect($response->json('mentionables'))->keyBy('token');

        $this->assertSame('owner', $items[$ana->username]['kind']);
        $this->assertSame('Dueño del grupo', $items[$ana->username]['label']);
        $this->assertSame('member', $items[$luis->username]['kind']);
        $this->assertSame('Participante', $items[$luis->username]['label']);
        $this->assertSame('admin', $items[$admin->username]['kind']);
        $this->assertSame('Administrador', $items[$admin->username]['label']);
        $this->assertSame('broadcast', $items['everyone']['kind']);
        $this->assertSame('broadcast', $items['here']['kind']);
        $this->assertSame('Todos', $items['everyone']['label']);
    }

    public function test_las_etiquetas_de_un_chat_directo_son_solo_los_dos_participantes(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);
        $pedro = $this->userWithChat('Pedro', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $response = $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $tokens = collect($response->json('mentionables'))->pluck('token');

        $this->assertCount(2, $tokens);
        $this->assertTrue($tokens->contains($ana->username));
        $this->assertTrue($tokens->contains($luis->username));
        $this->assertFalse($tokens->contains($pedro->username));
        $this->assertFalse($tokens->contains('everyone'));
    }

    public function test_las_etiquetas_del_amigo_secreto_no_revelan_quien_es(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'secret', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $response = $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $items = collect($response->json('mentionables'));

        $this->assertSame(['amigosecreto'], $items->pluck('token')->all());
        $this->assertSame('AmigoSecreto', $items->first()['text']);
        $this->assertSame('Amigo secreto', $items->first()['label']);
        $this->assertSame('secret', $items->first()['kind']);
    }

    public function test_etiquetar_al_amigo_secreto_avisa_al_otro_sin_revelarlo(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'secret', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/mensajes', ['body' => 'Hola @AmigoSecreto'])
            ->assertOk();

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $luis->id)
                ->where('type', 'chat.mention')
                ->exists()
        );

        $this->assertSame(
            0,
            Notification::query()->where('notifiable_id', $ana->id)->where('type', 'chat.mention')->count()
        );

        // El aviso no revela quién etiquetó: solo dice que la conversación
        // del amigo secreto tiene una etiqueta nueva.
        $notification = Notification::query()
            ->where('notifiable_id', $luis->id)
            ->where('type', 'chat.mention')
            ->firstOrFail();

        $this->assertStringNotContainsString('Ana', $notification->title());
        $this->assertStringNotContainsString('Ana', $notification->body());
    }

    public function test_el_contador_suma_los_nuevos_propios_y_separa_la_moderacion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $admin->forceFill(['last_login_at' => now()->subMinute()])->save();

        // Conversación propia del administrador: 2 mensajes nuevos.
        $direct = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $direct->users()->attach([$admin->id, $ana->id]);

        ChatMessage::create(['conversation_id' => $direct->id, 'user_id' => $ana->id, 'body' => 'Hola']);
        ChatMessage::create(['conversation_id' => $direct->id, 'user_id' => $ana->id, 'body' => '¿Estás?']);

        // Conversación silenciada: sus mensajes no cuentan.
        $muted = Conversation::create(['type' => 'direct', 'created_by' => $luis->id]);
        $muted->users()->attach([$admin->id, $luis->id]);
        $muted->users()->updateExistingPivot($admin->id, ['muted_at' => now()]);

        ChatMessage::create(['conversation_id' => $muted->id, 'user_id' => $luis->id, 'body' => 'Silenciada']);

        // Conversación que el administrador solo modera: cuenta aparte.
        $moderated = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $moderated->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create(['conversation_id' => $moderated->id, 'user_id' => $ana->id, 'body' => 'Privado']);

        $this->actingAs($admin)
            ->getJson('/chat/contador')
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonPath('moderation', 1);

        $this->assertSame(0, $moderated->users()->whereKey($admin->id)->count());

        $resumen = $this->actingAs($admin)->getJson('/chat/resumen')->assertOk();

        $this->assertSame(2, $resumen->json('unread'));
        $this->assertSame(1, $resumen->json('moderation_unread'));
    }

    public function test_abrir_una_conversacion_ajena_limpia_el_conteo_de_moderacion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $admin->forceFill(['last_login_at' => now()->subMinute()])->save();

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'user_id' => $ana->id, 'body' => 'Hola']);

        $this->actingAs($admin)
            ->getJson('/chat/contador')
            ->assertOk()
            ->assertJsonPath('moderation', 1);

        $this->actingAs($admin)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $this->assertDatabaseHas('chat_moderation_reads', [
            'user_id' => $admin->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/chat/contador')
            ->assertOk()
            ->assertJsonPath('moderation', 0);
    }

    public function test_el_administrador_no_tiene_pestana_de_amigo_secreto(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-tab="secret"', false)
            ->assertDontSee('data-panel="secret"', false)
            ->assertSee('data-tab="moderation"', false)
            ->assertSee('id="chat-moderation-count"', false)
            ->assertSee('id="chat-mentions"', false)
            ->assertSee('data-url-contador', false);

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-tab="secret"', false)
            ->assertDontSee('data-tab="moderation"', false);
    }

    /**
     * El sondeo del widget pide solo lo nuevo: mensajes posteriores al último
     * pintado más una ventana corta con el estado de los recientes.
     */
    public function test_el_sondeo_incremental_solo_trae_lo_nuevo_y_la_ventana_reciente(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $primero = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Uno',
        ]);

        $segundo = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Dos',
        ]);

        $response = $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes?after_id=' . $primero->id)
            ->assertOk()
            ->assertJsonPath('delta', true)
            ->assertJsonPath('latest_id', $segundo->id)
            ->assertJsonPath('conversation', null)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Dos');

        // La ventana reciente permite parchear lo que ya estaba pintado.
        $this->assertSame(['Uno', 'Dos'], array_column($response->json('recent'), 'body'));
        $this->assertNotNull($response->json('recent_hash'));
    }

    /**
     * Un sondeo sin novedades no debe escribir en la base (ni marcar lectura
     * ni entregas): la conversación abierta en reposo no golpea el sistema.
     */
    public function test_el_sondeo_incremental_no_escribe_cuando_no_hay_novedad(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $mensaje = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Hola',
        ]);

        // Primera carga: entrega y marca de lectura normales.
        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $marca = now()->subHour()->startOfSecond();
        $conversation->users()->updateExistingPivot($ana->id, ['last_read_at' => $marca]);

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes?after_id=' . $mensaje->id)
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $guardado = $conversation->fresh()->users->firstWhere('id', $ana->id)->pivot->last_read_at;

        $this->assertSame(
            $marca->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($guardado)->toDateTimeString()
        );
    }

    /**
     * Refrescar una burbuja concreta (editar, borrar o reaccionar) no debe
     * pedir el historial ni escribir marcas de lectura.
     */
    public function test_el_refresco_por_id_no_pide_historial_ni_marca_lectura(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $mensajes = [];

        for ($i = 1; $i <= 15; $i++) {
            $mensajes[] = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $luis->id,
                'body' => 'Mensaje ' . $i,
            ]);
        }

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        $marca = now()->subHour()->startOfSecond();
        $conversation->users()->updateExistingPivot($ana->id, ['last_read_at' => $marca]);

        $response = $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes?ids[]=' . $mensajes[0]->id)
            ->assertOk()
            ->assertJsonPath('delta', true)
            ->assertJsonCount(0, 'messages')
            ->assertJsonCount(1, 'recent')
            ->assertJsonPath('recent.0.id', $mensajes[0]->id);

        $this->assertNotEmpty($response->json('recent'));

        $guardado = $conversation->fresh()->users->firstWhere('id', $ana->id)->pivot->last_read_at;

        $this->assertSame(
            $marca->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($guardado)->toDateTimeString()
        );
    }

    /**
     * La ventana reciente refleja reacciones y borrados sin volver a pedir el
     * historial completo.
     */
    public function test_el_sondeo_incremental_refleja_reacciones_y_borrados(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $primero = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Con reacción',
        ]);

        $segundo = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Se va a borrar',
        ]);

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk();

        \App\Models\ChatReaction::create([
            'message_id' => $primero->id,
            'user_id' => $luis->id,
            'emoji' => '👍',
        ]);

        $segundo->forceFill([
            'deleted_at' => now(),
            'deleted_by' => $luis->id,
        ])->save();

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes?after_id=' . $segundo->id)
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('recent.0.reactions.0.emoji', '👍')
            ->assertJsonPath('recent.0.reactions.0.count', 1)
            ->assertJsonPath('recent.1.deleted', true);
    }

    public function test_el_menu_de_usuarios_tiene_silenciar_bloquear_y_reportar(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="chat-user-menu"', false)
            ->assertSee('data-user-action="mute"', false)
            ->assertSee('data-user-action="unmute"', false)
            ->assertSee('data-user-action="block"', false)
            ->assertSee('data-user-action="unblock"', false)
            ->assertSee('data-user-action="report"', false)
            // El alta de grupos se hace en un modal, no dentro de la pestaña.
            ->assertSee('id="modal-chat-group"', false)
            ->assertSee('id="chat-group-open"', false)
            ->assertSee('id="chat-group-invite-url"', false);
    }

    public function test_silenciar_a_un_usuario_crea_el_chat_y_alterna_el_silencio(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $this->actingAs($ana)
            ->postJson('/chat/usuarios/' . $luis->id . '/silenciar')
            ->assertOk()
            ->assertJsonPath('muted', true);

        $conversation = Conversation::query()->where('type', 'direct')->firstOrFail();

        $this->assertSame(2, $conversation->users()->count());
        $this->assertNotNull(
            $conversation->fresh('users')->users->firstWhere('id', $ana->id)->pivot->muted_at
        );

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('users.0.muted', true)
            ->assertJsonPath('users.0.conversation_id', $conversation->id);

        // Silenciado: sus mensajes no suman al contador del botón flotante.
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Hola',
        ]);

        $this->actingAs($ana)
            ->getJson('/chat/contador')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        // Volver a pulsar quita el silencio.
        $this->actingAs($ana)
            ->postJson('/chat/usuarios/' . $luis->id . '/silenciar')
            ->assertOk()
            ->assertJsonPath('muted', false);

        $this->actingAs($ana)
            ->getJson('/chat/contador')
            ->assertOk()
            ->assertJsonPath('unread', 1);
    }

    public function test_bloquear_corta_el_acceso_y_desbloquear_lo_devuelve(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $this->actingAs($ana)
            ->postJson('/chat/usuarios/' . $luis->id . '/bloquear')
            ->assertOk()
            ->assertJsonPath('blocked', true);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('users.0.blocked', true);

        // Bloqueado: no se puede abrir ni entrar al chat.
        $this->actingAs($ana)
            ->postJson('/chat/abrir', ['user_id' => $luis->id])
            ->assertStatus(422);

        $this->actingAs($luis)
            ->postJson('/chat/abrir', ['user_id' => $ana->id])
            ->assertStatus(422);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonCount(0, 'conversations');

        // El bloqueado tampoco puede reportar mientras dure el bloqueo.
        $this->actingAs($ana)
            ->postJson('/chat/usuarios/' . $luis->id . '/reportar', ['reason' => 'Molesta'])
            ->assertStatus(422);

        $this->actingAs($ana)
            ->deleteJson('/chat/usuarios/' . $luis->id . '/bloquear')
            ->assertOk()
            ->assertJsonPath('blocked', false);

        $this->actingAs($ana)
            ->postJson('/chat/abrir', ['user_id' => $luis->id])
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('users.0.blocked', false);
    }

    public function test_estando_bloqueado_no_se_pueden_leer_los_mensajes(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Hola',
        ]);

        $this->actingAs($luis)
            ->postJson('/chat/usuarios/' . $ana->id . '/bloquear')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertForbidden();
    }

    public function test_reportar_a_un_usuario_avisa_a_la_administracion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $this->actingAs($ana)
            ->postJson('/chat/usuarios/' . $luis->id . '/reportar', ['reason' => 'Me escribió cosas feas'])
            ->assertOk();

        $report = \App\Models\ChatReport::query()->firstOrFail();

        $this->assertNull($report->message_id);
        $this->assertSame($ana->id, $report->reporter_id);
        $this->assertSame('Me escribió cosas feas', $report->reason);

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $admin->id)
                ->where('type', 'chat.report')
                ->exists()
        );

        // Sin motivo se guarda un texto por defecto con el nombre.
        $this->actingAs($luis)
            ->postJson('/chat/usuarios/' . $ana->id . '/reportar')
            ->assertOk();

        $this->assertDatabaseHas('chat_reports', [
            'reporter_id' => $luis->id,
            'reason' => 'Reporte del usuario Ana',
        ]);
    }

    public function test_crear_grupo_sin_integrantes_y_obtener_el_enlace_de_invitacion(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $response = $this->actingAs($ana)
            ->postJson('/chat/grupo', ['name' => 'Cumpleaños'])
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Cumpleaños');

        $groupId = $response->json('conversation.id');
        $group = Conversation::query()->findOrFail($groupId);

        $this->assertSame('group', $group->type);
        $this->assertSame(1, $group->users()->count());

        $this->actingAs($ana)
            ->postJson('/chat/grupo/' . $groupId . '/invitar', [])
            ->assertOk()
            ->assertJsonStructure(['token', 'url']);

        $this->actingAs($ana)
            ->postJson('/chat/grupo/' . $groupId . '/invitar')
            ->assertOk();

        $token = \App\Models\GroupInvitation::query()->latest('id')->firstOrFail()->token;

        // La invitación no tiene página propia: con sesión lleva al panel.
        $this->actingAs($ana)
            ->get('/chat/grupo/invitacion/' . $token)
            ->assertRedirect(route('dashboard'));

        // El grupo aparece en la pestaña Grupos del resumen.
        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $groupId)
            ->assertJsonPath('conversations.0.is_group', true);
    }

    public function test_la_invitacion_abre_el_modal_en_el_panel_y_permite_entrar_al_grupo(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $invited = $this->userWithChat('Invitado', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id]);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/invitar', [])
            ->assertOk();

        $token = \App\Models\GroupInvitation::query()->latest('id')->firstOrFail()->token;

        // Con sesión: se recuerda la invitación y se va al panel.
        $this->actingAs($invited)
            ->get('/chat/grupo/invitacion/' . $token)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('chat.invitation', $token);

        // El panel la muestra como modal dentro del sistema.
        $this->actingAs($invited)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="modal-chat-invite"', false)
            ->assertSee('Aceptar e ingresar')
            ->assertSee('Rechazar');

        // Datos para el modal: grupo y quién invita.
        $this->actingAs($invited)
            ->getJson('/chat/grupo/invitacion/' . $token . '/datos')
            ->assertOk()
            ->assertJsonPath('group', 'Cumpleaños')
            ->assertJsonPath('inviter', 'Dueno');

        // Aceptar mete a la persona al grupo y olvida la invitación pendiente.
        $this->actingAs($invited)
            ->postJson('/chat/grupo/invitacion/' . $token . '/aceptar')
            ->assertOk()
            ->assertJsonPath('conversation_id', $group->id)
            ->assertSessionMissing('chat.invitation');

        $this->assertTrue($group->users()->whereKey($invited->id)->exists());

        $this->actingAs($invited)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $group->id);
    }

    public function test_sin_sesion_la_invitacion_pasa_por_el_acceso_y_luego_al_panel(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id]);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/invitar', [])
            ->assertOk();

        $token = \App\Models\GroupInvitation::query()->latest('id')->firstOrFail()->token;

        // Se cierra la sesión del dueño para probar el camino del invitado.
        $this->app['auth']->forgetGuards();

        // Sin sesión: al acceso, recordando la invitación para después de entrar.
        $this->get('/chat/grupo/invitacion/' . $token)
            ->assertRedirect(route('login'))
            ->assertSessionHas('chat.invitation', $token);
    }

    public function test_rechazar_la_invitacion_la_olvida(): void
    {
        $owner = $this->userWithChat('Dueno', UserRole::Participant);
        $invited = $this->userWithChat('Invitado', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $owner->id]);
        $group->users()->attach([$owner->id]);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/' . $group->id . '/invitar', [])
            ->assertOk();

        $token = \App\Models\GroupInvitation::query()->latest('id')->firstOrFail()->token;

        $this->actingAs($invited)
            ->get('/chat/grupo/invitacion/' . $token)
            ->assertRedirect(route('dashboard'));

        $this->actingAs($invited)
            ->postJson('/chat/grupo/invitacion/' . $token . '/rechazar')
            ->assertOk()
            ->assertSessionMissing('chat.invitation');

        $this->assertFalse($group->users()->whereKey($invited->id)->exists());
    }

    public function test_el_menu_de_la_conversacion_tiene_las_opciones_nuevas(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-tab="archived"', false)
            ->assertSee('data-panel="archived"', false)
            ->assertSee('id="chat-pin"', false)
            ->assertSee('Vaciar chat')
            ->assertSee('Marcar como leído')
            ->assertSee('Marcar como no leído')
            ->assertSee('Colocar en favoritos')
            ->assertSee('Quitar de favoritos')
            ->assertSee('Fijar conversación')
            ->assertSee('Desfijar conversación')
            ->assertSee('Archivar')
            ->assertSee('Deshacer archivo')
            ->assertSee('Fijar mensaje')
            ->assertSee('Desfijar mensaje')
            ->assertDontSee('Borrar de mi lado');
    }

    public function test_vaciar_limpia_el_chat_pero_la_conversacion_sigue_en_la_lista(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Hola',
        ]);

        // Un mensaje del pasado: el vaciado no oculta lo que llega en el mismo
        // segundo para no perder mensajes nuevos.
        $message->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/vaciar')
            ->assertOk();

        $pivot = $conversation->fresh('users')->users->firstWhere('id', $ana->id)->pivot;

        $this->assertNotNull($pivot->cleared_at);
        $this->assertNull($pivot->hidden_at);

        $this->actingAs($ana)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonCount(0, 'archived');
    }

    public function test_eliminar_saca_la_conversacion_y_al_reabrir_empieza_vacia(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'user_id' => $luis->id, 'body' => 'Hola']);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/eliminar')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonCount(0, 'conversations');

        // La conversación no se duplica: se reutiliza la misma al reabrirla.
        $response = $this->actingAs($ana)
            ->postJson('/chat/abrir', ['user_id' => $luis->id])
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id);

        $this->assertNotNull($response->json('conversation'));

        $pivot = $conversation->fresh('users')->users->firstWhere('id', $ana->id)->pivot;

        $this->assertNull($pivot->hidden_at);
        $this->assertNotNull($pivot->cleared_at);
        $this->assertSame(1, Conversation::query()->count());

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversation->id);
    }

    public function test_el_favorito_destaca_pero_no_cambia_el_orden_y_fijar_la_pone_primero(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);
        $pedro = $this->userWithChat('Pedro', UserRole::Participant);

        $vieja = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $vieja->users()->attach([$ana->id, $luis->id]);

        $nueva = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $nueva->users()->attach([$ana->id, $pedro->id]);

        $vieja->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        $nueva->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->actingAs($ana)
            ->postJson('/chat/' . $vieja->id . '/favorito')
            ->assertOk()
            ->assertJsonPath('favorite', true);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $nueva->id)
            ->assertJsonPath('conversations.1.favorite', true);

        $this->actingAs($ana)
            ->postJson('/chat/' . $vieja->id . '/fijar')
            ->assertOk()
            ->assertJsonPath('pinned', true);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $vieja->id)
            ->assertJsonPath('conversations.0.pinned', true)
            ->assertJsonPath('conversations.0.favorite', true);

        // Volver a pulsar quita la fijación y el favorito.
        $this->actingAs($ana)
            ->postJson('/chat/' . $vieja->id . '/fijar')
            ->assertOk()
            ->assertJsonPath('pinned', false);

        $this->actingAs($ana)
            ->postJson('/chat/' . $vieja->id . '/favorito')
            ->assertOk()
            ->assertJsonPath('favorite', false);
    }

    public function test_marcar_como_leido_y_no_leido(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'user_id' => $luis->id, 'body' => 'Uno']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'user_id' => $luis->id, 'body' => 'Dos']);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.unread', 2);

        // Leído sin entrar al chat.
        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/leer')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.unread', 0);

        // No leído: se revierte la lectura de toda la conversación.
        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/no-leido')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.unread', 2);
    }

    public function test_archivar_saca_la_conversacion_de_la_moderacion(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        ChatMessage::create(['conversation_id' => $conversation->id, 'user_id' => $ana->id, 'body' => 'Privado']);

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('moderation.0.id', $conversation->id);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/archivar')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonCount(0, 'moderation');

        $this->actingAs($admin)
            ->getJson('/chat/' . $conversation->id . '/mensajes')
            ->assertForbidden();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('archived.0.id', $conversation->id)
            ->assertJsonCount(0, 'conversations');

        // Deshacer el archivo la devuelve a la lista y a la moderación.
        $this->actingAs($ana)
            ->deleteJson('/chat/' . $conversation->id . '/archivar')
            ->assertOk();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonCount(0, 'archived');

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('moderation.0.id', $conversation->id);
    }

    public function test_los_grupos_no_se_archivan(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumple', 'created_by' => $admin->id]);
        $group->users()->attach([$admin->id, $ana->id]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $group->id . '/archivar')
            ->assertStatus(422);
    }

    public function test_fijar_un_mensaje_lo_muestra_en_el_cintillo_y_la_moderacion_no_puede(): void
    {
        $admin = $this->userWithChat('Admin', UserRole::Admin);
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje importante',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/' . $conversation->id . '/fijar-mensaje/' . $message->id)
            ->assertOk()
            ->assertJsonPath('pinned_message.id', $message->id)
            ->assertJsonPath('pinned_message.body', 'Mensaje importante');

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.pinned_message.id', $message->id);

        $this->actingAs($admin)
            ->postJson('/chat/' . $conversation->id . '/fijar-mensaje/' . $message->id)
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('moderation.0.pinned_message.id', $message->id);

        $this->actingAs($ana)
            ->deleteJson('/chat/' . $conversation->id . '/mensaje-fijado')
            ->assertOk();

        $this->assertNull($conversation->fresh()->pinned_message_id);
    }

    public function test_el_amigo_secreto_distingue_al_asignado_del_incognito(): void
    {
        $ana = $this->userWithChat('Ana', UserRole::Participant);
        $luis = $this->userWithChat('Luis', UserRole::Participant);

        $event = Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => (int) now()->year,
            'status' => EventStatus::Drawn,
            'exchange_at' => now()->addDays(10),
        ]);

        $assignment = Assignment::create([
            'event_id' => $event->id,
            'giver_id' => $ana->id,
            'receiver_id' => $luis->id,
            'drawn_at' => now(),
        ]);

        $this->actingAs($ana)
            ->getJson('/chat/amigo-secreto')
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Amigo secreto: Luis')
            ->assertJsonPath('items.0.label', 'Tu asignado');

        $conversation = Conversation::query()->where('assignment_id', $assignment->id)->firstOrFail();

        // Quien regala ve el nombre; quien recibe no.
        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.title', 'Amigo secreto: Luis')
            ->assertJsonPath('conversations.0.subtitle', 'Tu amigo secreto asignado');

        $this->actingAs($luis)
            ->getJson('/chat/amigo-secreto')
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Amigo secreto')
            ->assertJsonPath('items.0.label', 'Incógnito');

        $this->actingAs($luis)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('conversations.0.title', 'Amigo secreto')
            ->assertJsonPath('conversations.0.subtitle', 'Amigo secreto incógnito');

        // Al terminar el evento, el chat queda archivado y no se revierte.
        $event->update(['status' => EventStatus::Revealed]);

        $this->assertNotNull(
            $conversation->fresh('users')->users->firstWhere('id', $ana->id)->pivot->archived_at
        );

        $this->actingAs($ana)
            ->deleteJson('/chat/' . $conversation->id . '/archivar')
            ->assertStatus(422);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('archived.0.id', $conversation->id);
    }
}
