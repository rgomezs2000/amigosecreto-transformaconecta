<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tasa de cambio del día. La moneda canónica del sistema es USD y VES es solo
 * presentación: los montos se guardan en USD y se convierten al mostrar.
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'rate_date',
        'base',
        'quote',
        'rate',
        'source',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
            'fetched_at' => 'datetime',
        ];
    }

    /** Última tasa conocida de una fuente ('oficial' o 'paralelo'). */
    public static function latestFor(string $source = 'oficial'): ?self
    {
        return static::query()
            ->where('source', $source)
            ->orderByDesc('rate_date')
            ->first();
    }

    public function convert(float $usd): float
    {
        return round($usd * (float) $this->rate, 2);
    }
}
