<?php

namespace Tests\Feature;

use App\Models\SessionKick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanSessionCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_sesiones_huerfanas_y_limpia_la_sesion_activa(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create([
            'active_session_id' => 'sesion-vieja',
        ]);

        $now = now()->timestamp;

        DB::table('sessions')->insert([
            [
                'id' => 'sesion-vieja',
                'user_id' => $user->id,
                'ip_address' => '1.1.1.1',
                'user_agent' => 'Navegador cerrado',
                'payload' => '',
                'last_activity' => $now - (31 * 60),
            ],
            [
                'id' => 'sesion-reciente',
                'user_id' => $user->id,
                'ip_address' => '2.2.2.2',
                'user_agent' => 'Navegador abierto',
                'payload' => '',
                'last_activity' => $now,
            ],
        ]);

        SessionKick::query()->create([
            'session_id' => 'aviso-viejo',
            'user_id' => $user->id,
            'message' => 'Aviso viejo',
            'consumed_at' => now()->subDays(2),
        ]);

        $this->artisan('sessions:cleanup', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-vieja']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-reciente']);
        $this->assertNull($user->fresh()->active_session_id);
        $this->assertDatabaseMissing('session_kicks', ['session_id' => 'aviso-viejo']);
    }

    /**
     * La pantalla de acceso también tiene sesión (guarda el token CSRF). No
     * envía latido, así que antes se borraba y el envío del formulario
     * respondía «CSRF token mismatch».
     */
    public function test_la_sesion_de_un_invitado_sobrevive_a_la_limpieza(): void
    {
        config(['session.driver' => 'database']);

        DB::table('sessions')->insert([
            'id' => 'invitado-en-la-pantalla-de-acceso',
            'user_id' => null,
            'ip_address' => '3.3.3.3',
            'user_agent' => 'Pantalla de acceso abierta',
            'payload' => '',
            'last_activity' => now()->subMinutes(31)->timestamp,
        ]);

        $this->artisan('sessions:cleanup', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'invitado-en-la-pantalla-de-acceso']);
    }

    /**
     * El latido se detiene mientras la pestaña está oculta, así que unos
     * minutos de silencio no indican un navegador cerrado.
     */
    public function test_no_borra_una_sesion_de_pocos_minutos_aunque_se_pida_un_minuto(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'pestana-oculta',
            'user_id' => $user->id,
            'ip_address' => '4.4.4.4',
            'user_agent' => 'Pestaña en segundo plano',
            'payload' => '',
            'last_activity' => now()->subMinutes(5)->timestamp,
        ]);

        $this->artisan('sessions:cleanup', ['--minutes' => 1])
            ->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'pestana-oculta']);
    }
}
