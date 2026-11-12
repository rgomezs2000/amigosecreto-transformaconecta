<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    /**
     * Consulta si un login (usuario) está disponible.
     *
     * Responde JSON para el blur del formulario de perfil. El login del
     * propio usuario se considera disponible.
     */
    public function consultarLoginExiste(Request $request): JsonResponse
    {
        $login = trim((string) $request->input('login', ''));

        if ($login === '') {
            return response()->json([
                'available' => false,
                'message' => 'El usuario es obligatorio.',
            ], 422);
        }

        if (mb_strlen($login) > 255) {
            return response()->json([
                'available' => false,
                'message' => 'El usuario es demasiado largo.',
            ], 422);
        }

        $ignoreId = $request->integer('ignore');

        if (! $request->boolean('admin')) {
            $ignoreId = $ignoreId ?: $request->user()?->id;
        }

        $exists = User::query()
            ->loginExists($login, $ignoreId ?: null)
            ->exists();

        return response()->json([
            'available' => ! $exists,
            'message' => $exists ? 'Usuario no disponible.' : 'Usuario disponible.',
        ]);
    }
}
