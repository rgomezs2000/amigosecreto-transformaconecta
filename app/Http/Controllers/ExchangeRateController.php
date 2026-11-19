<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ExchangeRateController extends Controller
{
    /**
     * Tasa BCV actual para actualizarla en vivo en todas las pantallas.
     */
    public function show(): JsonResponse
    {
        $dollar = bcv_dollar();

        if ($dollar === null) {
            return api_success('Tasa BCV no disponible por el momento.', [
                'rate' => null,
                'formatted' => null,
                'updated_at' => null,
            ]);
        }

        return api_success('Tasa BCV actualizada.', [
            'rate' => (float) $dollar['promedio'],
            'formatted' => format_rate($dollar['promedio']),
            'updated_at' => $dollar['fechaActualizacion'],
        ]);
    }
}
