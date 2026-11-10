<?php

namespace App\Providers;

use Google\Client;
use Illuminate\Support\ServiceProvider;

class GoogleServiceProvider extends ServiceProvider
{
    /**
     * Cliente de Google configurado una sola vez por petición.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $client = new Client();

            $client->setClientId((string) config('services.google.client_id'));
            $client->setClientSecret((string) config('services.google.client_secret'));
            $client->setRedirectUri((string) config('services.google.redirect'));

            $client->addScope('email');
            $client->addScope('profile');

            return $client;
        });
    }
}
