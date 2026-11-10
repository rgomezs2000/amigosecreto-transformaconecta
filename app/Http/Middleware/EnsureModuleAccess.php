<?php

namespace App\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    /**
     * Deja pasar solo si el usuario autenticado tiene acceso al módulo.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $module = $request->route('module');

        if (! $module instanceof Module) {
            $module = Module::query()
                ->where('slug', $module)
                ->first();
        }

        if ($module === null || ! $module->is_active) {
            abort(404);
        }

        if (! $request->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        return $next($request);
    }
}
