<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatBlock;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\GroupBan;
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

    public function test_el_administrador_ve_los_reportes_y_su_flujo(): void
    {
        [$conversation, $message, $ana] = $this->conversationWithReport();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);

        $this->actingAs($admin)
            ->get(module_url('moderacion'))
            ->assertOk()
            ->assertSee('Reportes del chat')
            ->assertSee('tomar el caso');

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
            ->get(module_url('moderacion'))
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

    public function test_el_modulo_de_moderacion_tiene_tres_pestanas(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);

        $this->actingAs($admin)
            ->get(module_url('moderacion'))
            ->assertOk()
            ->assertSee('Reportes')
            ->assertSee('Bloqueos entre usuarios')
            ->assertSee('Vetados de grupo')
            ->assertSee('moderacion-usuarios-table', false)
            ->assertSee('moderacion-grupos-table', false)
            ->assertSee('modal-moderacion-bloqueos', false)
            ->assertSee('modal-moderacion-baneados', false);
    }

    public function test_el_modal_de_bloqueos_selecciona_con_clic_y_guarda_con_confirmacion(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);

        $this->actingAs($admin)
            ->get(module_url('moderacion'))
            ->assertOk()
            // Sin casillas: la selección es por clic y las flechas mueven.
            ->assertDontSee('data-transfer-check', false)
            ->assertSee('data-transfer-save', false)
            ->assertSee('data-transfer-confirm-yes', false)
            ->assertSee('Sí, guardar')
            ->assertSee('data-move="to-right-all"', false)
            ->assertSee('data-move="to-right"', false)
            ->assertSee('data-move="to-left"', false)
            ->assertSee('data-move="to-left-all"', false)
            // Cada flecha conserva su nombre accesible aunque no lleve texto.
            ->assertSee('aria-label="Bloquear a todas las personas"', false)
            ->assertSee('aria-label="Bloquear a la persona seleccionada"', false)
            ->assertSee('aria-label="Desbloquear a la persona seleccionada"', false)
            ->assertSee('aria-label="Desbloquear a todas las personas"', false);
    }

    public function test_el_listado_de_bloqueos_muestra_a_todos_los_usuarios_con_su_estado(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);
        $pedro = $this->makeUser('Pedro', UserRole::Participant);

        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);
        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $pedro->id]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/usuarios?draw=3&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('draw', 3)
            ->assertJsonPath('recordsTotal', 4)
            ->assertJsonPath('recordsFiltered', 4);

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame(2, $rows[$ana->id]['blocked_count']);
        $this->assertSame(0, $rows[$luis->id]['blocked_count']);
        $this->assertSame('Ana', $rows[$ana->id]['name_raw']);
        $this->assertStringContainsString('moderacion-bloqueos', $rows[$ana->id]['actions']);
        $this->assertStringContainsString('title="Gestionar bloqueos', $rows[$ana->id]['actions']);
        $this->assertStringNotContainsString('<span>', $rows[$ana->id]['actions']);
    }

    public function test_el_listado_de_bloqueos_se_puede_buscar(): void
    {
        // Correos y usuarios fijos: los aleatorios del factory pueden contener
        // el texto buscado y volver la prueba inestable.
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat'], [
            'email' => 'admin@example.com',
            'username' => 'admin',
        ]);

        $this->makeUser('Ana', UserRole::Participant, [], [
            'email' => 'ana@example.com',
            'username' => 'ana',
        ]);

        $this->makeUser('Luis', UserRole::Participant, [], [
            'email' => 'luis@example.com',
            'username' => 'luis',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/usuarios?draw=1&start=0&length=10&search[value]=Luis')
            ->assertOk();

        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame('Luis', $response->json('data.0.name_raw'));
    }

    public function test_el_listado_de_bloqueos_ordena_por_cantidad_de_bloqueos(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);

        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);
        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $admin->id]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/usuarios?draw=1&start=0&length=10&order[0][column]=3&order[0][dir]=desc')
            ->assertOk();

        $this->assertSame($ana->id, $response->json('data.0.id'));
        $this->assertSame(2, $response->json('data.0.blocked_count'));
    }

    public function test_el_detalle_de_bloqueos_separa_bloqueados_y_desbloqueados(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);
        $pedro = $this->makeUser('Pedro', UserRole::Participant);

        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/'.$ana->id)
            ->assertOk()
            ->assertJsonPath('subject.id', $ana->id)
            ->assertJsonPath('blocked.0.id', $luis->id);

        $availableIds = collect($response->json('available'))->pluck('id');

        $this->assertTrue($availableIds->contains($admin->id));
        $this->assertTrue($availableIds->contains($pedro->id));
        $this->assertFalse($availableIds->contains($ana->id));
        $this->assertFalse($availableIds->contains($luis->id));
    }

    public function test_la_sincronizacion_de_bloqueos_crea_y_elimina_segun_las_listas(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);
        $pedro = $this->makeUser('Pedro', UserRole::Participant);

        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [$admin->id, $luis->id],
                'blocked' => [$pedro->id],
            ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('removed', 1)
            ->assertJsonPath('message', 'Bloqueos actualizados.');

        $this->assertDatabaseHas('chat_blocks', ['blocker_id' => $ana->id, 'blocked_id' => $pedro->id]);
        $this->assertDatabaseMissing('chat_blocks', ['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);
        $this->assertSame(1, ChatBlock::query()->where('blocker_id', $ana->id)->count());

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [$admin->id, $luis->id, $pedro->id],
                'blocked' => [],
            ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('removed', 1)
            ->assertJsonPath('message', 'Bloqueo eliminado.');

        $this->assertSame(0, ChatBlock::query()->count());
    }

    public function test_la_sincronizacion_de_bloqueos_no_duplica_bloqueos(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);

        ChatBlock::create(['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [],
                'blocked' => [$luis->id],
            ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('removed', 0)
            ->assertJsonPath('message', 'Sin cambios en los bloqueos.');

        $this->assertSame(1, ChatBlock::query()->count());
    }

    public function test_la_sincronizacion_de_bloqueos_valida_las_listas(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant);
        $luis = $this->makeUser('Luis', UserRole::Participant);

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [$admin->id],
                'blocked' => [$ana->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un usuario no puede bloquearse a sí mismo.');

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [$luis->id],
                'blocked' => [$luis->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un usuario no puede estar en las dos listas a la vez.');

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, ['blocked' => [$luis->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Falta la lista de usuarios desbloqueados.');

        $this->actingAs($admin)
            ->postJson('/moderacion/bloqueos/'.$ana->id, [
                'available' => [],
                'blocked' => [999999],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uno de los usuarios bloqueados ya no existe.');

        $this->assertSame(0, ChatBlock::query()->count());
    }

    public function test_el_listado_de_grupos_muestra_miembros_y_vetados(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);
        $pedro = $this->makeUser('Pedro', UserRole::Participant, ['chat']);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $admin->id]);
        $group->users()->attach([$ana->id, $luis->id, $pedro->id]);

        GroupBan::create([
            'conversation_id' => $group->id,
            'user_id' => $pedro->id,
            'banned_by' => $admin->id,
        ]);

        Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/grupos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.name_raw', 'Cumpleaños')
            ->assertJsonPath('data.0.owner_raw', 'Admin')
            ->assertJsonPath('data.0.members', 3)
            ->assertJsonPath('data.0.banned', 1);

        $this->assertStringContainsString('moderacion-baneados', $response->json('data.0.actions'));
        $this->assertStringNotContainsString('<span>', $response->json('data.0.actions'));
    }

    public function test_el_detalle_de_vetados_separa_vetados_y_disponibles(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);
        $pedro = $this->makeUser('Pedro', UserRole::Participant, ['chat']);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $admin->id]);
        $group->users()->attach([$ana->id, $pedro->id]);

        GroupBan::create([
            'conversation_id' => $group->id,
            'user_id' => $pedro->id,
            'banned_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/grupos/'.$group->id.'/vetados')
            ->assertOk()
            ->assertJsonPath('subject.name', 'Cumpleaños')
            ->assertJsonPath('banned.0.id', $pedro->id)
            ->assertJsonPath('banned.0.member', true);

        $availableIds = collect($response->json('available'))->pluck('id');

        $this->assertTrue($availableIds->contains($ana->id));
        $this->assertTrue($availableIds->contains($luis->id));
        $this->assertFalse($availableIds->contains($pedro->id));
        $this->assertFalse($availableIds->contains($admin->id), 'El administrador no aparece en las listas.');
    }

    public function test_el_detalle_de_vetados_solo_acepta_grupos(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $direct = Conversation::create(['type' => 'direct', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->getJson('/moderacion/grupos/'.$direct->id.'/vetados')
            ->assertNotFound();
    }

    public function test_la_sincronizacion_de_vetados_aplica_el_veto_y_saca_al_usuario_del_grupo(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);
        $luis = $this->makeUser('Luis', UserRole::Participant, ['chat']);
        $pedro = $this->makeUser('Pedro', UserRole::Participant, ['chat']);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $admin->id]);
        $group->users()->attach([$ana->id, $luis->id, $pedro->id]);

        $this->actingAs($admin)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', [
                'available' => [$luis->id],
                'banned' => [$ana->id, $pedro->id],
            ])
            ->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('removed', 0)
            ->assertJsonPath('kicked', 2)
            ->assertJsonPath('message', 'Veto aplicado y usuario sacado del grupo.');

        $this->assertSame(2, GroupBan::query()->where('conversation_id', $group->id)->whereNull('revoked_at')->count());
        $this->assertFalse($group->users()->whereKey($ana->id)->exists());
        $this->assertFalse($group->users()->whereKey($pedro->id)->exists());
        $this->assertTrue($group->users()->whereKey($luis->id)->exists());

        $this->actingAs($admin)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', [
                'available' => [$ana->id, $luis->id],
                'banned' => [$pedro->id],
            ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('removed', 1)
            ->assertJsonPath('message', 'Veto retirado.');

        $this->assertNotNull(
            GroupBan::query()->where('conversation_id', $group->id)->where('user_id', $ana->id)->firstOrFail()->revoked_at
        );

        $this->assertSame(1, GroupBan::query()->where('conversation_id', $group->id)->whereNull('revoked_at')->count());
        $this->assertFalse($group->users()->whereKey($ana->id)->exists(), 'Retirar el veto no vuelve a meter al usuario.');
    }

    public function test_la_sincronizacion_de_vetados_valida_las_listas(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->makeUser('Ana', UserRole::Participant, ['chat']);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', [
                'available' => [$admin->id],
                'banned' => [$admin->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Un usuario no puede estar en las dos listas a la vez.');

        $this->actingAs($admin)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', [
                'available' => [$ana->id],
                'banned' => [$admin->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes vetarte a ti mismo.');

        $this->actingAs($admin)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', ['available' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Falta la lista de vetados.');

        $this->assertSame(0, GroupBan::query()->count());
    }

    public function test_los_endpoints_de_bloqueos_y_vetados_exigen_el_modulo(): void
    {
        [$conversation] = $this->conversationWithReport();
        $user = $this->makeUser('Curioso', UserRole::Participant, ['chat']);
        $other = $this->makeUser('Otro', UserRole::Participant, ['chat']);

        $group = Conversation::create(['type' => 'group', 'name' => 'Cumpleaños', 'created_by' => $user->id]);

        $this->actingAs($user)->getJson('/moderacion/bloqueos/usuarios')->assertForbidden();

        $this->actingAs($user)
            ->getJson('/moderacion/bloqueos/'.$other->id)
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/moderacion/bloqueos/'.$other->id, ['available' => [], 'blocked' => [$user->id]])
            ->assertForbidden();

        $this->actingAs($user)->getJson('/moderacion/grupos')->assertForbidden();

        $this->actingAs($user)
            ->getJson('/moderacion/grupos/'.$group->id.'/vetados')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/moderacion/grupos/'.$group->id.'/vetados', ['available' => [], 'banned' => []])
            ->assertForbidden();

        $this->assertSame(0, ChatBlock::query()->count());
        $this->assertSame(0, GroupBan::query()->count());
        $this->assertNotNull($conversation->fresh());
    }

    public function test_los_reportes_se_filtran_por_estado_y_por_rango_de_fecha(): void
    {
        [$conversation, $message, $ana] = $this->conversationWithReport();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $report = ChatReport::query()->firstOrFail();
        $report->update(['status' => ChatReport::STATUS_ATTENDING]);

        $closed = ChatReport::create([
            'message_id' => $message->id,
            'reporter_id' => $ana->id,
            'reason' => 'Otro reporte cerrado',
            'status' => ChatReport::STATUS_CLOSED,
        ]);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'filter_type' => 'status',
                'status' => ChatReport::STATUS_ATTENDING,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.id', $report->id);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'filter_type' => 'status',
                'status' => ChatReport::STATUS_CLOSED,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.id', $closed->id);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'filter_type' => 'date',
                'date_from' => now()->subDay()->format('Y-m-d\TH:i'),
                'date_to' => now()->addDay()->format('Y-m-d\TH:i'),
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 2);
    }

    public function test_los_bloqueos_se_filtran_por_rol(): void
    {
        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $user = $this->makeUser('Ana', UserRole::Participant, ['chat']);

        $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/usuarios?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'filter_type' => 'role',
                'role' => 'admin',
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name_raw', 'Admin');

        $this->actingAs($admin)
            ->getJson('/moderacion/bloqueos/usuarios?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'filter_type' => 'role',
                'role' => 'participant',
            ]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name_raw', 'Ana');
    }

    public function test_los_contadores_de_reportes_incluyen_los_tres_estados(): void
    {
        [$conversation, $message, $ana] = $this->conversationWithReport();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);

        $this->actingAs($admin)
            ->getJson('/moderacion/contadores')
            ->assertOk()
            ->assertJsonPath('reports', 1)
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('attending', 0)
            ->assertJsonPath('closed', 0);
    }

    public function test_el_reporte_pasa_por_atender_atendiendo_y_cerrado(): void
    {
        [$conversation, $message, $ana] = $this->conversationWithReport();

        $admin = $this->makeUser('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $otherAdmin = $this->makeUser('Otro Admin', UserRole::Admin, ['moderacion', 'chat']);
        $report = ChatReport::query()->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'Por atender')
            ->assertJsonPath('data.0.can_take', true)
            ->assertJsonPath('data.0.can_close', false);

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar', [
                'attention_observation' => 'Revisando el caso',
            ])
            ->assertOk()
            ->assertJsonPath('report.status_label', 'Atendiendo')
            ->assertJsonPath('report.assigned_raw', 'Admin');

        $this->assertDatabaseHas('chat_reports', [
            'id' => $report->id,
            'status' => ChatReport::STATUS_ATTENDING,
            'assigned_to' => $admin->id,
            'attention_observation' => 'Revisando el caso',
        ]);

        $this->assertStringContainsString(
            'Ir al chat',
            $this->actingAs($admin)->getJson('/moderacion/datos?draw=1&start=0&length=10')->json('data.0.actions')
        );

        // Otro administrador ya no puede tomarlo.
        $this->actingAs($otherAdmin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertStatus(409);

        $this->actingAs($otherAdmin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Intento ajeno',
                'confirmed' => 1,
            ])
            ->assertForbidden();

        // El asignado no puede cerrar sin observación ni sin confirmación.
        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', ['confirmed' => 1])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Caso resuelto',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Caso resuelto',
                'confirmed' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('report.status_label', 'Cerrado');

        $report->refresh();

        $this->assertSame(ChatReport::STATUS_CLOSED, $report->status);
        $this->assertSame($admin->id, $report->closed_by);
        $this->assertSame('Caso resuelto', $report->close_observation);
        $this->assertNotNull($report->closed_at);
        $this->assertNotNull($report->taken_at);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'Cerrado')
            ->assertJsonPath('data.0.can_take', false)
            ->assertJsonPath('data.0.can_close', false);

        $this->assertStringNotContainsString(
            'Ir al chat',
            $this->actingAs($admin)->getJson('/moderacion/datos?draw=1&start=0&length=10')->json('data.0.actions')
        );
    }

    public function test_las_acciones_de_caso_exigen_el_modulo_de_moderacion(): void
    {
        [$conversation] = $this->conversationWithReport();

        $user = $this->makeUser('Curioso', UserRole::Participant, ['chat']);
        $report = ChatReport::query()->firstOrFail();

        $this->actingAs($user)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'No debería',
                'confirmed' => 1,
            ])
            ->assertForbidden();
    }

    public function test_las_rutas_de_reporte_siguen_siendo_de_solo_lectura(): void
    {
        // La página del módulo la sirve el enrutador de módulos (GET); aquí
        // solo quedan las rutas de consulta con nombre propio.
        $reportRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getName() === 'moderacion.datos');

        $this->assertCount(1, $reportRoutes);

        foreach ($reportRoutes as $route) {
            $this->assertSame(
                [],
                array_values(array_diff($route->methods(), ['GET', 'HEAD'])),
                'La ruta '.$route->uri().' debería ser de solo lectura.'
            );
        }

        $this->post('/administracion/moderacion')->assertStatus(405);
    }
}
