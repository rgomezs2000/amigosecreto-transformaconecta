<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Consulta y sincroniza la tasa oficial USD -> VES desde DolarAPI Venezuela.
 *
 * `fetch` devuelve la estructura normalizada del dólar oficial; `sync` la
 * guarda por día en `exchange_rates`. Si la API falla, el sistema debe seguir
 * mostrando la última tasa conocida (ver `latest`), no romperse.
 */
class ExchangeRateService
{
    /**
     * Estructura normalizada que consume el helper y el frontend.
     *
     * @return array{fuente:string,nombre:string,compra:float,venta:float,promedio:float,fechaActualizacion:string}|null
     */
    public function fetch(bool $fresh = false): ?array
    {
        $provider = config('exchange.provider');
        $cacheKey = 'exchange_rate:'.md5((string) $provider['url']);
        $cacheTtl = (int) config('exchange.cache_ttl', 300);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $cacheTtl, function () use ($provider): ?array {
            try {
                $response = Http::acceptJson()
                    ->timeout($provider['timeout'])
                    ->retry(2, 500)
                    ->get($provider['url']);
            } catch (ConnectionException|RequestException $e) {
                Log::warning('No se pudo consultar la tasa oficial.', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('La API de tasa respondió con error.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['promedio']) || ! is_numeric($data['promedio'])) {
                Log::warning('La API de tasa devolvió un formato inesperado.', [
                    'data' => $data,
                ]);

                return null;
            }

            return [
                'fuente' => (string) ($data['fuente'] ?? 'oficial'),
                'nombre' => (string) ($data['nombre'] ?? 'Dólar oficial'),
                'compra' => (float) ($data['compra'] ?? $data['promedio']),
                'venta' => (float) ($data['venta'] ?? $data['promedio']),
                'promedio' => (float) $data['promedio'],
                'fechaActualizacion' => (string) ($data['fechaActualizacion'] ?? now()->toIso8601String()),
            ];
        });
    }

    public function sync(): ExchangeRate
    {
        $data = $this->fetch(true);

        if ($data === null) {
            throw new RuntimeException('No se pudo consultar la API de tasa.');
        }

        return ExchangeRate::updateOrCreate(
            [
                'rate_date' => Carbon::parse($data['fechaActualizacion'])->toDateString(),
                'quote' => config('exchange.display_currency'),
                'source' => $data['fuente'],
            ],
            [
                'base' => config('exchange.base_currency'),
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
