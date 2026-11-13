<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use App\Models\UserPermissionModule;
use App\Services\DinamicaProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DinamicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El panel lanza el comando en un proceso aparte; en las pruebas se
        // ejecuta en la misma petición para poder verificar el resultado.
        config(['dinamica.async' => false, 'dinamica.path' => storage_path('app/dinamica-test')]);

        Mail::fake();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 904.4500,
                'fechaActualizacion' => '2026-11-12T00:00:00-04:00',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        // Limpia los archivos de estado de las ejecuciones de la prueba.
        foreach (glob(storage_path('app/dinamica-test').DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::superAdmin();

        $this->assertNotNull($user, 'Debe existir el superadministrador.');

        return $user;
    }

    /**
     * Otro administrador: perfil Administrador, pero no es el superadministrador.
     */
    private function otherAdmin(): User
    {
        $profile = Profile::query()->where('slug', 'administrador')->firstOrFail();

        return User::factory()->create([
            'name' => 'Otro Administrador',
            'username' => 'otro-admin',
            'profile_id' => $profile->id,
            'role' => \App\Enums\UserRole::Admin,
        ]);
    }

    /**
     * Carga la dinámica de un evento con chats, moderación y actividad.
     */
    private function seedDinamica(): void
    {
        $participantProfile = Profile::query()->where('slug', 'usuario')->value('id');

        $ana = User::factory()->create([
            'name' => 'Ana',
            'username' => 'ana',
            'profile_id' => $participantProfile,
        ]);

        $luis = User::factory()->create([
            'name' => 'Luis',
            'username' => 'luis',
            'profile_id' => $participantProfile,
        ]);

        $eventId = DB::table('events')->insertGetId([
            'name' => 'Evento de prueba',
            'year' => (int) now()->year,
            'status' => 'drawn',
            'exchange_at' => now()->addDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $participationId = DB::table('event_participants')->insertGetId([
            'event_id' => $eventId,
            'user_id' => $ana->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = DB::table('assignments')->insertGetId([
            'event_id' => $eventId,
            'giver_id' => $ana->id,
            'receiver_id' => $luis->id,
            'drawn_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wishlists')->insert([
            'event_participant_id' => $participationId,
            'likes' => 'Audífonos',
            'avoid' => 'Perfume',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('clues')->insert([
            'assignment_id' => $assignmentId,
            'body' => 'Pista',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversationId = DB::table('chat_conversations')->insertGetId([
            'name' => 'Amigo secreto',
            'type' => 'secret',
            'created_by' => $ana->id,
            'event_id' => $eventId,
            'assignment_id' => $assignmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_conversation_user')->insert([
            'conversation_id' => $conversationId,
            'user_id' => $ana->id,
        ]);

        $messageId = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'user_id' => $ana->id,
            'body' => 'Hola',
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_message_attachments')->insert([
            'message_id' => $messageId,
            'type' => 'image',
            'path' => 'chat/images/1/foto.png',
            'name' => 'foto.png',
            'mime' => 'image/png',
            'size' => 1024,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_reports')->insert([
            'message_id' => $messageId,
            'reporter_id' => $luis->id,
            'reason' => 'Prueba',
            'status' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_blocks')->insert([
            'blocker_id' => $ana->id,
            'blocked_id' => $luis->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\General',
            'notifiable_type' => User::class,
            'notifiable_id' => $ana->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('email_logs')->insert([
            'user_id' => $ana->id,
            'to_email' => $ana->email,
            'subject' => 'Bienvenida',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'event_id' => $eventId,
            'user_id' => $ana->id,
            'action' => 'event.created',
        ]);
    }

    public function test_el_modulo_aparece_solo_para_el_superadministrador(): void
    {
        $this->seed();

        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->get('/administracion/restablecer-dinamica')
            ->assertOk()
            ->assertSee('Restablecer Amigo Secreto');

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Restablecer dinámica');

        $other = $this->otherAdmin();

        $this->actingAs($other)
            ->get('/administracion/restablecer-dinamica')
            ->assertForbidden();

        $this->actingAs($other)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Restablecer dinámica');

        $participant = User::factory()->create([
            'profile_id' => Profile::query()->where('slug', 'usuario')->value('id'),
        ]);

        $this->actingAs($participant)
            ->get('/administracion/restablecer-dinamica')
            ->assertForbidden();
    }

    public function test_otro_administrador_no_puede_iniciar_el_restablecimiento(): void
    {
        $this->seed();

        $other = $this->otherAdmin();

        $this->actingAs($other)
            ->postJson('/administracion/restablecer-dinamica')
            ->assertForbidden();

        $this->actingAs($other)
            ->getJson('/administracion/restablecer-dinamica/progreso?run='.Str::uuid())
            ->assertForbidden();

        // Nadie más pudo lanzar el proceso: no hay ejecuciones registradas.
        $this->assertNull(app(DinamicaProgress::class)->latest());
    }

    public function test_el_restablecimiento_deja_el_sistema_en_cero(): void
    {
        $this->seed();
        $this->seedDinamica();

        $superAdmin = $this->superAdmin();
        $masterCounts = $this->masterCounts();

        $this->actingAs($superAdmin)
            ->postJson('/administracion/restablecer-dinamica')
            ->assertOk()
            ->assertJson(['success' => true]);

        // La ejecución terminó bien y la bitácora quedó registrada.
        $state = app(DinamicaProgress::class)->latest();

        $this->assertNotNull($state);
        $this->assertSame(DinamicaProgress::DONE, $state['status']);
        $this->assertSame(100, $state['percent']);
        $this->assertNotEmpty($state['lines']);

        // Nada de dinámica.
        foreach ([
            'events', 'event_participants', 'assignments', 'wishlists', 'clues',
            'exchange_gifts', 'consumable_gifts', 'chat_conversations', 'chat_messages',
            'chat_message_attachments', 'chat_reports', 'chat_blocks', 'chat_moderation_reads',
            'chat_group_audits', 'chat_group_bans', 'chat_group_suspensions',
            'chat_group_invitations', 'notifications', 'audit_logs',
            'notification_preferences',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'La tabla '.$table.' debería quedar vacía.');
        }

        // En `email_logs` solo quedan los avisos del restablecimiento.
        $this->assertSame(2, DB::table('email_logs')->count());
        $this->assertSame(2, DB::table('email_logs')->where('subject', 'El sistema será restablecido')->count());

        // Solo queda el superadministrador, con su acceso intacto.
        $this->assertSame(1, User::query()->count());
        $this->assertSame($superAdmin->id, User::query()->value('id'));
        $this->assertTrue(User::query()->firstOrFail()->isSuperAdmin());

        $this->actingAs(User::query()->firstOrFail())
            ->get('/administracion/restablecer-dinamica')
            ->assertOk();

        // Los CRUD, los módulos, los roles y la matriz siguen igual.
        $this->assertSame($masterCounts, $this->masterCounts());
    }

    public function test_el_comando_se_puede_ejecutar_por_consola(): void
    {
        $this->seed();
        $this->seedDinamica();

        $superAdmin = $this->superAdmin();

        $this->artisan('dinamica:reiniciar', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('chat_messages')->count());
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'username' => 'admin']);
    }

    public function test_el_superadministrador_no_se_puede_eliminar_ni_bloquear(): void
    {
        $this->seed();

        $superAdmin = $this->superAdmin();
        $other = $this->otherAdmin();

        $usuarios = Module::query()->where('slug', 'usuarios')->firstOrFail();
        $view = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::query()->firstOrCreate([
            'profile_id' => $other->profile_id,
            'permission_id' => $view->id,
            'module_id' => $usuarios->id,
        ]);

        $this->actingAs($other)
            ->deleteJson('/usuarios/'.$superAdmin->id)
            ->assertStatus(409);

        $this->actingAs($other)
            ->postJson('/usuarios/'.$superAdmin->id.'/bloquear')
            ->assertStatus(409);

        $this->actingAs($other)
            ->postJson('/usuarios/eliminar-varios', ['ids' => [$superAdmin->id]])
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'is_active' => true]);
    }

    public function test_cierra_las_sesiones_y_avisa_por_correo_a_los_usuarios_eliminados(): void
    {
        $this->seed();

        $superAdmin = $this->superAdmin();
        $perfil = Profile::query()->where('slug', 'usuario')->value('id');

        $ana = User::factory()->create([
            'name' => 'Ana',
            'username' => 'ana-aviso',
            'email' => 'ana.aviso@example.com',
            'profile_id' => $perfil,
        ]);

        $luis = User::factory()->create([
            'name' => 'Luis',
            'username' => 'luis-aviso',
            'email' => 'luis.aviso@example.com',
            'profile_id' => $perfil,
        ]);

        $clave = auth('web')->getName();
        $payload = fn (int $userId): string => base64_encode(serialize([
            $clave => $userId,
            '_token' => 'token-'.$userId,
            '_previous' => ['url' => 'http://localhost/dashboard'],
        ]));

        $abrirSesion = function (string $id, int $userId) use ($payload): void {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => $payload($userId),
                'last_activity' => now()->getTimestamp(),
            ]);
        };

        $abrirSesion('sesion-ana', $ana->id);
        $abrirSesion('sesion-luis', $luis->id);
        $abrirSesion('sesion-admin', $superAdmin->id);

        $this->artisan('dinamica:reiniciar', ['--force' => true])->assertSuccessful();

        // Las cuentas se fueron, pero la sesión sigue con el aviso.
        foreach (['sesion-ana', 'sesion-luis'] as $id) {
            $fila = DB::table('sessions')->where('id', $id)->first();

            $this->assertNotNull($fila, 'La sesión debe quedar para poder mostrar el aviso.');
            $this->assertNull($fila->user_id);

            $datos = unserialize(base64_decode($fila->payload));

            $this->assertArrayNotHasKey($clave, $datos, 'La sesión debe quedar sin autenticación.');
            $this->assertSame(
                'La administración restableció el sistema del amigo secreto: tu sesión se cerró y tu cuenta fue eliminada. Solicita un nuevo usuario a la administración.',
                $datos['restablecimiento_aviso'] ?? null
            );
            $this->assertSame(['restablecimiento_aviso'], $datos['_flash']['new'] ?? null);
        }

        // El superadministrador conserva su sesión.
        $admin = DB::table('sessions')->where('id', 'sesion-admin')->first();

        $this->assertNotNull($admin);
        $this->assertSame($superAdmin->id, (int) $admin->user_id);
        $this->assertArrayHasKey($clave, unserialize(base64_decode($admin->payload)));

        // Aviso por correo a cada cuenta eliminada.
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('ana.aviso@example.com'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('luis.aviso@example.com'));
        Mail::assertSent(NotificationMail::class, 2);

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'ana.aviso@example.com',
            'subject' => 'El sistema será restablecido',
        ]);

        // El aviso se ve en la pantalla de acceso (aquí la sesión de la prueba
        // es la de Laravel, no la fila que se acaba de preparar).
        $this->withSession([
            'restablecimiento_aviso' => 'La administración restableció el sistema del amigo secreto: tu sesión se cerró y tu cuenta fue eliminada. Solicita un nuevo usuario a la administración.',
        ])
            ->get('/login')
            ->assertOk()
            ->assertSee('La administración restableció el sistema del amigo secreto');
    }

    /**
     * @return array<string, int>
     */
    private function masterCounts(): array    {
        return [
            'categories' => Category::query()->count(),
            'modules' => Module::query()->count(),
            'permissions' => Permission::query()->count(),
            'profiles' => Profile::query()->count(),
            'profile_permission_module' => DB::table('profile_permission_module')->count(),
            'module_permission' => DB::table('module_permission')->count(),
        ];
    }
}
