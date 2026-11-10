<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginViewTest extends TestCase
{
    public function test_muestra_la_pantalla_de_acceso(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('Acceso al sistema')
            ->assertSee('Usuario o correo')
            ->assertSee('Clave')
            ->assertSee('Ingresar')
            ->assertSee('Salir')
            ->assertSee('Acceso con Google');
    }

    public function test_carga_las_librerias_por_cdn(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('bootstrap@5.3.3', false)
            ->assertSee('jquery-3.7.1', false)
            ->assertSee('bootbox@6.0.4', false)
            ->assertSee('font-awesome', false)
            ->assertSee('accounts.google.com/gsi/client', false);
    }

    public function test_incluye_el_client_id_publico_de_google(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee(config('services.google.client_id'), false);
    }

    public function test_no_expone_el_secreto_de_google(): void
    {
        $secret = (string) config('services.google.client_secret');

        $this->assertNotSame('', $secret, 'El secreto de Google debe estar configurado en el entorno.');

        $this->get('/login')
            ->assertOk()
            ->assertDontSee($secret, false);
    }
}
