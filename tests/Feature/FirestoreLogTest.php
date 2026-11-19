<?php

namespace Tests\Feature;

use App\Models\FirestoreLog;
use App\Models\User;
use App\Services\Firestore\FirestoreGateway;
use App\Services\Firestore\FirestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFirestoreGateway;
use Tests\TestCase;

class FirestoreLogTest extends TestCase
{
    use RefreshDatabase;

    private FakeFirestoreGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeFirestoreGateway;
        $this->app->instance(FirestoreGateway::class, $this->gateway);
    }

    public function test_cada_escritura_de_firestore_queda_en_el_log_sql_con_el_mismo_json(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = $this->app->make(FirestoreService::class);

        $sent = $service->recordEmails([
            [
                'id' => 'correo-1',
                'user_id' => $user->id,
                'to' => 'destino@example.com',
                'subject' => 'Bienvenida',
            ],
        ]);

        $this->assertTrue($sent);

        $log = FirestoreLog::query()->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertContains($user->id, $log->user_ids);
        $this->assertSame('setMany', $log->action);
        $this->assertSame('multiple', $log->collection);
        $this->assertSame('ok', $log->status);
        $this->assertSame(1, $log->documents);
        $this->assertSame('correo-1', $log->payload[0]['id']);
        $this->assertSame('destino@example.com', $log->payload[0]['data']['to']);

        // El JSON del log es exactamente el que recibió el espejo NoSQL.
        $this->assertSame(
            $this->gateway->documents[FirestoreService::EMAILS]['correo-1'],
            $log->payload[0]['data']
        );
    }

    public function test_el_log_registra_el_intento_aunque_firestore_este_deshabilitado(): void
    {
        $this->gateway->enabled = false;

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->app->make(FirestoreService::class)->recordEmails([
            ['id' => 'correo-2', 'user_id' => $user->id, 'to' => 'otro@example.com'],
        ]);

        $log = FirestoreLog::query()->firstOrFail();

        $this->assertSame('disabled', $log->status);
        $this->assertSame('setMany', $log->action);
        $this->assertNull($this->gateway->get(FirestoreService::EMAILS, 'correo-2'));
    }

    public function test_el_log_incluye_los_usuarios_relacionados_del_documento(): void
    {
        $ana = User::factory()->create();
        $luis = User::factory()->create();

        $this->actingAs($ana);

        $notification = new \App\Models\Notification;
        $notification->id = 55;
        $notification->data = ['title' => 'Hola', 'body' => 'Mensaje'];

        $this->app->make(FirestoreService::class)->recordPushes([
            [
                'notification' => $notification,
                'user' => $luis,
            ],
        ]);

        $log = FirestoreLog::query()->firstOrFail();

        $this->assertSame($ana->id, $log->user_id);
        $this->assertContains($ana->id, $log->user_ids);
        $this->assertContains($luis->id, $log->user_ids);
        $this->assertSame('notificaciones_push', $log->payload[0]['collection']);
    }
}
