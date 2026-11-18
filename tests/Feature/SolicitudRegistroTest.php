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

    public function test_el_administrador_puede_crear_un_ticket_de_usuarios_sin_chat(): void
    {
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->get('/administracion/moderacion')
            ->assertOk()
            ->assertSee('<option value="registro">Usuarios</option>', false);

        $this->actingAs($admin)->post('/moderacion/reportes', [
            'type' => ChatReport::TYPE_USER_REGISTRATION,
            'reason' => 'Alta de usuario',
            'message' => 'El administrador registra el caso para crear la cuenta.',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();

        $this->assertSame(ChatReport::TYPE_USER_REGISTRATION, $report->type);
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->status);
        $this->assertDatabaseMissing('chat_conversations', ['report_id' => $report->id]);

        $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.ticket_chat_url', null)
            ->assertJsonPath('report.create_user_url', module_url('usuarios'));
    }

    public function test_una_solicitud_de_cuenta_tampoco_abre_chat(): void
    {
        $user = User::factory()->create([
            'name' => 'Cuenta Reset',
            'email' => 'reset@example.com',
            'username' => 'cuenta.reset',
        ]);

        $this->postJson('/solicitudes/recuperacion', [
            'login' => 'reset@example.com',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk()
            ->assertJsonPath('ticket_chat_id', null)
            ->assertJsonPath('report.account_action', 'reset')
            ->assertJsonPath('report.ticket_chat_url', null);

        $this->assertDatabaseMissing('chat_conversations', ['report_id' => $report->id]);
    }

    public function test_buscar_una_solicitud_abierta_la_toma_y_bloquea_si_es_de_otro(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Solicitud Buscada',
            'email' => 'buscada@example.com',
            'username' => 'solicitud.buscada',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();
        $otro = $this->makeUser('Otro Admin', UserRole::Admin, ['usuarios']);

        $response = $this->actingAs($admin)
            ->getJson('/usuarios/solicitud?email=buscada@example.com')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('solicitud.id', $report->id);

        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->refresh()->status);
        $this->assertSame($admin->id, $report->taken_by);

        $this->actingAs($otro)
            ->getJson('/usuarios/solicitud?username=solicitud.buscada')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('blocked', true)
            ->assertJsonPath('taken_by', $admin->name);

        $this->actingAs($otro)
            ->postJson(route('usuarios.crear'), [
                'name' => 'Solicitud Buscada',
                'email' => 'buscada@example.com',
                'username' => 'solicitud.buscada',
                'profile_id' => $this->perfilUsuario()->id,
            ])
            ->assertStatus(422);
    }

    public function test_crear_un_usuario_con_solicitud_abierta_la_toma_si_no_tiene_gestor(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Solicitud Sin Gestor',
            'email' => 'sin.gestor@example.com',
            'username' => 'sin.gestor',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson(route('usuarios.crear'), [
                'name' => 'Solicitud Sin Gestor',
                'email' => 'sin.gestor@example.com',
                'username' => 'sin.gestor',
                'profile_id' => $this->perfilUsuario()->id,
            ])
            ->assertOk();

        $this->assertSame($admin->id, $report->refresh()->taken_by);
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->status);
    }

    public function test_el_cambio_de_clave_atado_a_un_caso_respeta_al_gestor(): void
    {
        $user = User::factory()->create([
            'name' => 'Cuenta Clave',
            'email' => 'clave@example.com',
            'username' => 'cuenta.clave',
        ]);

        $this->postJson('/solicitudes/recuperacion', [
            'login' => 'clave@example.com',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();
        $otro = $this->makeUser('Otro Gestor', UserRole::Admin, ['usuarios']);
        $hashOriginal = $user->password;

        // Otro administrador no puede cambiar la clave si el caso no es suyo.
        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        $this->actingAs($otro)
            ->postJson('/usuarios/'.$user->id.'/restablecer')
            ->assertStatus(422);

        $this->assertSame($hashOriginal, $user->fresh()->password);

        // El gestor sí puede cambiarla; el caso queda abierto hasta cerrarlo.
        $this->actingAs($admin)
            ->postJson('/usuarios/'.$user->id.'/restablecer')
            ->assertOk()
            ->assertJsonPath('mail_sent', true);

        $this->assertNotSame($hashOriginal, $user->fresh()->password);
        $this->assertSame($admin->id, $report->refresh()->taken_by);
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->status);

        Mail::assertSent(\App\Mail\NotificationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_el_cambio_de_clave_toma_el_caso_si_no_tiene_gestor(): void
    {
        $user = User::factory()->create([
            'name' => 'Cuenta Libre',
            'email' => 'libre@example.com',
            'username' => 'cuenta.libre',
        ]);

        $this->postJson('/solicitudes/recuperacion', [
            'login' => 'libre@example.com',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/usuarios/'.$user->id.'/restablecer')
            ->assertOk()
            ->assertJsonPath('mail_sent', true);

        $this->assertSame($admin->id, $report->refresh()->taken_by);
        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->status);
    }

    public function test_no_se_puede_rechazar_un_registro_si_el_usuario_ya_existe(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Registro Existente',
            'email' => 'registro.existente@example.com',
            'username' => 'registro.existente',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        User::factory()->create([
            'name' => 'Registro Existente',
            'email' => 'registro.existente@example.com',
            'username' => 'registro.existente',
        ]);

        $this->actingAs($admin)
            ->postJson('/moderacion/solicitudes/'.$report->id.'/rechazar-registro', [
                'reason' => 'No aplica',
            ])
            ->assertStatus(422);

        $this->assertSame(ChatReport::STATUS_ATTENDING, $report->refresh()->status);
    }

    public function test_no_se_puede_crear_un_usuario_de_una_solicitud_rechazada(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Registro Rechazado',
            'email' => 'registro.rechazado@example.com',
            'username' => 'registro.rechazado',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/moderacion/solicitudes/'.$report->id.'/rechazar-registro', [
                'reason' => 'Datos incompletos',
            ])
            ->assertOk();

        $this->assertSame(ChatReport::STATUS_CLOSED, $report->refresh()->status);
        $this->assertNotEmpty(($report->payload ?: [])['rejected_at']);

        $this->actingAs($admin)
            ->postJson(route('usuarios.crear'), [
                'name' => 'Registro Rechazado',
                'email' => 'registro.rechazado@example.com',
                'username' => 'registro.rechazado',
                'profile_id' => $this->perfilUsuario()->id,
            ])
            ->assertStatus(422);
    }

    public function test_no_se_puede_rechazar_antes_de_tomar_el_caso(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Sin Tomar',
            'email' => 'sin.tomar@example.com',
            'username' => 'sin.tomar',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();

        $this->actingAs($admin)
            ->postJson('/moderacion/solicitudes/'.$report->id.'/rechazar-registro', [
                'reason' => 'Intento antes de tomar',
            ])
            ->assertStatus(422);

        $this->assertSame(ChatReport::STATUS_PENDING, $report->refresh()->status);
    }

    public function test_no_hay_acciones_de_gestion_antes_de_tomar_ni_despues_de_cerrar(): void
    {
        $this->postJson('/solicitudes/registro', [
            'name' => 'Caso Gestionable',
            'email' => 'gestionable@example.com',
            'username' => 'gestionable',
        ])->assertOk();

        $report = ChatReport::query()->latest('id')->firstOrFail();
        $admin = $this->administrador();
        $otro = $this->makeUser('Otro Para Remitir', UserRole::Admin, ['moderacion']);

        // Pendiente: solo se puede ver y tomar.
        $pending = $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.can_manage', false)
            ->assertJsonPath('report.create_user_url', null)
            ->assertJsonPath('report.ticket_chat_url', null);

        $this->assertSame('', $pending->json('report.account_action'));

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/remitir', [
                'admin_id' => $otro->id,
                'observation' => 'Todavía pendiente',
            ])
            ->assertStatus(422);

        // Tomado: ya se puede gestionar.
        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/tomar')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.can_manage', true);

        // Cerrado: no se vuelve a gestionar ni remitir.
        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/cerrar', [
                'close_observation' => 'Caso cerrado',
                'confirmed' => 1,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/moderacion/reportes/'.$report->id.'/detalle')
            ->assertOk()
            ->assertJsonPath('report.can_manage', false)
            ->assertJsonPath('report.create_user_url', null)
            ->assertJsonPath('report.ticket_chat_url', null);

        $this->actingAs($admin)
            ->postJson('/moderacion/reportes/'.$report->id.'/remitir', [
                'admin_id' => $otro->id,
                'observation' => 'Ya está cerrado',
            ])
            ->assertStatus(422);
    }
}
