<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

class EventoReinicioController extends Controller
{
    public function reiniciar(Request $request): JsonResponse
    {
        $this->ensurePermission();

        $year = (int) $request->input('year', now()->year);

        $output = new BufferedOutput();

        $status = Artisan::call('evento:reiniciar', ['year' => $year], $output);
        $text = trim($output->fetch());

        if ($status !== 0) {
            return api_response(false, $text !== '' ? $text : 'No se pudo reiniciar el evento.', ['output' => $text], 500);
        }

        return api_success('Evento reiniciado. Ya puedes crear uno nuevo.', ['output' => $text, 'year' => $year]);
    }

    private function ensurePermission(): void
    {
        $user = request()->user();

        abort_if($user === null, 403);

        if ($user->role === UserRole::Admin) {
            return;
        }

        abort(403, 'Solo la administración puede reiniciar el evento.');
    }
}
