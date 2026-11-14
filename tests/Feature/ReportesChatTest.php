<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Chat de cada reporte (ticket) por fases.
 *
 * - Al abrir el caso nace el chat en solo lectura, solo para el beneficiario.
 * - Al tomarlo, el administrador entra y la escritura se habilita para ambos.
 * - Al cerrarlo, vuelve a solo lectura para los dos.
 */
class ReportesChatTest extends TestCase
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

    private function usuario(string $name, UserRole $role = UserRole::Participant, array $modules = ['chat']): User
    {
        return $this->makeUser($name, $role, $modules);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: User, 4: ChatReport}
     */
    private function escenario(): array
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['chat', 'moderacion']);
        $otroAdmin = $this->usuario('Otro Admin', UserRole::Admin, ['chat', 'moderacion']);
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

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

        return [$admin, $otroAdmin, $ana, $luis, ChatReport::query()->latest('id')->firstOrFail()];
    }

    private function tomar(User $admin, ChatReport $report): Conversation
    {
        $response = $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar', [
                'attention_observation' => 'Revisando el caso',
            ])
            ->assertOk();

        $this->assertNotNull($response->json('ticket_chat_id'));

        return Conversation::query()->where('report_id', $report->id)->firstOrFail();
    }

    public function test_el_ticket_nace_con_chat_en_solo_lectura_para_el_beneficiario(): void
    {
        Mail::fake();

        [$admin, , $ana, , $report] = $this->escenario();
        $conversation = $report->conversation;

        $this->assertNotNull($conversation);
        $this->assertSame('report', $conversation->type);
        $this->assertSame('Reporte Nº: '.$report->id, $conversation->name);
        $this->assertTrue($conversation->is_read_only);

        // Solo el beneficiario está dentro; ningún administrador todavía.
        $members = $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$ana->id], $members);

        $this->assertSame('Reporte Nº: '.$report->id, $conversation->messages()->orderBy('id')->value('body'));

        // El beneficiario recibe el correo y el push de apertura; la
        // administración solo el push (el correo del caso sale al tomarlo).
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($ana->email));
        Mail::assertNotSent(NotificationMail::class, fn ($mail) => $mail->hasTo($admin->email));

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $ana->id)
                ->where('type', 'chat.reporte')
                ->exists()
        );

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $admin->id)
                ->where('type', 'chat.reporte')
                ->exists()
        );

        // Mientras esté por atender, el chat es de solo lectura.
        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', true)
            ->assertJsonPath('read_only_label', 'Caso por atender: el chat se habilita cuando la administración lo tome.');

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola'])
            ->assertForbidden();

        // El administrador no entra hasta tomarlo.
        $this->actingAs($admin)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertForbidden();

        $this->assertSame([], $this->actingAs($admin)->getJson('/chat/resumen')->assertOk()->json('reports'));
    }

    public function test_al_tomar_el_caso_se_habilita_el_chat_para_ambos(): void
    {
        Mail::fake();

        [$admin, , $ana, , $report] = $this->escenario();
        $conversation = $this->tomar($admin, $report);

        $this->assertFalse($conversation->is_read_only);

        $members = $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(
            collect([$ana->id, $admin->id])->sort()->values()->all(),
            $members
        );

        // Correo del caso para el administrador que lo gestiona y aviso de la
        // nueva fase para el beneficiario.
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($ana->email));

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $ana->id)
                ->get()
                ->contains(fn (Notification $item) => (int) ($item->data['conversation_id'] ?? 0) === (int) $conversation->id
                    && ($item->data['report_phase'] ?? '') === 'atendiendo')
        );

        // El chat del caso aparece en la pestaña Reportes para los dos.
        $this->assertSame(
            $conversation->id,
            $this->actingAs($ana)->getJson('/chat/resumen')->assertOk()->json('reports.0.id')
        );
        $this->assertSame(
            $conversation->id,
            $this->actingAs($admin)->getJson('/chat/resumen')->assertOk()->json('reports.0.id')
        );

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', false);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola, soy Ana'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola, soy la administración'])
            ->assertOk();
    }

    public function test_otro_administrador_ve_la_ficha_pero_no_el_chat(): void
    {
        [$admin, $otroAdmin, $ana, $luis, $report] = $this->escenario();
        $conversation = $report->conversation;

        // Por atender: otro administrador puede ver la ficha y tomarlo, pero
        // no entra al chat (ni aparece el enlace).
        $row = $this->actingAs($otroAdmin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($row['can_take']);
        $this->assertSame('Ana', $row['reporter_raw']);
        $this->assertSame('Insultos', $row['reason_full']);
        $this->assertNull($row['ticket_chat_url']);
        $this->assertStringNotContainsString('Abrir el chat del caso', $row['actions']);

        $this->actingAs($otroAdmin)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertForbidden();

        // Tomado por otro: puede monitorear la ficha, pero no gestionarlo ni
        // entrar al chat del caso.
        $this->tomar($admin, $report);

        $row = $this->actingAs($otroAdmin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($row['can_take']);
        $this->assertFalse($row['can_close']);
        $this->assertSame('Admin', $row['assigned_raw']);
        $this->assertNull($row['ticket_chat_url']);

        $this->actingAs($otroAdmin)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertForbidden();

        $this->assertSame([], $this->actingAs($otroAdmin)->getJson('/chat/resumen')->assertOk()->json('reports'));

        // El asignado sí tiene el enlace y el chat.
        $row = $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertSame($conversation->id, $row['ticket_chat_id']);
        $this->assertStringContainsString('Abrir el chat del caso', $row['actions']);

        $this->actingAs($luis)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertForbidden();
    }

    public function test_al_cerrar_el_caso_el_chat_queda_en_solo_lectura(): void
    {
        Mail::fake();

        [$admin, , $ana, , $report] = $this->escenario();
        $conversation = $this->tomar($admin, $report);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Mensaje antes de cerrar'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Caso resuelto',
                'confirmed' => 1,
            ])
            ->assertOk();

        $conversation->refresh();

        $this->assertTrue($conversation->is_read_only);

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('read_only_label', 'Caso cerrado: chat de solo lectura.');

        // Ya no escribe ninguno de los dos; el chat sigue en la pestaña.
        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Tarde'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Tarde'])
            ->assertForbidden();

        $this->actingAs($ana)
            ->getJson('/chat/resumen')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $conversation->id)
            ->assertJsonPath('reports.0.read_only', true);

        // El cierre se avisa al beneficiario.
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($ana->email));
        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $ana->id)
                ->get()
                ->contains(fn (Notification $item) => ($item->data['report_phase'] ?? '') === 'cerrado')
        );
    }

    public function test_un_administrador_puede_gestionar_varios_casos(): void
    {
        [$admin, , $ana, , $report] = $this->escenario();

        // Segundo caso pendiente, reportado por la misma beneficiaria.
        $segundo = ChatReport::create([
            'reporter_id' => $ana->id,
            'reason' => 'Otro motivo',
        ]);

        $this->tomar($admin, $report);
        $this->tomar($admin, $segundo);

        $this->assertSame(
            [$report->id, $segundo->id],
            ChatReport::query()
                ->where('assigned_to', $admin->id)
                ->orderBy('id')
                ->pluck('id')
                ->all()
        );

        $this->assertSame(
            2,
            Conversation::query()->whereIn('report_id', [$report->id, $segundo->id])->where('is_read_only', false)->count()
        );
    }

    public function test_el_widget_tiene_la_pestana_de_reportes(): void
    {
        $ana = $this->usuario('Ana');

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-tab="reports"', false)
            ->assertSee('id="chat-reports"', false)
            ->assertSee('id="chat-reports-count"', false)
            ->assertSee('Reportes');
    }
}
