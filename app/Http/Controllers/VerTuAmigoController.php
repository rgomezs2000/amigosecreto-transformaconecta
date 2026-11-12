<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Module;
use App\Services\SorteoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Ver tu amigo»: consulta libre del amigo secreto y de las pistas recibidas.
 *
 * Se activa junto con el botón «Ver de nuevo» del sorteo ciego: mientras el
 * sorteo esté abierto (o ya concluido) el participante puede entrar cuantas
 * veces quiera, sin volver a sortear.
 */
class VerTuAmigoController extends Controller
{
    public function __construct(private readonly SorteoService $sorteo) {}

    public function index(): View
    {
        $this->ensureModuleAccess();

        $user = request()->user();
        $event = $this->eventoActual();
        $estado = $this->sorteo->estado($event, $user);

        return view('amigo.index', [
            'event' => $event,
            'estado' => $estado,
            'pistas' => $event !== null && $estado['revelado']
                ? $this->sorteo->pistasRecibidas($event, $user)
                : collect(),
        ]);
    }

    /**
     * Refresco sin recargar la página.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureModuleAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $user = $request->user();
        $estado = $this->sorteo->estado($event, $user);

        if (! $estado['revelado']) {
            return api_error('Todavía no has descubierto a tu amigo secreto.', 409);
        }

        return api_success('Tu amigo secreto.', [
            'amigo' => $estado['amigo'],
            'estado' => $estado,
            'pistas' => $this->sorteo->pistasRecibidas($event, $user)
                ->map(fn ($pista) => [
                    'id' => $pista->id,
                    'body' => $pista->body,
                    'fecha' => $pista->sent_at?->format('d/m/Y H:i'),
                ])
                ->values(),
        ]);
    }

    private function eventoActual(): ?Event
    {
        return Event::query()->orderByDesc('year')->first();
    }

    private function ensureModuleAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'ver-tu-amigo')
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
