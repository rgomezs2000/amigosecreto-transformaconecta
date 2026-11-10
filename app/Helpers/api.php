<?php

use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;

if (! function_exists('api_response')) {
    /**
     * Respuesta JSON uniforme para los endpoints AJAX.
     *
     * @param  array<string, mixed>  $data
     */
    function api_response(bool $success, string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
        ], $data), $status);
    }
}

if (! function_exists('api_success')) {
    /**
     * @param  array<string, mixed>  $data
     */
    function api_success(string $message = 'OK', array $data = [], int $status = 200): JsonResponse
    {
        return api_response(true, $message, $data, $status);
    }
}

if (! function_exists('api_error')) {
    /**
     * @param  array<string, mixed>  $data
     */
    function api_error(string $message, int $status = 400, array $data = []): JsonResponse
    {
        return api_response(false, $message, $data, $status);
    }
}

if (! function_exists('bcv_dollar')) {
    /**
     * Tasa oficial USD -> VES (BCV) desde DolarAPI Venezuela.
     *
     * Estructura:
     * [
     *   'fuente' => 'oficial',
     *   'nombre' => 'Dólar',
     *   'compra' => 0.0,
     *   'venta' => 0.0,
     *   'promedio' => 0.0,
     *   'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
     * ]
     *
     * Devuelve null si la API no responde o trae un formato inesperado.
     *
     * @return array{fuente:string,nombre:string,compra:float,venta:float,promedio:float,fechaActualizacion:string}|null
     */
    function bcv_dollar(bool $fresh = false): ?array
    {
        return app(ExchangeRateService::class)->fetch($fresh);
    }
}
