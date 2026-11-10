<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use Throwable;

class SyncExchangeRate extends Command
{
    protected $signature = 'exchange:sync';

    protected $description = 'Sincroniza la tasa oficial USD -> VES desde DolarAPI';

    public function handle(ExchangeRateService $service): int
    {
        try {
            $rate = $service->sync();
        } catch (Throwable $e) {
            $this->error('No se pudo sincronizar la tasa: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Tasa %s del %s: %s VES por USD',
            $rate->source,
            $rate->rate_date->format('Y-m-d'),
            number_format((float) $rate->rate, 4, ',', '.'),
        ));

        $this->line('10 USD = '.number_format($rate->convert(10), 2, ',', '.').' VES');

        return self::SUCCESS;
    }
}
