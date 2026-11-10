<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Contracts\View\View;

class ModuleController extends Controller
{
    /**
     * Marcador de posición para los módulos que aún no se construyen.
     */
    public function show(Module $module): View
    {
        return view('modules.placeholder', [
            'module' => $module,
        ]);
    }
}
