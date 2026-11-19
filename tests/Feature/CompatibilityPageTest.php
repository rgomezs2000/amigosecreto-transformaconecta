<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CompatibilityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_de_compatibilidad_carga_con_la_matriz(): void
    {
        $this->get('/compatibilidad')
            ->assertOk()
            ->assertSee('Compatibilidad del sistema')
            ->assertSee('Sistemas operativos de escritorio')
            ->assertSee('Sistemas operativos móviles')
            ->assertSee('Trident / MSHTML')
            ->assertSee('Blink')
            ->assertSee('No compatible');
    }

    public function test_un_navegador_legacy_se_redirige_a_compatibilidad(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko')
            ->get('/login')
            ->assertRedirect(route('compatibilidad', [
                'from' => url('/login'),
                'motivo' => 'incompatible',
            ]));
    }

    public function test_la_pantalla_de_compatibilidad_no_se_redirige_a_si_misma(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko')
            ->get('/compatibilidad')
            ->assertOk()
            ->assertSee('Internet Explorer');
    }

    public function test_un_navegador_moderno_puede_entrar_al_login(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36')
            ->get('/login')
            ->assertOk();
    }

    public function test_un_navegador_legacy_cierra_la_sesion_activa(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko')
            ->get('/dashboard')
            ->assertRedirect(route('compatibilidad', [
                'from' => url('/dashboard'),
                'motivo' => 'incompatible',
            ]));

        $this->assertGuest();
    }

    public function test_la_url_firmada_de_compatibilidad_cierra_la_sesion(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute('compatibilidad.logout', now()->addMinutes(30), [
            'from' => url('/dashboard'),
        ]);

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('compatibilidad', [
                'from' => url('/dashboard'),
                'motivo' => 'incompatible',
            ]));

        $this->assertGuest();
    }

    public function test_la_pantalla_de_compatibilidad_avisa_que_se_cerro_la_sesion(): void
    {
        $this->get('/compatibilidad?motivo=incompatible')
            ->assertOk()
            ->assertSee('Acceso bloqueado por incompatibilidad')
            ->assertSee('se cerró automáticamente');
    }

    public function test_la_pantalla_de_compatibilidad_tambien_cierra_una_sesion_legacy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko')
            ->get('/compatibilidad')
            ->assertOk()
            ->assertSee('Acceso bloqueado por incompatibilidad');

        $this->assertGuest();
    }

    public function test_el_parametro_from_externo_se_descarta(): void
    {
        $this->get('/compatibilidad?from=https://ejemplo-externo.test/login')
            ->assertOk()
            ->assertDontSee('ejemplo-externo.test', false);
    }

    public function test_el_parametro_from_con_esquema_peligroso_se_descarta(): void
    {
        $this->get('/compatibilidad?from=javascript:alert(1)')
            ->assertOk()
            ->assertDontSee('javascript:alert', false);
    }
}
