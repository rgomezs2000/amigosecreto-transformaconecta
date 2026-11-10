<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Panel principal: saludo, reloj y menú según los módulos permitidos.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $groups = $user->accessibleModules()->groupBy('group');

        return view('dashboard', [
            'user' => $user,
            'groups' => $groups,
        ]);
    }
}
