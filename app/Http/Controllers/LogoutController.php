<?php

namespace App\Http\Controllers;

use App\Services\SessionSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /**
     * Cierra la sesión y vuelve a la raíz. HomeController se encarga de
     * mandar al login porque ya no hay sesión.
     */
    public function logout(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            SessionSecurity::clear($request->user(), $request);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
