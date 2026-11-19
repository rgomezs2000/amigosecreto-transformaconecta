<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GrantsModules;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use GrantsModules;
    use RefreshDatabase;

    public function test_la_tasa_bcv_se_entrega_para_actualizacion_en_vivo(): void
    {
        Cache::flush();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => 916.65,
                'venta' => 916.65,
                'promedio' => 916.65,
                'fechaActualizacion' => '2026-11-18T00:00:00-04:00',
            ]),
        ]);

        $user = $this->makeUser('Ana Tasa', UserRole::Participant);

        $this->actingAs($user)
            ->getJson('/tasa-bcv')
            ->assertOk()
            ->assertJsonPath('rate', 916.65)
            ->assertJsonPath('formatted', '916,65')
            ->assertJsonPath('updated_at', '2026-11-18T00:00:00-04:00');
    }

    public function test_la_tasa_bcv_exige_sesion(): void
    {
        $this->getJson('/tasa-bcv')->assertUnauthorized();
    }
}
