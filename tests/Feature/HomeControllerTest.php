<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HomeControllerTest extends TestCase
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

    public function test_un_invitado_es_enviado_al_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_un_usuario_con_sesion_ve_el_panel(): void
    {
        $this->actingAs($this->usuario())
            ->get('/')
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_el_panel_exige_sesion(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_el_panel_se_muestra_con_sesion(): void
    {
        $this->actingAs($this->usuario())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Panel de control')
            ->assertSee('Ana Torres')
            ->assertSee('US$/Bs (BCV)')
            ->assertSee('899,27');
    }

    public function test_el_logout_cierra_la_sesion_y_vuelve_a_la_raiz(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    private function usuario(): User
    {
        // No se guarda: el guard solo necesita la instancia para `Auth::check()`.
        return new User(['name' => 'Ana Torres', 'username' => 'ana']);
    }
}
