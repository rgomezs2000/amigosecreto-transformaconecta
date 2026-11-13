<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\EmailLog;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Firestore\FirestoreGateway;
use App\Services\Firestore\FirestoreService;
use App\Services\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeFirestoreGateway;
use Tests\Support\GrantsModules;
use Tests\TestCase;

class NotificacionesTest extends TestCase
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

    public function test_el_administrador_guarda_la_configuracion_global(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['control-notificaciones']);

        $this->actingAs($admin)
            ->postJson('/administracion/notificaciones', [
                'email_enabled' => 1,
                'push_enabled' => 1,
                'sound_enabled' => 0,
                'in_app_enabled' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $settings = NotificationSetting::current();

        $this->assertFalse($settings->sound_enabled);
        $this->assertTrue($settings->in_app_enabled);
        $this->assertTrue($settings->push_enabled);
    }

    public function test_el_control_es_solo_para_quien_tiene_el_modulo(): void
    {
        $participant = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        // El módulo existe, pero no se le ha concedido a este usuario.
        $this->ensureModule('control-notificaciones');

        $this->actingAs($participant)
            ->get(module_url('control-notificaciones'))
            ->assertForbidden();
    }

    public function test_el_usuario_silencia_el_sonido_y_conserva_los_avisos(): void
    {
        NotificationSetting::current()->update(['sound_enabled' => true, 'in_app_enabled' => true]);

        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        $this->actingAs($user)
            ->postJson('/notificaciones/preferencias', [
                'sound_enabled' => 0,
                'in_app_enabled' => 1,
                'chat_sound_enabled' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('preferences.sound', false)
            ->assertJsonPath('preferences.in_app', true)
            ->assertJsonPath('preferences.chat_sound', false);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'sound_enabled' => 0,
            'in_app_enabled' => 1,
            'chat_sound_enabled' => 0,
        ]);
    }

    public function test_el_administrador_cambia_y_restablece_preferencias_de_un_usuario(): void
    {
        NotificationSetting::current()->update(['sound_enabled' => true]);

        $admin = $this->makeUser('Admin', UserRole::Admin, ['control-notificaciones']);
        $user = $this->makeUser('Luis', UserRole::Participant, ['notificaciones']);

        $this->actingAs($admin)
            ->postJson('/administracion/notificaciones/usuarios/'.$user->id, [
                'sound_enabled' => 0,
                'push_enabled' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('preferences.sound', false)
            ->assertJsonPath('preferences.push', true);

        $this->actingAs($admin)
            ->postJson('/administracion/notificaciones/usuarios/'.$user->id, ['reset' => 1])
            ->assertOk()
            ->assertJsonPath('preferences.sound', true);

        $preference = NotificationPreference::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNull($preference->sound_enabled);
        $this->assertNull($preference->push_enabled);
    }

    public function test_el_contador_cuenta_y_la_lectura_marca_como_leida(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);
        $other = $this->makeUser('Luis', UserRole::Participant, ['notificaciones']);

        $notifier = app(PushNotifier::class);
        $notifier->send($user, 'prueba.uno', 'Primera', 'Cuerpo uno');
        $notifier->send($user, 'prueba.dos', 'Segunda', 'Cuerpo dos');

        $this->actingAs($user)
            ->getJson('/notificaciones/contador')
            ->assertOk()
            ->assertJsonPath('count', 2);

        $first = Notification::query()->where('notifiable_id', $user->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson('/notificaciones/'.$first->id.'/leer')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($this->firestore->get(FirestoreService::PUSH, $first->id));

        $this->actingAs($other)
            ->postJson('/notificaciones/'.$first->id.'/leer')
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson('/notificaciones/leer-todas')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_el_listado_ajax_devuelve_las_notificaciones(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        app(PushNotifier::class)->send(
            $user,
            'prueba.lista',
            'Título de lista',
            'Cuerpo de lista',
            [],
            '/destino'
        );

        $this->actingAs($user)
            ->getJson('/notificaciones/lista')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('notifications.0.title', 'Título de lista')
            ->assertJsonPath('notifications.0.body', 'Cuerpo de lista')
            ->assertJsonPath('notifications.0.url', '/destino')
            ->assertJsonPath('notifications.0.read', false);
    }

    public function test_el_listado_ajax_se_pagina_como_una_tabla(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);
        $notifier = app(PushNotifier::class);

        for ($index = 1; $index <= 12; $index++) {
            $notifier->send(
                $user,
                'prueba.pagina',
                'Aviso '.$index,
                'Cuerpo '.$index
            );
        }

        $first = $this->actingAs($user)
            ->getJson('/notificaciones/lista?page=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'notifications')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.total', 12)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 10);

        $this->assertCount(10, $first->json('notifications'));

        $second = $this->actingAs($user)
            ->getJson('/notificaciones/lista?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.from', 11)
            ->assertJsonPath('pagination.to', 12);

        $this->assertCount(2, $second->json('notifications'));
    }

    public function test_el_mensaje_del_chat_crea_notificacion_y_espejo_nosql(): void
    {
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola Luis'])
            ->assertOk();

        $notification = Notification::query()->where('notifiable_id', $luis->id)->first();

        $this->assertNotNull($notification);
        $this->assertSame('chat.mensaje', $notification->type);
        $this->assertSame('Nuevo mensaje de Ana', $notification->data['title']);
        $this->assertFalse($notification->sound);

        $message = ChatMessage::query()->latest('id')->firstOrFail();

        $this->assertTrue($this->firestore->has(FirestoreService::MESSAGES, (string) $message->id));
        $this->assertTrue($this->firestore->has(FirestoreService::PUSH, (string) $notification->id));
        $this->assertTrue($this->firestore->has(FirestoreService::CONVERSATIONS, (string) $conversation->id));

        $mirror = $this->firestore->get(FirestoreService::MESSAGES, (string) $message->id);

        $this->assertSame('Hola Luis', $mirror['body']);
        $this->assertSame('Ana', $mirror['sender']);
    }

    public function test_abrir_una_conversacion_la_sincroniza_con_firestore(): void
    {
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $response = $this->actingAs($ana)
            ->postJson('/chat/abrir', ['user_id' => $luis->id])
            ->assertOk();

        $conversationId = (string) $response->json('conversation.id');

        $this->assertNotSame('', $conversationId);
        $this->assertTrue($this->firestore->has(FirestoreService::CONVERSATIONS, $conversationId));

        $mirror = $this->firestore->get(FirestoreService::CONVERSATIONS, $conversationId);

        $this->assertSame('direct', $mirror['type']);
        $this->assertCount(2, $mirror['participants']);
    }

    public function test_leer_la_conversacion_marca_sus_notificaciones_de_chat_como_leidas(): void
    {
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $luis->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $this->actingAs($luis)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola Ana'])
            ->assertOk();

        $notification = Notification::query()
            ->where('notifiable_id', $ana->id)
            ->where('type', 'chat.mensaje')
            ->firstOrFail();

        $this->assertNull($notification->read_at);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_un_participante_puede_crear_un_grupo_desde_la_pestana_grupos(): void
    {
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);

        $response = $this->actingAs($ana)
            ->postJson('/chat/grupo', [
                'name' => 'Grupo de prueba',
                'user_ids' => [$luis->id],
            ])
            ->assertOk();

        $conversation = Conversation::query()->findOrFail($response->json('conversation.id'));

        $this->assertSame('group', $conversation->type);
        $this->assertCount(2, $conversation->users);
        $this->assertSame('moderator', $conversation->users->firstWhere('id', $ana->id)->pivot->role);

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-tab="groups"', false)
            ->assertSee('Crear grupo');
    }

    public function test_el_correo_se_registra_en_sql_y_en_firestore(): void
    {
        Mail::fake();

        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        $sent = send_mail(
            $user->email,
            'Asunto de prueba',
            'Título de prueba',
            ['Primera línea', 'Clave: Secreta123'],
            null,
            ['source' => 'prueba']
        );

        $this->assertTrue($sent);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $user->email,
            'status' => EmailLog::STATUS_SENT,
            'source' => 'prueba',
            'user_id' => $user->id,
        ]);

        $mirror = $this->firestore->collection(FirestoreService::EMAILS);

        $this->assertCount(1, $mirror);
        $this->assertSame('sent', array_values($mirror)[0]['status']);
        $this->assertSame('Asunto de prueba', array_values($mirror)[0]['subject']);
        $this->assertSame('Clave: [oculto]', array_values($mirror)[0]['lines'][1]);

        NotificationSetting::current()->update(['email_enabled' => false]);

        $skipped = send_mail($user->email, 'Asunto omitido', 'Título', ['Línea']);

        $this->assertFalse($skipped);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $user->email,
            'status' => EmailLog::STATUS_SKIPPED,
        ]);
    }

    public function test_el_panel_de_control_muestra_el_trafico_y_las_preferencias(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['control-notificaciones']);
        $this->makeUser('Luis', UserRole::Participant, ['notificaciones']);

        $this->actingAs($admin)
            ->get(module_url('control-notificaciones'))
            ->assertOk()
            ->assertSee('Tráfico')
            ->assertSee('Firestore (NoSQL)')
            ->assertSee('Preferencias por usuario')
            ->assertSee('Luis');
    }

    public function test_la_campana_muestra_las_notificaciones_y_sus_preferencias(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        app(PushNotifier::class)->send($user, 'prueba.panel', 'Título visible', 'Cuerpo visible');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Cómo quieres tus avisos')
            ->assertSee('Título visible')
            ->assertSee('Marcar todas como leídas')
            ->assertSee('Sonido')
            ->assertSee('modal-notification-detail', false)
            ->assertSee('notification-detail-body', false)
            ->assertDontSee('dashboard-notifications-label', false);
    }

    public function test_el_menu_enlaza_al_panel_de_control(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['control-notificaciones', 'notificaciones', 'moderacion']);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(module_url('control-notificaciones'), false)
            ->assertSee(module_url('moderacion'), false);
    }

    public function test_quien_solo_tiene_chat_puede_silenciar_el_chat(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['chat']);

        $this->actingAs($user)
            ->postJson('/notificaciones/preferencias', ['chat_sound_enabled' => 0])
            ->assertOk()
            ->assertJsonPath('preferences.chat_sound', false);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'chat_sound_enabled' => 0,
        ]);
    }

    public function test_un_usuario_no_puede_cambiar_preferencias_globales(): void
    {
        $user = $this->makeUser('Ana', UserRole::Participant, ['notificaciones']);

        $this->actingAs($user)
            ->postJson('/administracion/notificaciones', [
                'email_enabled' => 0,
                'push_enabled' => 0,
                'sound_enabled' => 1,
                'in_app_enabled' => 0,
            ])
            ->assertForbidden();
    }

    public function test_el_contador_requiere_el_modulo_de_notificaciones(): void
    {
        $this->ensureModule('notificaciones');

        $user = User::factory()->create(['role' => UserRole::Participant, 'is_active' => true]);

        $this->actingAs($user)
            ->getJson('/notificaciones/contador')
            ->assertForbidden();
    }
}
