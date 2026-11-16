<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureSingleSession;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ShareAppShell;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás de AWS/CloudFront, la IP real llega en X-Forwarded-For.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            ShareAppShell::class,
            EnsureSingleSession::class,
        ]);

        // Debe ejecutarse después de StartSession pero antes de auth, para
        // poder avisar a una sesión que ya fue eliminada.
        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: EnsureSingleSession::class,
        );

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
        // 419 significa que el formulario llegó con un token viejo: la sesión
        // que lo emitió ya no existe (por ejemplo, la pestaña del login estuvo
        // abierta mucho tiempo). Con AJAX se responde un aviso entendible y
        // sin la traza de la excepción; el navegador pide un token nuevo y
        // reintenta el envío en vez de dejar al usuario atascado.
        // Laravel entrega el 419 como HttpException, ya no como
        // TokenMismatchException, por eso se filtra por el código de estado.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($exception->getStatusCode() !== 419 || ! $request->expectsJson()) {
                return null;
            }

            return api_error(
                'La página perdió la sesión de seguridad. Se recargará para que puedas intentarlo de nuevo.',
                419,
                ['csrf_expired' => true]
            );
        });
    })->create();
