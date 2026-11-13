<?php

namespace App\Http\Middleware;

use App\Models\Module;
use App\Models\ModuleRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    /**
     * Resuelve la ruta /[categoria]/[modulo] contra el maestro de módulos.
     *
     * La resolución ocurre en cada petición: crear, renombrar o eliminar un
     * módulo en el maestro cambia su URL al instante, sin tocar rutas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim(rawurldecode((string) ($request->route('path') ?? $request->path())), '/');

        $module = Module::findByPath($path);

        if ($module === null) {
            $redirect = $this->legacyRedirect($path);

            if ($redirect !== null) {
                return $redirect;
            }

            abort(404);
        }

        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! $user->hasModuleAccess($module)) {
            abort(403);
        }

        $request->attributes->set('module', $module);

        return $next($request);
    }

    /**
     * Enlace antiguo de un módulo que cambió de slug o de categoría: se
     * responde con un 301 hacia su URL actual en lugar de un 404.
     */
    private function legacyRedirect(string $path): ?Response
    {
        if ($path === '') {
            return null;
        }

        $module = ModuleRedirect::query()
            ->with('module')
            ->where('path', $path)
            ->first()
            ?->module;

        if ($module === null || ! $module->is_active) {
            return null;
        }

        return redirect()->to($module->url(), 301);
    }
}
