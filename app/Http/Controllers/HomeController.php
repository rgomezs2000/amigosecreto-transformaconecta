<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
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

        $user = Auth::user();

        $modules = $user->accessibleModules()
            ->where('slug', '!=', 'salir')
            ->values();

        $groups = $modules->groupBy('group');

        $event = Event::query()->orderByDesc('year')->first();

        return view('dashboard', [
            'user' => $user,
            'modules' => $modules,
            'groups' => $groups,
            'event' => $event,
            'currentStep' => $this->currentStep($event),
        ]);
    }

    /**
     * Traduce el estado del evento a la fase 1..5 del tablero.
     */
    private function currentStep(?Event $event): int
    {
        if ($event === null) {
            return 1;
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return 1;
        }

        if ($event->status === EventStatus::DrawEnabled) {
            return 2;
        }

        if ($event->status === EventStatus::Drawn) {
            if ($event->exchange_at !== null && $event->exchange_at->isPast()) {
                return 5;
            }

            if ($event->dynamic_starts_at !== null && $event->dynamic_starts_at->isPast()) {
                return 4;
            }

            return 3;
        }

        return 5;
    }
}
