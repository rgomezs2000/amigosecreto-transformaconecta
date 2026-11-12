<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Module;
use App\Services\SorteoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sorteo ciego (vista del participante).
 *
 * Un botón central descubre al amigo secreto; después se puede volver a ver
 * cuantas veces se quiera. El reparto se calcula en el primer ingreso.
 */
class SorteoCiegoController extends Controller
{
    public function __construct(private readonly SorteoService $sorteo) {}

    public function index(): View
    {
        $this->ensureModuleAccess();

        $user = request()->user();
        $event = $this->eventoActual();

        return view('sorteo.index', [
            'event' => $event,
            'estado' => $this->sorteo->estado($event, $user),
        ]);
    }

    public function descubrir(Request $request): JsonResponse
    {
        $this->ensureModuleAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        try {
            $this->sorteo->descubrir($event, $request->user());
        } catch (RuntimeException $exception) {
            return api_error($exception->getMessage(), 409);
        }

        $estado = $this->sorteo->estado($event->fresh(), $request->user());

        return api_success('Este es tu amigo secreto.', [
            'amigo' => $estado['amigo'],
            'estado' => $estado,
        ]);
    }

    /**
     * «Ver de nuevo»: sin límite, no vuelve a sortear.
     */
    public function ver(Request $request): JsonResponse
    {
        $this->ensureModuleAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $estado = $this->sorteo->estado($event, $request->user());

        if (! $estado['revelado']) {
            return api_error('Todavía no has descubierto a tu amigo secreto.', 409);
        }

        return api_success('Tu amigo secreto.', [
            'amigo' => $estado['amigo'],
            'estado' => $estado,
        ]);
    }

    private function eventoActual(): ?Event
    {
        return Event::query()->orderByDesc('year')->first();
    }

    private function ensureModuleAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'asignar-amigo')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
