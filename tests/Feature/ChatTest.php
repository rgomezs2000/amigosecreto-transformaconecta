<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Module;
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
            'username' => strtolower(str_replace(' ', '-', $name)),
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
}
