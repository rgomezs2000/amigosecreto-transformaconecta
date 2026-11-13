<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\ChatReportReferral;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Remisión de un caso de reporte a otro administrador.
 *
 * Solo el gestor actual remite, con una observación obligatoria. El caso y su
 * chat pasan al nuevo gestor; el anterior deja de gestionarlo y de ver el chat.
 * El nuevo gestor puede remitirlo de vuelta.
 */
class ReportesRemisionTest extends TestCase
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
        $adminA = $this->usuario('Admin A', UserRole::Admin, ['chat', 'moderacion']);
        $adminB = $this->usuario('Admin B', UserRole::Admin, ['chat', 'moderacion']);
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

        return [$adminA, $adminB, $ana, $luis, ChatReport::query()->latest('id')->firstOrFail()];
    }

    private function tomar(User $admin, ChatReport $report): void
    {
        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar', [
                'attention_observation' => 'Revisando',
            ])
            ->assertOk();
    }

    private function remitir(User $admin, ChatReport $report, User $target, string $observation): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/remitir', [
                'admin_id' => $target->id,
                'observation' => $observation,
            ]);
    }

    public function test_solo_el_gestor_actual_puede_remitir(): void
    {
        [$adminA, $adminB, , , $report] = $this->escenario();

        // Caso por atender: todavía no se remite.
        $this->remitir($adminA, $report, $adminB, 'A ver si alguien lo toma')
            ->assertStatus(422);

        $this->tomar($adminA, $report);

        // Otro administrador no puede remitir un caso que no gestiona.
        $this->remitir($adminB, $report, $adminA, 'Intento ajeno')
            ->assertForbidden();

        // El gestor sí.
        $this->remitir($adminA, $report, $adminB, 'Te lo paso por carga de trabajo')
            ->assertOk()
            ->assertJsonPath('report.assigned_raw', 'Admin B');
    }

    public function test_remitir_valida_administrador_y_observacion(): void
    {
        [$adminA, $adminB, $ana, , $report] = $this->escenario();
        $this->tomar($adminA, $report);

        // Sin administrador.
        $this->actingAs($adminA)
            ->postJson('/moderacion/reportes/'.$report->id.'/remitir', [
                'observation' => 'Sin destino',
            ])
            ->assertStatus(422);

        // Sin observación.
        $this->actingAs($adminA)
            ->postJson('/moderacion/reportes/'.$report->id.'/remitir', [
                'admin_id' => $adminB->id,
            ])
            ->assertStatus(422);

        // A sí mismo.
        $this->remitir($adminA, $report, $adminA, 'A mí mismo')->assertStatus(422);

        // A una persona que no es administradora.
        $this->remitir($adminA, $report, $ana, 'A una participante')->assertStatus(422);

        // A un administrador inactivo.
        $inactivo = $this->usuario('Admin Inactivo', UserRole::Admin, ['chat', 'moderacion']);
        $inactivo->forceFill(['is_active' => false])->save();

        $this->remitir($adminA, $report, $inactivo, 'A un inactivo')->assertStatus(422);

        // A un administrador válido.
        $this->remitir($adminA, $report, $adminB, 'Motivo válido')->assertOk();
    }

    public function test_remitir_mueve_el_caso_y_el_chat(): void
    {
        Mail::fake();

        [$adminA, $adminB, $ana, , $report] = $this->escenario();
        $this->tomar($adminA, $report);

        $conversation = Conversation::query()->where('report_id', $report->id)->firstOrFail();

        $this->remitir($adminA, $report, $adminB, 'Te lo remito por carga de trabajo')
            ->assertOk();

        $report->refresh();

        $this->assertSame($adminB->id, (int) $report->assigned_to);

        $referral = ChatReportReferral::query()->where('report_id', $report->id)->firstOrFail();

        $this->assertSame($adminA->id, (int) $referral->from_admin_id);
        $this->assertSame($adminB->id, (int) $referral->to_admin_id);
        $this->assertSame('Te lo remito por carga de trabajo', $referral->observation);

        // El chat pasa al nuevo gestor y el anterior sale.
        $members = $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(
            collect([$ana->id, $adminB->id])->sort()->values()->all(),
            $members
        );

        $this->assertFalse($conversation->fresh()->is_read_only);

        // El anterior ya no entra al chat; el nuevo sí, y ambos escriben.
        $this->actingAs($adminA)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertForbidden();

        $this->assertSame(
            [],
            $this->actingAs($adminA)->getJson('/chat/resumen')->assertOk()->json('reports')
        );

        $this->actingAs($adminB)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Hola, tomo el caso'])
            ->assertOk();

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Gracias'])
            ->assertOk();

        // Avisos: al nuevo gestor y al beneficiario.
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($adminB->email));
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($ana->email));

        $this->assertTrue(
            Notification::query()
                ->where('notifiable_id', $adminB->id)
                ->get()
                ->contains(fn (Notification $item) => ($item->data['report_phase'] ?? '') === 'remitido')
        );
    }

    public function test_el_anterior_gestor_ya_no_cierra_y_puede_recibirla_de_vuelta(): void
    {
        [$adminA, $adminB, $ana, , $report] = $this->escenario();
        $this->tomar($adminA, $report);
        $this->remitir($adminA, $report, $adminB, 'Te lo paso')->assertOk();

        // El anterior ya no cierra ni remite.
        $this->actingAs($adminA)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Cierre ajeno',
                'confirmed' => 1,
            ])
            ->assertForbidden();

        $this->remitir($adminA, $report, $adminB, 'Otra vez')->assertForbidden();

        // El nuevo gestor se lo devuelve al anterior.
        $this->remitir($adminB, $report, $adminA, 'Te lo devuelvo')
            ->assertOk()
            ->assertJsonPath('report.assigned_raw', 'Admin A');

        $report->refresh();

        $this->assertSame($adminA->id, (int) $report->assigned_to);

        $conversation = Conversation::query()->where('report_id', $report->id)->firstOrFail();
        $members = $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($adminA->id, $members);
        $this->assertNotContains($adminB->id, $members);

        // Ahora sí puede cerrar.
        $this->actingAs($adminA)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Caso resuelto',
                'confirmed' => 1,
            ])
            ->assertOk();

        $this->assertTrue($conversation->fresh()->is_read_only);

        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', true);
    }

    public function test_no_se_remite_al_beneficiario_del_caso(): void
    {
        // Un administrador reporta: él es el beneficiario del ticket.
        $adminA = $this->usuario('Admin A', UserRole::Admin, ['chat', 'moderacion']);
        $adminB = $this->usuario('Admin B', UserRole::Admin, ['chat', 'moderacion']);
        $luis = $this->usuario('Luis');

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $adminB->id]);
        $conversation->users()->attach([$adminB->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje inapropiado',
        ]);

        $this->actingAs($adminB)
            ->postJson('/chat/mensajes/'.$message->id.'/reportar', ['reason' => 'Insultos'])
            ->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->tomar($adminA, $report);

        $this->remitir($adminA, $report, $adminB, 'Al beneficiario no')
            ->assertStatus(422);
    }

    public function test_la_ficha_muestra_las_remisiones_y_el_boton_solo_al_gestor(): void
    {
        [$adminA, $adminB, , , $report] = $this->escenario();
        $this->tomar($adminA, $report);
        $this->remitir($adminA, $report, $adminB, 'Motivo de la remisión')->assertOk();

        $rowB = $this->actingAs($adminB)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($rowB['can_refer']);
        $this->assertTrue($rowB['can_close']);
        $this->assertSame('Admin A', $rowB['referrals'][0]['from']);
        $this->assertSame('Admin B', $rowB['referrals'][0]['to']);
        $this->assertSame('Motivo de la remisión', $rowB['referrals'][0]['observation']);

        $rowA = $this->actingAs($adminA)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($rowA['can_refer']);
        $this->assertFalse($rowA['can_close']);
        $this->assertNull($rowA['ticket_chat_url']);
    }

    public function test_no_se_remite_a_un_administrador_sin_acceso_a_moderacion(): void
    {
        [$adminA, $adminB, , , $report] = $this->escenario();
        $sinModulo = $this->usuario('Admin sin módulo', UserRole::Admin, ['chat']);

        $this->tomar($adminA, $report);

        $this->remitir($adminA, $report, $sinModulo, 'No tiene el módulo')
            ->assertStatus(422);

        $items = collect(
            $this->actingAs($adminA)
                ->getJson('/moderacion/administradores')
                ->assertOk()
                ->json('items')
        );

        $this->assertFalse($items->pluck('id')->contains($sinModulo->id));
        $this->assertTrue($items->pluck('id')->contains($adminB->id));
    }

    public function test_el_combobox_lista_los_administradores_activos(): void
    {
        [$adminA, $adminB] = $this->escenario();

        $items = collect(
            $this->actingAs($adminA)
                ->getJson('/moderacion/administradores')
                ->assertOk()
                ->json('items')
        );


        $this->assertTrue($items->pluck('id')->contains($adminB->id));
        $this->assertFalse($items->pluck('id')->contains($adminA->id));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function filas(User $admin, array $params = []): array
    {
        $url = '/moderacion/datos?draw=1&start=0&length=10&'.http_build_query($params);

        return $this->actingAs($admin)->getJson($url)->assertOk()->json('data');
    }

    public function test_la_lista_muestra_gestor_original_y_remitido(): void
    {
        [$adminA, $adminB, , , $report] = $this->escenario();
        $this->tomar($adminA, $report);

        $row = $this->filas($adminA)[0];
        $this->assertSame('Admin A', $row['manager_raw']);
        $this->assertSame('—', $row['referred_to_raw']);

        $this->remitir($adminA, $report, $adminB, 'Primera remisión')->assertOk();

        $row = $this->filas($adminB)[0];
        // El gestor original no cambia con la remisión.
        $this->assertSame('Admin A', $row['manager_raw']);
        $this->assertSame('Admin B', $row['referred_to_raw']);

        // Si se la devuelven, «Remitido a» pasa a ser el anterior.
        $this->remitir($adminB, $report, $adminA, 'De vuelta')->assertOk();

        $row = $this->filas($adminA)[0];
        $this->assertSame('Admin A', $row['manager_raw']);
        $this->assertSame('Admin A', $row['referred_to_raw']);
    }

    public function test_los_filtros_gestionado_por_y_remitido_a(): void
    {
        [$adminA, $adminB, , , $report] = $this->escenario();
        $this->tomar($adminA, $report);
        $this->remitir($adminA, $report, $adminB, 'Motivo de la remisión')->assertOk();

        // «Gestionado por» filtra al gestor original, aunque ya no lo tenga.
        $this->assertCount(1, $this->filas($adminB, [
            'filter_type' => 'manager',
            'manager_id' => $adminA->id,
        ]));
        $this->assertCount(0, $this->filas($adminB, [
            'filter_type' => 'manager',
            'manager_id' => $adminB->id,
        ]));

        // «Remitido a» filtra por el último administrador remitido.
        $this->assertCount(1, $this->filas($adminB, [
            'filter_type' => 'referred',
            'referred_to_id' => $adminB->id,
        ]));
        $this->assertCount(0, $this->filas($adminB, [
            'filter_type' => 'referred',
            'referred_to_id' => $adminA->id,
        ]));

        // Si se la devuelven a A, el filtro de remitido a A la encuentra.
        $this->remitir($adminB, $report, $adminA, 'De vuelta')->assertOk();

        $this->assertCount(1, $this->filas($adminA, [
            'filter_type' => 'referred',
            'referred_to_id' => $adminA->id,
        ]));
        $this->assertCount(0, $this->filas($adminA, [
            'filter_type' => 'referred',
            'referred_to_id' => $adminB->id,
        ]));
    }

    public function test_la_pantalla_tiene_las_columnas_y_filtros_de_gestores(): void
    {
        [$adminA] = $this->escenario();

        $this->actingAs($adminA)
            ->get(module_url('moderacion'))
            ->assertOk()
            ->assertSee('Gestionado por')
            ->assertSee('Remitido a')
            ->assertSee('id="moderacion-filter-manager"', false)
            ->assertSee('id="moderacion-filter-referred"', false)
            ->assertSee('id="moderacion-detail-manager"', false)
            ->assertSee('id="moderacion-detail-referred"', false);
    }

    public function test_la_pantalla_tiene_el_modal_de_remision(): void
    {
        [$adminA] = $this->escenario();

        $this->actingAs($adminA)
            ->get(module_url('moderacion'))
            ->assertOk()
            ->assertSee('id="modal-moderacion-refer"', false)
            ->assertSee('id="moderacion-detail-refer"', false)
            ->assertSee('id="moderacion-refer-search"', false)
            ->assertSee('id="moderacion-refer-observation"', false)
            ->assertSee('id="moderacion-detail-referrals"', false);
    }
}


