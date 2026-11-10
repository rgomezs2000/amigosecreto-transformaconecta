<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class LoginController extends Controller
{
    /**
     * Muestra la pantalla de acceso.
     */
    public function index(): View
    {
        return view('auth.login');
    }
}
