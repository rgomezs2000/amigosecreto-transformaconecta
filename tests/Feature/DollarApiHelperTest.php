<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DollarApiHelperTest extends TestCase
{
    public function test_devuelve_la_estructura_del_dolar_oficial(): void
    {
        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => 890.0,
                'venta' => 900.0,
                'promedio' => 895.0,
                'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
            ]),
        ]);

        $rate = bcv_dollar(true);

        $this->assertIsArray($rate);
        $this->assertSame('oficial', $rate['fuente']);
        $this->assertSame('Dólar', $rate['nombre']);
        $this->assertSame(890.0, $rate['compra']);
        $this->assertSame(900.0, $rate['venta']);
        $this->assertSame(895.0, $rate['promedio']);
        $this->assertSame('2026-11-10T00:00:00-04:00', $rate['fechaActualizacion']);
    }

    public function test_devuelve_null_si_la_api_falla(): void
    {
        Http::fake([
            've.dolarapi.com/*' => Http::response([], 500),
        ]);

        $this->assertNull(bcv_dollar(true));
    }
}
