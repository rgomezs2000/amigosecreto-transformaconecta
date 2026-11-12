<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use App\Services\Firestore\FirestoreGateway;
use App\Services\Firestore\FirestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeFirestoreGateway;
use Tests\Support\GrantsModules;
use Tests\TestCase;

class ModeracionTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    private FakeFirestoreGateway $firestore;

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

        $this->firestore = new FakeFirestoreGateway;
        $this->app->instance(FirestoreGateway::class, $this->firestore);
    }

    /**
     * @return array{0: Conversation, 1: ChatMessage, 2: User, 3: User}
     */
    private function conversationWithReport(): array
    {
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje ofensivo',
        ]);

        ChatReport::create([
            'message_id' => $message->id,
            'reporter_id' => $ana->id,
            'reason' => 'Lenguaje ofensivo',
        ]);

        return [$conversation, $message, $ana, $luis];
    }

    public function test_el_administrador_ve_los_reportes_en_solo_lectura(): void
    {
        [$conversation, $message, $ana] = $this->conversationWithReport();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);

        $this->actingAs($admin)
            ->get('/administracion/moderacion')
            ->assertOk()
            ->assertSee('Reportes del chat')
            ->assertSee('solo lectura');

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.reporter', 'Ana')
            ->assertJsonPath('data.0.author', 'Luis')
            ->assertJsonPath('data.0.reason_full', 'Lenguaje ofensivo');

        $data = $response->json('data.0');

        $this->assertStringContainsString('conversacion='.$conversation->id, $data['conversation_url']);
        $this->assertSame($message->id, ChatReport::query()->firstOrFail()->message_id);
    }

    public function test_un_usuario_sin_el_modulo_no_entra_a_moderacion(): void
    {
        [$conversation] = $this->conversationWithReport();

        $user = $this->makeUser('Curioso', UserRole::Participant, ['chat']);

        $this->actingAs($user)
            ->get('/administracion/moderacion')
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson('/moderacion/datos')
            ->assertForbidden();

        $this->assertNotNull($conversation->fresh());
    }

    public function test_reportar_desde_el_chat_registra_nosql_y_avisa_al_administrador(): void
    {
        Mail::fake();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje inapropiado',
        ]);

        $this->actingAs($ana)
            ->postJson('/chat/mensajes/'.$message->id.'/reportar', ['reason' => 'Insultos'])
            ->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->assertTrue($this->firestore->has(FirestoreService::REPORTS, (string) $report->id));

        $mirror = $this->firestore->get(FirestoreService::REPORTS, (string) $report->id);

        $this->assertSame('Insultos', $mirror['reason']);
        $this->assertSame('Ana', $mirror['reporter_name']);

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $admin->id)
                ->where('type', 'chat.reporte')
                ->exists()
        );

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $admin->email,
            'source' => 'reporte',
        ]);
    }

    public function test_el_comando_de_sincronizacion_copia_el_historial(): void
    {
        [$conversation, $message] = $this->conversationWithReport();

        $report = ChatReport::query()->firstOrFail();

        $this->artisan('chat:sync-firestore')->assertSuccessful();

        $this->assertTrue($this->firestore->has(FirestoreService::CONVERSATIONS, (string) $conversation->id));
        $this->assertTrue($this->firestore->has(FirestoreService::MESSAGES, (string) $message->id));
        $this->assertTrue($this->firestore->has(FirestoreService::REPORTS, (string) $report->id));
    }

    public function test_el_modulo_de_moderacion_no_expone_rutas_de_escritura(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'administracion/moderacion')
                || str_starts_with($route->uri(), 'moderacion/'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertSame(
                [],
                array_values(array_diff($route->methods(), ['GET', 'HEAD'])),
                'La ruta '.$route->uri().' debería ser de solo lectura.'
            );
        }
    }
}
