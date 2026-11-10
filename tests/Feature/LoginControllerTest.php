<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    public function test_index_muestra_la_vista_de_acceso(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('auth.login');
    }
}
