<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Services\DinamicaProgress;
use App\Services\DinamicaRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Módulo «Restablecer dinámica»: exclusivo del superadministrador.
 *
 * La página solo tiene un botón. Al confirmar, lanza el comando batch
 * `dinamica:reiniciar` y devuelve un identificador de ejecución; el panel
 * consulta `progreso` para mover la barra y ver la bitácora en el modal.
 */
class DinamicaController extends Controller
{
    public function __construct(
        private readonly DinamicaProgress $progress,
        private readonly DinamicaRunner $runner,
    ) {}

    public function index(): View
    {
        $this->ensureModuleAccess();

        return view('dinamica.index', [
            'logFile' => 'storage/logs/'.config('dinamica.log', 'dinamica').'.log',
            'latest' => $this->progress->latest(),
        ]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        $running = $this->progress->running();

        if ($running !== null) {
            return api_error('Ya hay un restablecimiento en curso.', 409, [
                'run' => $running['run'] ?? null,
            ]);
        }

        $run = (string) Str::uuid();

        $this->runner->start($run);

        return api_success('Restablecimiento iniciado.', ['run' => $run]);
    }

    public function progreso(Request $request): JsonResponse
    {
        $run = (string) $request->query('run', '');

        if (! $this->progress->isValidRun($run)) {
            return api_error('Ejecución no válida.', 422);
        }

        $state = $this->progress->read($run);

        if ($state === null) {
            return api_error('No hay información de esa ejecución.', 404);
        }

        return api_success('OK', ['estado' => $state]);
    }

    /**
     * El módulo debe existir y estar concedido a quien lo abre, además de la
     * comprobación de superadministrador del middleware.
     */
    private function ensureModuleAccess(): void
    {
        $module = Module::activeByCode('restablecer-dinamica');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
