<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Support\ModulePages;
use Illuminate\Http\Request;

/**
 * Atiende la página de cualquier módulo.
 *
 * La ruta llega ya resuelta por el middleware module.access, que deja el
 * módulo en los atributos de la petición. Si el módulo tiene una pantalla
 * construida se delega en su controlador; si todavía no la tiene, se muestra
 * el marcador de posición.
 */
class ModulePageController extends Controller
{
    public function __invoke(Request $request, ModulePages $pages)
    {
        $module = $request->attributes->get('module');

        if (! $module instanceof Module) {
            abort(404);
        }

        $handler = $pages->handlerFor($module);

        if ($handler === null) {
            return view('modules.placeholder', ['module' => $module]);
        }

        if (($handler['superadmin'] ?? false) && ! $request->user()?->isSuperAdmin()) {
            abort(403, 'Solo el superadministrador puede usar este módulo.');
        }

        $controller = app($handler['controller']);

        return app()->call([$controller, $handler['action']]);
    }
}
