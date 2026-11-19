<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudRegistroThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_limite_de_registro_responde_en_espanol(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/solicitudes/registro', [])
                ->assertStatus(422);
        }

        $this->postJson('/solicitudes/registro', [])
            ->assertStatus(429)
            ->assertJsonPath(
                'message',
                'Has realizado varios intentos de registro seguidos. Espera 60 segundos antes de volver a intentarlo.'
            )
            ->assertJsonPath('retry_after', 60);
    }
}
