<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'moneda' => 'USD',
            'fuente' => 'oficial',
            'nombre' => 'Dólar',
            'compra' => null,
            'venta' => null,
            'promedio' => 899.2705,
            'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
        ];
    }

    public function test_sincroniza_la_tasa_oficial(): void
    {
        Http::fake(['ve.dolarapi.com/*' => Http::response($this->payload())]);

        $rate = app(ExchangeRateService::class)->sync();

        $this->assertSame('oficial', $rate->source);
        $this->assertSame('USD', $rate->base);
        $this->assertSame('VES', $rate->quote);
        $this->assertSame('2026-11-10', $rate->rate_date->toDateString());
        $this->assertEqualsWithDelta(899.2705, (float) $rate->rate, 0.000001);

        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_no_duplica_filas_del_mismo_dia(): void
    {
        Http::fake(['ve.dolarapi.com/*' => Http::response($this->payload())]);

        $service = app(ExchangeRateService::class);
        $service->sync();
        $service->sync();

        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_usa_la_ultima_tasa_conocida_para_convertir(): void
    {
        Http::fake(['ve.dolarapi.com/*' => Http::response($this->payload())]);

        $service = app(ExchangeRateService::class);
        $service->sync();

        $this->assertEqualsWithDelta(8992.71, $service->toVes(10.0), 0.01);
    }

    public function test_falla_si_la_api_responde_error(): void
    {
        Http::fake(['ve.dolarapi.com/*' => Http::response([], 500)]);

        $this->expectException(RuntimeException::class);

        app(ExchangeRateService::class)->sync();
    }

    public function test_no_guarda_nada_si_el_formato_es_inesperado(): void
    {
        Http::fake(['ve.dolarapi.com/*' => Http::response(['moneda' => 'USD'])]);

        try {
            app(ExchangeRateService::class)->sync();
            $this->fail('Se esperaba una excepción por formato inesperado.');
        } catch (RuntimeException) {
            $this->assertSame(0, ExchangeRate::count());
        }
    }
}
