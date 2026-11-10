<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moneda y tasa de cambio
    |--------------------------------------------------------------------------
    |
    | La moneda canónica es USD: todos los montos se guardan en dólares y VES
    | es solo presentación. La tasa se guarda por día en `exchange_rates` para
    | poder mostrar el equivalente a la fecha del obsequio.
    |
    */

    'base_currency' => env('EXCHANGE_BASE_CURRENCY', 'USD'),
    'display_currency' => env('EXCHANGE_DISPLAY_CURRENCY', 'VES'),

    // 'oficial' (BCV) o 'paralelo'. El administrador decide cuál se muestra.
    'default_source' => env('EXCHANGE_DEFAULT_SOURCE', 'oficial'),

    'provider' => [
        'name' => env('EXCHANGE_PROVIDER', 'dolarapi'),

        // Endpoint de la tasa oficial. Devuelve un solo objeto:
        // {"moneda":"USD","fuente":"oficial","promedio":899.2705,
        //  "fechaActualizacion":"2026-11-10T00:00:00-04:00"}
        'url' => env('EXCHANGE_PROVIDER_URL', 'https://ve.dolarapi.com/v1/dolares/oficial'),

        'timeout' => (int) env('EXCHANGE_PROVIDER_TIMEOUT', 5),
    ],

];
