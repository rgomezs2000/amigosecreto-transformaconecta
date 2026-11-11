<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Contracts\View\View;

class ModuleController extends Controller
{
    /**
     * Marcador de posición para los módulos que aún no se construyen.
     * La ruta es /[categoria]/[modulo].
     */
    public function show(string $category, string $module): View
    {
        $model = Module::query()
            ->where('slug', $module)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('slug', $category))
            ->firstOrFail();

        return view('modules.placeholder', [
            'module' => $model,
        ]);
    }
}
