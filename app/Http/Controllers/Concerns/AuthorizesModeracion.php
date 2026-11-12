<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Module;

/**
 * Acceso al módulo de Moderación.
 *
 * Los tres apartados del módulo (reportes, bloqueos entre usuarios y vetados
 * de grupo) comparten la misma regla: el módulo tiene que existir, estar
 * activo y el usuario tenerlo concedido en su perfil o de forma individual.
 */
trait AuthorizesModeracion
{
    /**
     * Corta la petición con 404 si el módulo no existe y con 403 si el
     * usuario no lo tiene concedido.
     */
    protected function ensureModeracionAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'moderacion')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
