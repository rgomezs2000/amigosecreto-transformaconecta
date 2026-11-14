<?php

namespace Tests\Feature;

use Google\Client;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    public function test_redirige_a_google_cuando_esta_configurado(): void
    {
        $this->mock(Client::class, function ($mock) {
            $mock->shouldReceive('setPrompt')
                ->once()
                ->with('select_account');

            $mock->shouldReceive('setState')
                ->once()
                ->with(\Mockery::type('string'));

            $mock->shouldReceive('createAuthUrl')
                ->once()
                ->andReturn('https://accounts.google.com/o/oauth2/auth?test=1');
        });

        $this->get('/auth/google/redirect')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth?test=1')
            ->assertSessionHas('google.oauth_state');
    }

    public function test_callback_con_state_invalido_no_autentica(): void
    {
        $this->mock(Client::class);

        $this->get('/auth/google/callback?code=invalido&state=invalido')
            ->assertRedirect(route('login', ['google' => 'error']));
    }

    public function test_callback_sin_codigo_vuelve_al_login(): void
    {
        $this->get('/auth/google/callback')
            ->assertRedirect(route('login', ['google' => 'cancelled']));
    }
}
