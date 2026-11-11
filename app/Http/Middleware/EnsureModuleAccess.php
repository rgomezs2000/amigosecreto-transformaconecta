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
        $slug = $request->route('module');

        if (! is_string($slug)) {
            abort(404);
        }

        $module = Module::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! $request->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        return $next($request);
    }
}
