<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatReport;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GrantsModules;
use Tests\TestCase;

/**
 * Prerregistro público: la pantalla de acceso y la solicitud que crea el caso.
 */
class SolicitudRegistroTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function administrador(): User
    {
        return $this->makeUser('Admin Solicitudes', UserRole::Admin, ['moderacion', 'usuarios']);
    }

    private function perfilUsuario(): Profile
    {
        return Profile::query()->firstOrCreate(
            ['slug' => 'usuario'],
            ['name' => 'Usuario', 'is_active' => true]
        );
    }

    public function test_la_pantalla_de_acceso_define_la_confirmacion_del_prerregistro(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Solicitar registro')
            ->assertSee('confirmDialog(', false)
            ->assertSee('solicitud-registro-form', false)
            ->assertSee("event.key !== 'Enter'", false)
            ->assertSee('solicitud-registro-enviar', false);

        // La confirmación es compartida desde dialogos.js, no una copia local.
        $this->assertStringContainsString(
            'window.confirmDialog = function',
            file_get_contents(public_path('js/dialogos.js'))
        );
    }

    public function test_el_prerregistro_crea_un_caso_de_usuarios(): void
    {
        $response = $this->postJson('/solicitudes/registro', [
            'name' => 'Participante Nuevo',
            'email' => 'participante.nuevo@example.com',
            'username' => 'participante.nuevo',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $response->assertJsonPath('ticket', $report->id);
        $this->assertSame(ChatReport::TYPE_USER_REGISTRATION, $report->type);
        $this->assertSame(ChatReport::STATUS_PENDING, $report->status);
        $this->assertSame('participante.nuevo', $report->payload['username']);
        $this->assertSame('participante.nuevo@example.com', $report->payload['email']);

        $this->postJson('/solicitudes/registro', [
            'name' => 'Participante Nuevo',
            'email' => 'participante.nuevo@example.com',
            'username' => 'participante.nuevo',
        ])->assertStatus(422);
    }

    public function test_no_permite_otra_solicitud_abierta_con_el_mismo_correo(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Primera Solicitud',
            'email' => 'mismo-correo@example.com',
            'username' => 'primera.solicitud',
        ])->assertOk();

        $this->postJson('/solicitudes/registro', [
            'name' => 'Segunda Solicitud',
            'email' => 'mismo-correo@example.com',
            'username' => 'segunda.solicitud',
        ])->assertStatus(422);

        $this->assertSame(1, ChatReport::query()->where('type', ChatReport::TYPE_USER_REGISTRATION)->count());
    }

    public function test_no_permite_solicitud_con_correo_ya_registrado(): void
    {
        User::factory()->create([
            'name' => 'Cuenta Existente',
            'email' => 'cuenta-existente@example.com',
            'username' => 'cuenta.existente',
        ]);

        $this->postJson('/solicitudes/registro', [
            'name' => 'Otra Persona',
            'email' => 'cuenta-existente@example.com',
            'username' => 'otra.persona',
        ])->assertStatus(422);
    }

    public function test_el_caso_de_registro_no_abre_chat_y_ofrece_crear_usuario(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Persona Registro',
            'email' => 'registro.chat@example.com',
            'username' => 'registro.chat',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $taken = $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk()
            ->assertJsonPath('ticket_chat_id', null);

        $createUrl = (string) $taken->json('report.create_user_url');
        $this->assertStringContainsString('solicitud='.$report->id, $createUrl);

        $this->assertDatabaseMissing('chat_conversations', [
            'report_id' => $report->id,
        ]);

        $detail = $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.ticket_chat_url', null);

        $this->assertStringContainsString(
            'solicitud='.$report->id,
            (string) $detail->json('report.create_user_url')
        );

        $usuariosPath = parse_url(module_url('usuarios'), PHP_URL_PATH);
        $this->perfilUsuario();

        $this->actingAs($admin)
            ->get($usuariosPath.'?solicitud='.$report->id)
            ->assertOk()
            ->assertSee('usuarios-solicitud', false)
            ->assertSee('data-slug="usuario"', false)
            ->assertSee('Persona Registro');
    }

    public function test_al_crear_el_usuario_desde_la_solicitud_el_caso_no_se_cierra(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Persona Formalizada',
            'email' => 'formalizada@example.com',
            'username' => 'formalizada',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        $profile = $this->perfilUsuario();

        $this->actingAs($admin)
            ->postJson(route('usuarios.crear'), [
                'name' => 'Persona Formalizada',
                'email' => 'formalizada@example.com',
                'username' => 'formalizada',
                'profile_id' => $profile->id,
                'solicitud' => $report->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'email' => 'formalizada@example.com',
            'username' => 'formalizada',
            'profile_id' => $profile->id,
        ]);

        // La solicitud sigue abierta: se cierra desde su propio ticket.
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->refresh()->status);
    }

    public function test_no_se_puede_crear_un_usuario_con_correo_duplicado(): void
    {
        User::factory()->create([
            'name' => 'Cuenta Original',
            'email' => 'duplicado@example.com',
            'username' => 'cuenta.original',
        ]);

        $admin = $this->administrador();
        $profile = $this->perfilUsuario();

        $this->actingAs($admin)
            ->postJson(route('usuarios.crear'), [
                'name' => 'Cuenta Duplicada',
                'email' => 'duplicado@example.com',
                'username' => 'cuenta.duplicada',
                'profile_id' => $profile->id,
            ])
            ->assertStatus(422);

        $this->assertSame(1, User::query()->where('email', 'duplicado@example.com')->count());
    }
}
