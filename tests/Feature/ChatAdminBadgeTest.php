<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Etiqueta de «Administrador» (rol del sistema) junto al nombre.
 *
 * Debe verse en la burbuja y en la barra del chat, pero nunca en el amigo
 * secreto (revelaría quién es) ni sustituir al rótulo «Moderación».
 */
class ChatAdminBadgeTest extends TestCase
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

    public function test_la_burbuja_marca_al_administrador_junto_a_su_nombre(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin);
        $ana = $this->usuario('Ana');
        $conversation = $this->directa($admin, $ana);

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Aviso de la administración'])
            ->assertOk();

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Gracias'])
            ->assertOk();

        $messages = collect(
            $this->actingAs($ana)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        );

        $this->assertTrue($messages->firstWhere('body', 'Aviso de la administración')['sender_is_admin']);
        $this->assertFalse($messages->firstWhere('body', 'Gracias')['sender_is_admin']);
    }

    public function test_la_barra_del_chat_marca_al_administrador(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin);
        $ana = $this->usuario('Ana');
        $conversation = $this->directa($admin, $ana);

        // Ana habla con un administrador: la barra lo identifica.
        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.other_is_admin', true);

        // El administrador habla con una participante: no hay etiqueta.
        $this->actingAs($admin)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.other_is_admin', false);
    }

    public function test_el_amigo_secreto_no_revela_el_rol(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin);
        $ana = $this->usuario('Ana');

        $conversation = Conversation::create(['type' => 'secret', 'created_by' => $admin->id]);
        $conversation->users()->attach([$admin->id, $ana->id]);

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola desde el anonimato'])
            ->assertOk();

        $response = $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk();

        $this->assertFalse($response->json('conversation.other_is_admin'));

        $message = collect($response->json('messages'))->firstWhere('body', 'Hola desde el anonimato');

        $this->assertFalse($message['sender_is_admin']);
    }

    public function test_los_mensajes_de_moderacion_no_llevan_la_etiqueta(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin);
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $conversation = $this->directa($ana, $luis);

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Mensaje de moderación'])
            ->assertOk();

        $message = collect(
            $this->actingAs($ana)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('body', 'Mensaje de moderación');

        $this->assertTrue($message['is_moderation']);
        $this->assertFalse($message['sender_is_admin']);
    }

    public function test_la_cita_de_una_respuesta_conserva_la_etiqueta(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin);
        $ana = $this->usuario('Ana');
        $conversation = $this->directa($admin, $ana);

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Mensaje del administrador'])
            ->assertOk();

        $original = ChatMessage::query()->latest('id')->firstOrFail();

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', [
                'body' => 'Respuesta de Ana',
                'reply_to_id' => $original->id,
            ])
            ->assertOk();

        $reply = collect(
            $this->actingAs($ana)
                ->getJson('/chat/'.$conversation->id.'/mensajes')
                ->assertOk()
                ->json('messages')
        )->firstWhere('body', 'Respuesta de Ana');

        $this->assertTrue($reply['reply']['sender_is_admin']);
        $this->assertSame('Admin', $reply['reply']['sender']);
    }

    public function test_el_widget_tiene_la_etiqueta_en_la_barra_del_chat(): void
    {
        $ana = $this->usuario('Ana');

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="chat-title-badge"', false)
            ->assertSee('chat-badge chat-badge--admin', false);
    }
}
