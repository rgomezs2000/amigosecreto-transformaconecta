<?php

namespace Tests\Feature;

use Google\Client;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    public function test_redirige_a_google_cuando_esta_configurado(): void
    {
        $this->mock(Client::class, function ($mock) {
            $mock->shouldReceive('createAuthUrl')
                ->once()
                ->andReturn('https://accounts.google.com/o/oauth2/auth?test=1');
        });

        $this->get('/auth/google/redirect')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth?test=1');
    }

    public function test_callback_sin_codigo_vuelve_al_login(): void
    {
        $this->get('/auth/google/callback')
            ->assertRedirect(route('login', ['google' => 'cancelled']));
    }
}
