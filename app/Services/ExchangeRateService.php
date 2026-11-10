<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sincroniza la tasa oficial USD -> VES desde DolarAPI Venezuela.
 *
 * Se guarda por día, así que sincronizar varias veces el mismo día no duplica
 * filas. Si la API falla, el sistema debe seguir mostrando la última tasa
 * conocida (ver `latest`), no romperse.
 */
class ExchangeRateService
{
    public function sync(): ExchangeRate
    {
        $provider = config('exchange.provider');

        try {
            $response = Http::acceptJson()
                ->timeout($provider['timeout'])
                ->retry(2, 500)
                ->get($provider['url']);
        } catch (ConnectionException|RequestException $e) {
            // `retry` lanza antes de que podamos ver el status: se normaliza a
            // un error de dominio para que quien consuma el servicio no dependa
            // de las excepciones de Guzzle.
            throw new RuntimeException(
                'No se pudo consultar la API de tasa: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "La API de tasa respondió HTTP {$response->status()}."
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['promedio']) || ! is_numeric($data['promedio'])) {
            throw new RuntimeException('La API de tasa devolvió un formato inesperado.');
        }

        $rateDate = isset($data['fechaActualizacion'])
            ? Carbon::parse($data['fechaActualizacion'])->toDateString()
            : now()->toDateString();

        return ExchangeRate::updateOrCreate(
            [
                'rate_date' => $rateDate,
                'quote' => config('exchange.display_currency'),
                'source' => $data['fuente'] ?? config('exchange.default_source'),
            ],
            [
                'base' => $data['moneda'] ?? config('exchange.base_currency'),
                'rate' => $data['promedio'],
                'fetched_at' => now(),
            ],
        );
    }

    /** Última tasa conocida; null si nunca se ha sincronizado. */
    public function latest(?string $source = null): ?ExchangeRate
    {
        return ExchangeRate::latestFor($source ?? config('exchange.default_source'));
    }

    /** Convierte USD a VES con la tasa indicada o la última conocida. */
    public function toVes(float $usd, ?ExchangeRate $rate = null): ?float
    {
        $rate ??= $this->latest();

        return $rate?->convert($usd);
    }
}
