<?php

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
