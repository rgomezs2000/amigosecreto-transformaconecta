<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
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
            ->assertSee('Ana Torres');
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
