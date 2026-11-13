<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ShareAppShell;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ShareAppShell::class,
        ]);

        $middleware->alias([
            'module.access' => EnsureModuleAccess::class,
            'superadmin' => EnsureSuperAdmin::class,
        ]);

        // El logout debe poder cerrar la sesión aunque el token CSRF haya
        // expirado (por ejemplo, después de mucho tiempo con la pestaña
        // abierta). Sin esto, Laravel responde 419 antes de llegar al
        // controlador y la sesión no se destruye.
        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
