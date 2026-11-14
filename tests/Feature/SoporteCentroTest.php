<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\ChatReportAttachment;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ReporteChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Centro de Ayuda y Soporte: tipos de caso, adjuntos, evidencia y chats.
 */
class SoporteCentroTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
    }

    private function usuario(string $name, UserRole $role = UserRole::Participant, array $modules = ['chat']): User
    {
        return $this->makeUser($name, $role, $modules);
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation, 3: ChatMessage}
     */
    private function conversacion(): array
    {
        $ana = $this->usuario('Ana');
        $luis = $this->usuario('Luis');

        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $ana->id]);
        $conversation->users()->attach([$ana->id, $luis->id]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $luis->id,
            'body' => 'Mensaje con contenido inapropiado',
        ]);

        return [$ana, $luis, $conversation, $message];
    }

    public function test_un_reporte_guarda_archivos_enlaces_y_evidencia_webp(): void
    {
        Storage::fake('local');

        [$ana, , , $message] = $this->conversacion();

        $file = UploadedFile::fake()->image('captura.png', 640, 480);

        $this->actingAs($ana)
            ->post('/chat/mensajes/'.$message->id.'/reportar', [
                'reason' => 'Insultos en el mensaje',
                'links' => ['https://example.com/contexto', 'https://example.com/otro'],
                'files' => [$file],
            ])
            ->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->assertSame(ChatReport::TYPE_MODERATION, $report->type);
        $this->assertDatabaseHas('chat_report_links', [
            'report_id' => $report->id,
            'url' => 'https://example.com/contexto',
        ]);
        $this->assertDatabaseHas('chat_report_links', [
            'report_id' => $report->id,
            'url' => 'https://example.com/otro',
        ]);

        $attachments = $report->attachments()->get();

        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->contains(fn (ChatReportAttachment $item) => $item->is_evidence));

        $evidence = $attachments->firstWhere('is_evidence', true);

        $this->assertNotNull($evidence);
        $this->assertSame('image/webp', $evidence->mime);
        $this->assertTrue(Storage::disk('local')->exists($evidence->path));

        $absolute = Storage::disk('local')->path($evidence->path);
        $this->assertGreaterThan(0, filesize($absolute));
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($absolute)[2]);

        $image = $attachments->first(fn (ChatReportAttachment $item) => ! $item->is_evidence);

        $this->assertNotNull($image);
        $this->assertSame(ChatReportAttachment::KIND_IMAGE, $image->kind);
        $this->assertStringStartsWith('reportes/'.$report->id.'/imagenes-videos/', $image->path);
    }

    public function test_el_detalle_del_caso_agrupa_adjuntos_enlaces_y_sugerencia(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_SUGGESTION,
            'reporter_id' => $ana->id,
            'reason' => 'Sugerencia: agregar un recordatorio semanal.',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.type', ChatReport::TYPE_SUGGESTION)
            ->assertJsonPath('report.type_label', 'Sugerencia')
            ->assertJsonPath('attachments.media', [])
            ->assertJsonPath('attachments.audios', [])
            ->assertJsonPath('attachments.documents', [])
            ->assertJsonPath('links', []);

        $this->assertSame('Sugerencia: agregar un recordatorio semanal.', $response->json('report.reason_full'));
    }

    public function test_el_tipo_de_caso_se_filtra_en_la_lista(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        ChatReport::create([
            'type' => ChatReport::TYPE_MODERATION,
            'reporter_id' => $ana->id,
            'reason' => 'Caso de moderación',
        ]);
        ChatReport::create([
            'type' => ChatReport::TYPE_SUGGESTION,
            'reporter_id' => $ana->id,
            'reason' => 'Caso de sugerencia',
        ]);

        $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10&filter_type=case_type&case_type=sugerencia')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.type_label', 'Sugerencia');
    }

    public function test_una_sugerencia_no_abre_chat_al_usuario_hasta_que_el_administrador_responde(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_SUGGESTION,
            'reporter_id' => $ana->id,
            'reason' => 'Sugerencia de mejora',
        ]);

        // Al abrir el caso no hay chat para el usuario.
        $this->assertNull($report->conversation);

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        $conversation = Conversation::query()->where('report_id', $report->id)->firstOrFail();

        $this->assertTrue($conversation->is_read_only);
        $this->assertSame(
            [$admin->id],
            $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/sugerencia', [
                'body' => 'Gracias por la sugerencia; la revisaremos.',
            ])
            ->assertOk();

        $conversation->refresh()->load('users');

        $this->assertTrue($conversation->is_read_only);
        $this->assertTrue($conversation->users->contains('id', $ana->id));
        $this->assertTrue($conversation->users->contains('id', $admin->id));
        $this->assertSame(
            'Gracias por la sugerencia; la revisaremos.',
            $conversation->messages()->where('user_id', $admin->id)->value('body')
        );

        // El usuario la ve en solo lectura y no puede escribir.
        $this->actingAs($ana)
            ->getJson('/chat/'.$conversation->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', true);

        $this->actingAs($ana)
            ->postJson('/chat/'.$conversation->id.'/mensajes', ['body' => 'Otra respuesta'])
            ->assertForbidden();
    }

    public function test_ayuda_y_soporte_abren_chat_de_solo_lectura_y_se_habilitan_al_tomar(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        foreach ([ChatReport::TYPE_HELP, ChatReport::TYPE_SUPPORT] as $type) {
            $report = ChatReport::create([
                'type' => $type,
                'reporter_id' => $ana->id,
                'reason' => 'Necesito ayuda con mi cuenta',
                'module_code' => 'chat',
                'context_label' => 'Chat',
            ]);

            $conversation = app(ReporteChat::class)->abrirTicket($report);

            $this->assertNotNull($conversation);
            $this->assertTrue($conversation->is_read_only);
            $this->assertSame(
                [$ana->id],
                $conversation->load('users')->users->pluck('id')->map(fn ($id) => (int) $id)->all()
            );

            $this->actingAs($admin)
                ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
                ->assertOk();

            $conversation->refresh()->load('users');

            $this->assertFalse($conversation->is_read_only);
            $this->assertTrue($conversation->users->contains('id', $admin->id));
            $this->assertTrue($conversation->users->contains('id', $ana->id));

            $this->actingAs($admin)
                ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                    'close_observation' => 'Caso resuelto',
                    'confirmed' => 1,
                ])
                ->assertOk();

            $this->assertTrue($conversation->fresh()->is_read_only);
        }
    }

    public function test_el_soporte_enlaza_al_modulo_donde_ocurrio_el_caso(): void
    {
        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_SUPPORT,
            'reporter_id' => $ana->id,
            'reason' => 'No puedo abrir el chat',
            'module_code' => 'chat',
            'context_label' => 'Chat',
        ]);

        $row = $this->actingAs($admin)
            ->getJson('/moderacion/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('data.0.type', ChatReport::TYPE_SUPPORT)
            ->assertJsonPath('data.0.module_code', 'chat')
            ->json('data.0');

        $this->assertNotNull($row['module_url']);
        $this->assertStringContainsString('Ir al módulo', $row['actions']);
    }

    public function test_el_widget_de_chat_tiene_el_formulario_de_reporte_con_archivos_y_enlaces(): void
    {
        $ana = $this->usuario('Ana');

        $this->actingAs($ana)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('modal-chat-report', false)
            ->assertSee('chat-report-dropzone', false)
            ->assertSee('chat-report-files', false)
            ->assertSee('Enlaces de soporte')
            ->assertSee('Archivos de soporte');
    }

    public function test_solo_la_administracion_descarga_los_adjuntos_del_caso(): void
    {
        Storage::fake('local');

        $admin = $this->usuario('Admin', UserRole::Admin, ['moderacion', 'chat']);
        $ana = $this->usuario('Ana');

        $report = ChatReport::create([
            'type' => ChatReport::TYPE_HELP,
            'reporter_id' => $ana->id,
            'reason' => 'Adjunto de prueba',
        ]);

        $path = 'reportes/'.$report->id.'/documentos/prueba.txt';
        Storage::disk('local')->put($path, 'contenido');

        $attachment = $report->attachments()->create([
            'kind' => ChatReportAttachment::KIND_DOCUMENT,
            'path' => $path,
            'name' => 'prueba.txt',
            'mime' => 'text/plain',
            'size' => 9,
        ]);

        $this->actingAs($ana)
            ->get('/moderacion/adjuntos/'.$attachment->id)
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/moderacion/adjuntos/'.$attachment->id)
            ->assertOk();
    }
}
