<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsModules;
use Tests\TestCase;

class ShellMenuTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    public function test_el_menu_vivo_devuelve_html_y_firma(): void
    {
        $user = $this->makeUser('Ana Menú', UserRole::Participant, ['chat']);

        $response = $this->actingAs($user)
            ->getJson('/menu/estado')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'html', 'signature']);

        $this->assertStringContainsString('Chat', (string) $response->json('html'));
        $this->assertNotEmpty($response->json('signature'));
    }

    public function test_el_menu_vivo_exige_sesion(): void
    {
        $this->getJson('/menu/estado')
            ->assertUnauthorized();
    }
}
