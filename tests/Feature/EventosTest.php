<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Event;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventosTest extends TestCase
{
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

    private function adminWithEventosModule(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Eventos',
            'slug' => 'eventos',
            'group' => 'Eventos',
            'icon' => 'fa-calendar-days',
            'order' => 50,
            'is_active' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Ver',
            'slug' => 'view',
        ]);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        return User::factory()->create([
            'profile_id' => $profile->id,
            'role' => UserRole::Admin,
        ]);
    }

    private function participant(string $name = 'Ana Pérez', string $email = 'ana@example.com'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'username' => strtolower(str_replace(' ', '', $name)),
            'role' => UserRole::Participant,
            'is_active' => true,
        ]);
    }

    private function eventWithParticipant(EventStatus $status = EventStatus::Registration): array
    {
        $event = Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => $status,
            'exchange_at' => now()->addDays(20),
        ]);

        $participant = $this->participant();

        $event->participants()->attach($participant->id, [
            'status' => ParticipantStatus::Active->value,
            'confirmed_at' => now(),
        ]);

        return [$event, $participant];
    }

    public function test_la_pagina_de_eventos_muestra_tarjetas_y_acciones(): void
    {
        $admin = $this->adminWithEventosModule();

        $event = Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(20),
        ]);

        $this->actingAs($admin)
            ->get('/eventos/eventos')
            ->assertOk()
            ->assertSee('Nuevo evento')
            ->assertSee($event->name)
            ->assertSee('Participantes')
            ->assertSee('Iniciar')
            ->assertSee('Suspender')
            ->assertSee('Posponer')
            ->assertSee('Cancelar')
            ->assertSee('Reiniciar');
    }

    public function test_la_pagina_exige_el_modulo_de_eventos(): void
    {
        Module::create([
            'name' => 'Eventos',
            'slug' => 'eventos',
            'group' => 'Eventos',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/eventos/eventos')
            ->assertForbidden();
    }

    public function test_crear_evento_con_participantes_activos(): void
    {
        $admin = $this->adminWithEventosModule();
        $ana = $this->participant('Ana Pérez', 'ana@example.com');
        $luis = $this->participant('Luis Gómez', 'luis@example.com');

        $this->actingAs($admin)
            ->postJson('/eventos', [
                'name' => 'Amigo Secreto 2026',
                'exchange_at' => '2026-12-20',
                'status_reason' => 'Intercambio de fin de año.',
                'participants' => [
                    $ana->id => ['notes' => 'Prefiere regalos de café.'],
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $event = Event::query()->where('year', 2026)->firstOrFail();

        $this->assertSame('Amigo Secreto 2026', $event->name);
        $this->assertSame(EventStatus::Registration, $event->status);
        $this->assertSame(2, $event->participants()->count());
        $this->assertSame(
            'Prefiere regalos de café.',
            $event->participants()->whereKey($ana->id)->first()?->pivot?->notes
        );
    }

    public function test_no_permite_dos_eventos_en_el_mismo_anio(): void
    {
        $admin = $this->adminWithEventosModule();

        Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->postJson('/eventos', [
                'name' => 'Otro evento',
                'exchange_at' => '2026-12-24',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exchange_at']);
    }

    public function test_iniciar_evento_envia_correo_y_cambia_estado(): void
    {
        Mail::fake();

        $admin = $this->adminWithEventosModule();
        [$event, $participant] = $this->eventWithParticipant();

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/iniciar')
            ->assertOk();

        $event->refresh();

        $this->assertSame(EventStatus::DrawEnabled, $event->status);
        $this->assertNotNull($event->started_at);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($participant) {
            return $mail->hasTo($participant->email);
        });
    }

    public function test_iniciar_evento_sin_participantes_falla(): void
    {
        $admin = $this->adminWithEventosModule();

        $event = Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Registration,
            'exchange_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/iniciar')
            ->assertStatus(422);
    }

    public function test_suspender_evento_guarda_motivo_y_envia_correo(): void
    {
        Mail::fake();

        $admin = $this->adminWithEventosModule();
        [$event, $participant] = $this->eventWithParticipant(EventStatus::DrawEnabled);

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/suspender', [
                'reason' => 'Motivos de salud.',
            ])
            ->assertOk();

        $event->refresh();

        $this->assertSame(EventStatus::Suspended, $event->status);
        $this->assertSame('Motivos de salud.', $event->status_reason);
        $this->assertNotNull($event->suspended_at);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($participant) {
            return $mail->hasTo($participant->email);
        });
    }

    public function test_posponer_evento_reactiva_con_nueva_fecha(): void
    {
        Mail::fake();

        $admin = $this->adminWithEventosModule();
        [$event, $participant] = $this->eventWithParticipant(EventStatus::Suspended);

        $event->update([
            'previous_status' => EventStatus::DrawEnabled->value,
            'suspended_at' => now(),
            'status_reason' => 'Motivo inicial.',
        ]);

        $newDate = now()->addDays(40);

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/posponer', [
                'exchange_at' => $newDate->format('Y-m-d'),
                'reason' => 'Se movió la reunión.',
                'suspend' => 0,
            ])
            ->assertOk();

        $event->refresh();

        $this->assertSame(EventStatus::DrawEnabled, $event->status);
        $this->assertSame($newDate->format('Y-m-d'), $event->exchange_at?->format('Y-m-d'));
        $this->assertNull($event->suspended_at);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($participant) {
            return $mail->hasTo($participant->email);
        });
    }

    public function test_posponer_evento_mantiene_suspendido(): void
    {
        $admin = $this->adminWithEventosModule();
        [$event] = $this->eventWithParticipant(EventStatus::DrawEnabled);

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/posponer', [
                'exchange_at' => now()->addDays(30)->format('Y-m-d'),
                'reason' => 'Sigue suspendido.',
                'suspend' => 1,
            ])
            ->assertOk();

        $event->refresh();

        $this->assertSame(EventStatus::Suspended, $event->status);
        $this->assertNotNull($event->suspended_at);
    }

    public function test_cancelar_evento_elimina_y_audita(): void
    {
        Mail::fake();

        $admin = $this->adminWithEventosModule();
        [$event, $participant] = $this->eventWithParticipant();

        $this->actingAs($admin)
            ->postJson('/eventos/' . $event->id . '/cancelar', [
                'reason' => 'El grupo decidió no realizarlo.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.cancelled']);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($participant) {
            return $mail->hasTo($participant->email);
        });
    }

    public function test_participantes_devuelve_lista_y_observaciones(): void
    {
        $admin = $this->adminWithEventosModule();
        [$event, $participant] = $this->eventWithParticipant();

        $event->participants()->updateExistingPivot($participant->id, [
            'notes' => 'Sin perfume.',
        ]);

        $this->actingAs($admin)
            ->getJson('/eventos/' . $event->id . '/participantes')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('participants.0.notes', 'Sin perfume.')
            ->assertJsonPath('participants.0.status', 'Activo');
    }

    public function test_evento_suspendido_congela_modulos_de_amigo_secreto(): void
    {
        $admin = $this->adminWithEventosModule();

        $category = Category::create([
            'name' => 'Amigo secreto',
            'slug' => 'amigo-secreto',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $amigoModule = Module::create([
            'name' => 'Ver tu amigo',
            'slug' => 'ver-tu-amigo',
            'group' => 'Amigo secreto',
            'category_id' => $category->id,
            'icon' => 'fa-gift',
            'order' => 10,
            'is_active' => true,
        ]);

        $permission = Permission::query()->where('slug', 'view')->firstOrFail();

        ProfilePermissionModule::create([
            'profile_id' => $admin->profile_id,
            'permission_id' => $permission->id,
            'module_id' => $amigoModule->id,
        ]);

        Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => EventStatus::Suspended,
            'exchange_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Ver tu amigo');
    }
}
