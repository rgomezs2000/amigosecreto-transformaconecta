<?php

namespace App\Providers;

use App\Services\Firestore\FirestoreGateway;
use App\Services\Firestore\FirestoreService;
use App\Services\Firestore\NullFirestoreGateway;
use App\Services\Firestore\RestFirestoreGateway;
use App\Services\Firestore\SdkFirestoreGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Helpers/api.php');
        require_once app_path('Helpers/general.php');
        require_once app_path('Helpers/mail.php');

        $this->app->singleton(FirestoreGateway::class, function (): FirestoreGateway {
            $projectKey = (string) config('firebase.default', 'app');
            $project = (array) config('firebase.projects.'.$projectKey, []);
            $firestore = (array) ($project['firestore'] ?? []);

            $driver = strtolower(trim((string) ($firestore['driver'] ?? 'rest')));
            $database = trim((string) ($firestore['database'] ?? '(default)'));

            if ($driver === 'off') {
                return new NullFirestoreGateway;
            }

            $credentials = $project['credentials'] ?? null;
            $path = $this->resolveCredentialsPath(is_string($credentials) ? $credentials : null);
            $projectId = $this->projectIdFromCredentials($path);

            if ($path === null || $projectId === null) {
                return new NullFirestoreGateway;
            }

            if ($driver === 'sdk') {
                return new SdkFirestoreGateway($projectId);
            }

            return new RestFirestoreGateway($projectId, $database === '' ? '(default)' : $database, $path);
        });

        $this->app->bind(
            FirestoreService::class,
            fn ($app) => new FirestoreService($app->make(FirestoreGateway::class))
        );
    }

    /**
     * Resuelve la ruta del JSON de la cuenta de servicio (relativa o absoluta).
     */
    private function resolveCredentialsPath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        $isAbsolute = (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $value)
            || str_starts_with($value, '/')
            || str_starts_with($value, '\\\\');

        return $isAbsolute ? $value : base_path($value);
    }

    private function projectIdFromCredentials(?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) @file_get_contents($path), true);

        if (! is_array($json)) {
            return null;
        }

        $projectId = $json['project_id'] ?? null;

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
