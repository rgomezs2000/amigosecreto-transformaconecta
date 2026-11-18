<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Contacto y soporte del usuario y creación manual de tickets.
 */
class ContactoSoporteTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function usuario(string $name, array $modules = ['contacto-soporte', 'chat']): User
    {
        return $this->makeUser($name, UserRole::Participant, $modules);
    }

    private function administrador(string $name = 'Admin'): User
    {
        return $this->makeUser($name, UserRole::Admin, ['moderacion', 'chat']);
    }

    /**
     * @return array<string, int>
     */
    private function ratings(): array
    {
        return [
            'general' => 5,
            'funcionalidad' => 4,
            'dinamica' => 3,
            'soporte' => 2,
            'amigabilidad' => 1,
            'comunicacion' => 5,
        ];
    }

    public function test_el_usuario_ve_contacto_y_seguimiento_y_el_administrador_no(): void
    {
        $user = $this->usuario('Ana');

        $this->actingAs($user)
            ->get('/ayuda/contacto-soporte')
            ->assertOk()
            ->assertSee('Crear ticket')
            ->assertSee('Seguimiento')
            ->assertSee('Tipo de reporte')
            ->assertSee('Crear reporte')
            ->assertSee('Limpiar')
            ->assertSee('modal-confirmar-reporte', false)
            ->assertSee('seguimiento-table', false);

        $admin = $this->administrador();

        $this->actingAs($admin)
            ->get('/ayuda/contacto-soporte')
            ->assertForbidden();
    }

    public function test_un_caso_de_ayuda_crea_chat_de_solo_lectura_y_avisa_al_usuario(): void
    {
        $user = $this->usuario('Ana');
        $file = UploadedFile::fake()->image('captura.png', 320, 240);

        $response = $this->actingAs($user)->post('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_HELP,
            'reason' => 'No puedo entrar al sistema',
            'message' => 'Al abrir el panel me aparece un error.',
            'links' => ['https://example.com/ayuda'],
            'files' => [$file],
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $response->assertJsonPath('ticket', $report->id);
        $this->assertSame(ChatReport::TYPE_HELP, $report->type);
        $this->assertSame($user->id, $report->reporter_id);
        $this->assertSame('Al abrir el panel me aparece un error.', $report->case_message);
        $this->assertDatabaseHas('chat_report_links', [
            'report_id' => $report->id,
            'url' => 'https://example.com/ayuda',
        ]);
        $this->assertSame(1, $report->attachments()->count());

        $conversation = Conversation::query()->where('report_id', $report->id)->firstOrFail();

        $this->assertTrue($conversation->is_read_only);
        $this->assertSame([$user->id], $conversation->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all());

        $body = (string) $conversation->messages()->orderBy('id')->value('body');

        $this->assertStringContainsString('No puedo entrar al sistema', $body);
        $this->assertStringContainsString('Al abrir el panel me aparece un error.', $body);

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_una_sugerencia_guarda_las_seis_valoraciones_y_no_abre_chat(): void
    {
        $user = $this->usuario('Ana');

        $this->actingAs($user)->post('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_SUGGESTION,
            'reason' => 'Mejorar el panel',
            'message' => 'La pantalla de inicio puede ser más clara.',
            'suggestion' => 'Agregar un acceso directo a mis casos.',
            'ratings' => $this->ratings(),
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->assertSame(6, $report->ratings()->count());
        $this->assertDatabaseHas('chat_report_ratings', [
            'report_id' => $report->id,
            'aspect' => 'general',
            'score' => 5,
        ]);
        $this->assertNull(Conversation::query()->where('report_id', $report->id)->first());

        $detail = $this->actingAs($user)
            ->getJson('/contacto-soporte/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('ratings.0.aspect', 'general')
            ->assertJsonPath('ratings.0.qualitative', 'Excelente');

        $detail->assertJsonMissingPath('ratings_total');
        $detail->assertJsonMissingPath('ratings_average');
        $detail->assertJsonMissingPath('ratings.0.score');
    }

    public function test_el_usuario_solo_ve_sus_propios_casos(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        ChatReport::create([
            'type' => ChatReport::TYPE_SUPPORT,
            'reporter_id' => $ana->id,
            'reason' => 'Caso de Ana',
            'case_message' => 'Detalle de Ana',
        ]);

        ChatReport::create([
            'type' => ChatReport::TYPE_HELP,
            'reporter_id' => $luis->id,
            'reason' => 'Caso de Luis',
            'case_message' => 'Detalle de Luis',
        ]);

        $this->actingAs($ana)
            ->getJson('/contacto-soporte/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.reason', 'Caso de Ana');
    }

    public function test_el_usuario_puede_crear_un_caso_de_usuarios_sin_chat(): void
    {
        $user = $this->usuario('Ana');

        $this->actingAs($user)
            ->get('/ayuda/contacto-soporte')
            ->assertOk()
            ->assertSee('<option value="registro">Usuarios</option>', false);

        $response = $this->actingAs($user)->post('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_USER_REGISTRATION,
            'reason' => 'Necesito una cuenta',
            'message' => 'No puedo acceder con mi cuenta.',
        ])->assertOk()->assertJsonPath('conversation_id', null);

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $response->assertJsonPath('ticket', $report->id);
        $this->assertSame(ChatReport::TYPE_USER_REGISTRATION, $report->type);
        $this->assertDatabaseMissing('chat_conversations', ['report_id' => $report->id]);
    }

    public function test_el_usuario_descarga_sus_adjuntos_pero_no_los_de_otro(): void
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $this->actingAs($ana)->post('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_SUPPORT,
            'reason' => 'Adjunto de soporte',
            'message' => 'Revisar el archivo.',
            'files' => [UploadedFile::fake()->create('reporte.pdf', 10, 'application/pdf')],
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $attachment = $report->attachments()->firstOrFail();

        $this->actingAs($ana)
            ->get('/contacto-soporte/adjuntos/'.$attachment->id)
            ->assertOk();

        $this->actingAs($luis)
            ->get('/contacto-soporte/adjuntos/'.$attachment->id)
            ->assertForbidden();
    }

    public function test_un_administrador_crea_un_ticket_asignado_a_si_mismo(): void
    {
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->get('/administracion/moderacion')
            ->assertOk()
            ->assertSee('Crear ticket')
            ->assertSee('moderacion-nuevo-ticket', false)
            ->assertSee('modal-moderacion-nuevo-ticket', false);

        $response = $this->actingAs($admin)->post('/moderacion/reportes', [
            'type' => ChatReport::TYPE_SUPPORT,
            'reason' => 'Caso recibido por teléfono',
            'message' => 'El usuario reportó un problema con su perfil.',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $response->assertJsonPath('ticket', $report->id);
        $this->assertSame($admin->id, $report->reporter_id);
        $this->assertSame($admin->id, $report->assigned_to);
        $this->assertSame($admin->id, $report->taken_by);
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->status);
        $this->assertSame('El usuario reportó un problema con su perfil.', $report->case_message);
        $this->assertNotNull(Conversation::query()->where('report_id', $report->id)->first());
    }

    public function test_el_detalle_administrativo_muestra_mensaje_y_valoraciones(): void
    {
        $admin = $this->administrador();

        $this->actingAs($admin)->post('/moderacion/reportes', [
            'type' => ChatReport::TYPE_SUGGESTION,
            'reason' => 'Sugerencia de mejora',
            'message' => 'Detalle de la sugerencia.',
            'suggestion' => 'Agregar un buscador global.',
            'ratings' => $this->ratings(),
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.report_message', 'Detalle de la sugerencia.')
            ->assertJsonPath('ratings.0.aspect', 'general')
            ->assertJsonPath('ratings.0.score', 5)
            ->assertJsonPath('ratings_total', 20)
            ->assertJsonPath('ratings_average', 3.33);
    }

    public function test_el_usuario_puede_escribir_en_el_chat_cuando_el_administrador_toma_el_caso(): void
    {
        $user = $this->usuario('Ana');
        $admin = $this->administrador();

        $this->actingAs($user)->post('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_HELP,
            'reason' => 'No puedo entrar',
            'message' => 'Necesito ayuda con mi acceso.',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post('/moderacion/reportes/'.$report->id.'/tomar', [
                'observation' => 'Revisando el caso.',
            ])
            ->assertOk();

        $conversation = Conversation::query()->where('report_id', $report->id)->firstOrFail();
        $this->assertFalse($conversation->is_read_only);

        $this->actingAs($user)
            ->postJson('/contacto-soporte/reportes/'.$report->id.'/mensajes', [
                'body' => 'Ya intenté de nuevo y sigue el error.',
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'Ya intenté de nuevo y sigue el error.');

        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Ya intenté de nuevo y sigue el error.',
        ]);

        $this->actingAs($user)
            ->getJson('/contacto-soporte/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('chat.read_only', false)
            ->assertJsonPath('chat.messages.1.body', 'Ya intenté de nuevo y sigue el error.');
    }

    public function test_la_encuesta_exige_los_seis_aspectos(): void
    {
        $user = $this->usuario('Ana');

        $this->actingAs($user)->postJson('/contacto-soporte/reportes', [
            'type' => ChatReport::TYPE_SUGGESTION,
            'reason' => 'Sugerencia incompleta',
            'message' => 'Falta valorar.',
            'suggestion' => 'Una sugerencia.',
            'ratings' => ['general' => 5],
        ])->assertStatus(422);

        $this->assertSame(0, ChatReport::query()->count());
    }

    public function test_el_filtro_de_tipo_esta_antes_del_estado_en_administracion(): void
    {
        $admin = $this->administrador();

        $html = $this->actingAs($admin)
            ->get('/administracion/moderacion')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="case_type">Tipo de caso<\/option>\s*<option value="status">Estado<\/option>/',
            $html
        );
    }
}
