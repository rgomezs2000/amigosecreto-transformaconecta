<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShellController extends Controller
{
    /**
     * Devuelve el menú actual del usuario para refrescarlo sin recargar.
     */
    public function menu(Request $request): JsonResponse
    {
        $shell = view()->shared('shell');
        $groups = $shell['menuGroups'] ?? collect();
        $html = view('partials.menu', ['groups' => $groups])->render();

        return api_success('Menú actualizado.', [
            'html' => $html,
            'signature' => hash('sha256', $html),
        ]);
    }
}
