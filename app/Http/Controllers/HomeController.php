<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * Punto de entrada y panel.
     *
     * Sin sesión redirige al acceso. Con sesión muestra el dashboard. Tanto
     * la raíz (/) como /dashboard usan este mismo método.
     */
    public function index(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        return view('dashboard');
    }
}
