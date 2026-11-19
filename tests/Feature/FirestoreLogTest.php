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
        //
        // Se ordenan las claves antes de comparar porque MySQL 8 guarda las
        // columnas JSON reordenando las claves de los objetos (primero por
        // longitud y luego alfabéticamente), mientras que MariaDB las guarda
        // como texto y conserva el orden de inserción. Sin normalizar, esta
        // comprobación pasaría en local (MariaDB) y fallaría en el CI (MySQL 8)
        // sin que el contenido sea distinto.
        $this->assertSame(
            $this->conClavesOrdenadas($this->gateway->documents[FirestoreService::EMAILS]['correo-1']),
            $this->conClavesOrdenadas($log->payload[0]['data'])
        );
    }

    /**
     * Ordena las claves de un arreglo de forma recursiva.
     *
     * @param  array<array-key, mixed>  $valor
     * @return array<array-key, mixed>
     */
    private function conClavesOrdenadas(array $valor): array
    {
        ksort($valor);

        foreach ($valor as $clave => $item) {
            if (is_array($item)) {
                $valor[$clave] = $this->conClavesOrdenadas($item);
            }
        }

        return $valor;
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
