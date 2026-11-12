<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\Assignment;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SorteoCiegoTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $ctx = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        Mail::fake();
    }

    /**
     * Escenario base: un evento en inscripción con 4 participantes y un
     * administrador que puede iniciarlo.
     *
     * @return array<string, mixed>
     */
    private function escenario(int $participantes = 4): array
    {
        $perfilUsuario = Profile::create(['name' => 'Usuario', 'slug' => 'usuario', 'is_active' => true]);
        $perfilAdmin = Profile::create(['name' => 'Administrador', 'slug' => 'administrador', 'is_active' => true]);

        $view = Permission::create(['name' => 'Ver', 'slug' => 'view']);

        $modulos = [];

        foreach ([
            ['Sorteo ciego', 'asignar-amigo', 'fa-handshake'],
            ['Ver tu amigo', 'ver-tu-amigo', 'fa-user-secret'],
            ['Chat', 'chat', 'fa-comments'],
            ['Eventos', 'eventos', 'fa-calendar-days'],
            ['Notificaciones', 'notificaciones', 'fa-bell'],
        ] as $indice => [$nombre, $slug, $icono]) {
            // Algunas migraciones ya crean módulos (chat, notificaciones...).
            $modulos[$slug] = Module::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $nombre,
                    'group' => $slug === 'eventos' ? 'Eventos' : 'Amigo secreto',
                    'icon' => $icono,
                    'order' => 10 + $indice,
                    'is_active' => true,
                ]
            );

            ProfilePermissionModule::query()->firstOrCreate([
                'profile_id' => $slug === 'eventos' ? $perfilAdmin->id : $perfilUsuario->id,
                'permission_id' => $view->id,
                'module_id' => $modulos[$slug]->id,
            ]);
        }

        $usuarios = [];

        for ($i = 1; $i <= $participantes; $i++) {
            $usuarios[] = User::factory()->create([
                'name' => 'Participante '.$i,
                'username' => 'p'.$i,
                'email' => 'p'.$i.'@example.com',
                'profile_id' => $perfilUsuario->id,
            ]);
        }

        $evento = Event::create([
            'name' => 'Evento de prueba',
            'year' => (int) now()->year,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(10),
        ]);

        foreach ($usuarios as $usuario) {
            $evento->participants()->attach($usuario->id, [
                'status' => 'active',
                'confirmed_at' => now(),
            ]);
        }

        $admin = User::factory()->create([
            'name' => 'Coordinación',
            'username' => 'coord',
            'email' => 'coord@example.com',
            'profile_id' => $perfilAdmin->id,
            'role' => UserRole::Admin,
        ]);

        return $this->ctx = compact('usuarios', 'evento', 'admin', 'modulos');
    }

    private function iniciar(): void
    {
        $this->actingAs($this->ctx['admin'])
            ->postJson('/eventos/'.$this->ctx['evento']->id.'/iniciar')
            ->assertOk();
    }

    public function test_el_evento_iniciado_abre_el_sorteo_anuncia_en_el_chat_y_notifica(): void
    {
        $this->escenario();
        $this->iniciar();

        $evento = $this->ctx['evento']->fresh();

        $this->assertSame(EventStatus::DrawEnabled, $evento->status);

        // Chat fijo del evento, de solo lectura y con el aviso de apertura.
        $chat = Conversation::query()->where('event_id', $evento->id)->where('type', 'event')->first();

        $this->assertNotNull($chat);
        $this->assertTrue($chat->is_read_only);
        $this->assertSame(4, $chat->users()->count());
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $chat->id,
            'body' => 'El evento «'.$evento->name.'» ha comenzado. El sorteo está abierto: entra a «Sorteo ciego» y descubre a tu amigo secreto.',
        ]);

        // Aviso en pantalla para cada participante.
        foreach ($this->ctx['usuarios'] as $usuario) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $usuario->id,
                'type' => 'evento.iniciado',
            ]);
        }

        // Correo con el aviso de que el sorteo está abierto.
        Mail::assertSent(NotificationMail::class, 4);
    }

    public function test_el_reparto_no_tiene_autosorteo_ni_parejas_cruzadas(): void
    {
        $this->escenario();
        $this->iniciar();

        foreach ($this->ctx['usuarios'] as $usuario) {
            $this->actingAs($usuario)
                ->postJson('/amigo-secreto/asignar-amigo/descubrir')
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $asignaciones = Assignment::query()->where('event_id', $this->ctx['evento']->id)->get();

        $this->assertCount(4, $asignaciones);
        $this->assertSame(4, $asignaciones->pluck('receiver_id')->unique()->count());
        $this->assertSame(4, $asignaciones->pluck('giver_id')->unique()->count());

        foreach ($asignaciones as $asignacion) {
            $this->assertNotSame($asignacion->giver_id, $asignacion->receiver_id);
            $this->assertNotNull($asignacion->drawn_at);
        }

        // Sin parejas cruzadas (A→B y B→A).
        foreach ($asignaciones as $asignacion) {
            $cruzada = $asignaciones->first(
                fn (Assignment $otra) => $otra->giver_id === $asignacion->receiver_id
                    && $otra->receiver_id === $asignacion->giver_id
            );

            $this->assertNull($cruzada);
        }
    }

    public function test_ver_de_nuevo_devuelve_el_mismo_amigo_sin_volver_a_sortear(): void
    {
        $this->escenario(3);
        $this->iniciar();

        $usuario = $this->ctx['usuarios'][0];

        $primera = $this->actingAs($usuario)
            ->postJson('/amigo-secreto/asignar-amigo/descubrir')
            ->assertOk();

        $segunda = $this->actingAs($usuario)
            ->getJson('/amigo-secreto/asignar-amigo/amigo')
            ->assertOk();

        $this->assertSame(
            $primera->json('amigo.id'),
            $segunda->json('amigo.id')
        );

        $tercera = $this->actingAs($usuario)
            ->postJson('/amigo-secreto/asignar-amigo/descubrir')
            ->assertOk();

        $this->assertSame($primera->json('amigo.id'), $tercera->json('amigo.id'));
        $this->assertCount(3, Assignment::query()->where('event_id', $this->ctx['evento']->id)->get());
    }

    public function test_el_sorteo_se_cierra_cuando_eligen_todos_y_avisa(): void
    {
        $this->escenario(3);
        $this->iniciar();

        foreach ($this->ctx['usuarios'] as $usuario) {
            $respuesta = $this->actingAs($usuario)
                ->postJson('/amigo-secreto/asignar-amigo/descubrir');

            $this->assertSame(200, $respuesta->status(), $respuesta->getContent());
        }

        $evento = $this->ctx['evento']->fresh();

        $this->assertSame(EventStatus::Drawn, $evento->status);
        $this->assertNotNull($evento->draw_closed_at);

        // Aviso de cierre en el chat del evento.
        $chat = Conversation::query()->where('event_id', $evento->id)->where('type', 'event')->first();

        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $chat->id,
            'body' => 'El sorteo ciego ha concluido: todos los participantes ya eligieron a su amigo secreto. Comienza la etapa de la lista de deseos.',
        ]);

        foreach ($this->ctx['usuarios'] as $usuario) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $usuario->id,
                'type' => 'sorteo.concluido',
            ]);
        }
    }

    public function test_el_chat_del_evento_es_de_solo_lectura(): void
    {
        $this->escenario(3);
        $this->iniciar();

        $chat = Conversation::query()
            ->where('event_id', $this->ctx['evento']->id)
            ->where('type', 'event')
            ->firstOrFail();

        $usuario = $this->ctx['usuarios'][0];

        $this->actingAs($usuario)
            ->postJson('/chat/'.$chat->id.'/mensajes', ['body' => 'Hola'])
            ->assertForbidden();

        $this->actingAs($usuario)
            ->postJson('/chat/'.$chat->id.'/silenciar')
            ->assertForbidden();

        // Leer sí está permitido.
        $this->actingAs($usuario)
            ->getJson('/chat/'.$chat->id.'/mensajes')
            ->assertOk()
            ->assertJsonPath('conversation.read_only', true);
    }

    public function test_el_sorteo_se_muestra_cerrado_con_el_evento_suspendido(): void
    {
        $this->escenario(3);
        $this->iniciar();

        $this->actingAs($this->ctx['admin'])
            ->postJson('/eventos/'.$this->ctx['evento']->id.'/suspender', ['reason' => 'Pausa'])
            ->assertOk();

        $this->actingAs($this->ctx['usuarios'][0])
            ->postJson('/amigo-secreto/asignar-amigo/descubrir')
            ->assertStatus(409);
    }

    public function test_el_administrador_puede_reiniciar_el_sorteo(): void
    {
        $this->escenario(3);
        $this->iniciar();

        foreach ($this->ctx['usuarios'] as $usuario) {
            $this->actingAs($usuario)->postJson('/amigo-secreto/asignar-amigo/descubrir')->assertOk();
        }

        $this->assertSame(EventStatus::Drawn, $this->ctx['evento']->fresh()->status);

        $this->actingAs($this->ctx['admin'])
            ->postJson('/eventos/'.$this->ctx['evento']->id.'/sorteo/reiniciar')
            ->assertOk();

        $this->assertSame(0, Assignment::query()->where('event_id', $this->ctx['evento']->id)->count());
        $this->assertSame(0, Conversation::query()->where('event_id', $this->ctx['evento']->id)->where('type', 'secret')->count());
        $this->assertSame(EventStatus::DrawEnabled, $this->ctx['evento']->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'draw.reset']);
    }

    public function test_ver_tu_amigo_muestra_el_amigo_y_las_pistas_recibidas(): void
    {
        $this->escenario(3);
        $this->iniciar();

        $usuario = $this->ctx['usuarios'][0];

        $revelado = $this->actingAs($usuario)
            ->postJson('/amigo-secreto/asignar-amigo/descubrir')
            ->assertOk();

        $nombreAmigo = $revelado->json('amigo.name');

        // Pista enviada por su amigo secreto incógnito (quien le regala a él).
        $incognito = Assignment::query()
            ->where('event_id', $this->ctx['evento']->id)
            ->where('receiver_id', $usuario->id)
            ->firstOrFail();

        DB::table('clues')->insert([
            'assignment_id' => $incognito->id,
            'body' => 'Me gusta el chocolate',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($usuario)
            ->get('/amigo-secreto/ver-tu-amigo')
            ->assertOk()
            ->assertSee($nombreAmigo)
            ->assertSee('Me gusta el chocolate')
            ->assertSee('Ver de nuevo');
    }

    public function test_no_se_puede_sortear_con_dos_participantes_sin_parejas_mutuas(): void
    {
        $this->escenario(2);
        $this->iniciar();

        $this->actingAs($this->ctx['usuarios'][0])
            ->postJson('/amigo-secreto/asignar-amigo/descubrir')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Assignment::query()->where('event_id', $this->ctx['evento']->id)->count());
    }
}
