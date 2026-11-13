<?php

use App\Models\Module;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;

if (! function_exists('normalize_login')) {
    /**
     * Normaliza el usuario o correo que llega del formulario.
     */
    function normalize_login(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}

if (! function_exists('format_text')) {
    /**
     * Texto seguro y legible para vistas y JSON.
     */
    function format_text(?string $value, string $fallback = '—'): string
    {
        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }
}

if (! function_exists('format_date')) {
    /**
     * Fecha formateada para mostrar; acepta Carbon, DateTime o texto.
     */
    function format_date(mixed $date, string $format = 'd/m/Y H:i', string $fallback = '—'): string
    {
        if ($date === null || $date === '') {
            return $fallback;
        }

        try {
            return $date instanceof DateTimeInterface
                ? $date->format($format)
                : Carbon::parse($date)->format($format);
        } catch (Throwable) {
            return $fallback;
        }
    }
}

if (! function_exists('format_rate')) {
    /**
     * Tasa/cifra con dos decimales y separadores venezolanos.
     */
    function format_rate(mixed $value, int $decimals = 2, string $fallback = 'No disponible'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return number_format((float) $value, $decimals, ',', '.');
    }
}

if (! function_exists('format_count')) {
    /**
     * Entero con separador de miles.
     */
    function format_count(mixed $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }
}

if (! function_exists('module_url')) {
    /**
     * URL pública de un módulo por su código interno (o por su slug actual).
     *
     * Se usa en enlaces y redirecciones para no depender del slug: si el
     * módulo cambia de slug en el maestro, esta URL cambia con él. Cuando el
     * módulo ya no existe o está inactivo se devuelve la URL de respaldo.
     */
    function module_url(string $code, ?string $fallback = null): string
    {
        $module = Module::activeByCode($code)
            ?? Module::query()
                ->where('slug', $code)
                ->where('is_active', true)
                ->first();

        return $module?->url() ?? $fallback ?? route('dashboard');
    }
}

if (! function_exists('dollar_rate')) {
    /**
     * Tasa oficial USD -> VES (BCV) que usa toda la aplicación.
     *
     * Primero consulta la API con caché corta; si no responde, devuelve la
     * última tasa guardada en `exchange_rates`. Null solo si nunca hubo tasa.
     */
    function dollar_rate(bool $fresh = false): ?float
    {
        $dollar = bcv_dollar($fresh);
        $rate = $dollar['promedio']
            ?? app(ExchangeRateService::class)->latest()?->rate;

        return $rate !== null ? (float) $rate : null;
    }
}
