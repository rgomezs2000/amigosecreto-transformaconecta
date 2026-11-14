<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SessionSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activar_guarda_el_id_de_la_sesion_actual(): void
    {
        $user = User::factory()->create();
        $sessionId = str_repeat('a', 40);

        $request = Request::create('/', 'GET');
        $request->setLaravelSession(new Store('prueba', new ArraySessionHandler(120), $sessionId));

        SessionSecurity::activate($user, $request);

        $this->assertSame($sessionId, $user->fresh()->active_session_id);
    }

    public function test_activar_elimina_las_sesiones_anteriores_y_deja_el_aviso(): void
    {
        $user = User::factory()->create();
        $currentId = str_repeat('c', 40);

        DB::table('sessions')->insert([
            'id' => 'sesion-anterior',
            'user_id' => $user->id,
            'ip_address' => '2.2.2.2',
            'user_agent' => 'Prueba',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $request = Request::create('/', 'GET');
        $request->setLaravelSession(new Store('prueba', new ArraySessionHandler(120), $currentId));

        $this->assertSame(1, SessionSecurity::activate($user, $request));

        $this->assertDatabaseHas('sessions', [
            'id' => 'sesion-anterior',
            'user_id' => null,
            'payload' => '',
        ]);
        $this->assertDatabaseHas('session_kicks', ['session_id' => 'sesion-anterior']);
        $this->assertSame($currentId, $user->fresh()->active_session_id);
    }

    public function test_la_sesion_anterior_se_cierra_y_va_al_login(): void
    {
        $user = User::factory()->create([
            'active_session_id' => 'otra-sesion',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('login', ['sesion' => 'otra']));

        $this->assertGuest();
    }

    public function test_el_sondeo_avisa_y_cierra_la_sesion_anterior(): void
    {
        $user = User::factory()->create([
            'active_session_id' => 'otra-sesion',
        ]);

        $this->actingAs($user)
            ->getJson('/sesion/estado')
            ->assertOk()
            ->assertJsonPath('kicked', true)
            ->assertJsonPath('message', SessionSecurity::KICKED_MESSAGE);

        $this->assertGuest();
    }

    public function test_la_sesion_actual_sigue_activa(): void
    {
        $user = User::factory()->create([
            'active_session_id' => str_repeat('a', 40),
        ]);

        $request = Request::create('/', 'GET');
        $request->setLaravelSession(new Store('prueba', new ArraySessionHandler(120), str_repeat('a', 40)));

        $this->assertTrue(SessionSecurity::isCurrent($user, $request));

        $request->setLaravelSession(new Store('prueba', new ArraySessionHandler(120), str_repeat('b', 40)));

        $this->assertFalse(SessionSecurity::isCurrent($user, $request));
    }
}
