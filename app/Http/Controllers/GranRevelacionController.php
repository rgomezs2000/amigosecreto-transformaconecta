<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Assignment;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gran revelación (fase 5).
 *
 * Solo se abre el día exacto del intercambio. Revela al amigo secreto
 * incógnito (quien le regaló al usuario, no a quien él eligió) y el regalo que
 * esa persona registró. Al abrirse, los módulos anteriores quedan cerrados y,
 * pasada la fecha, el evento se finaliza y ya no admite reinicios ni cambios de
 * fecha.
 */
class GranRevelacionController extends Controller
{
    public function index(): View
    {
        $this->ensureAccess();

        $event = $this->eventoActual();
        $user = request()->user();
        $participacion = $event !== null ? $this->participacion($event, $user->id) : null;
        $disponible = $participacion !== null
            && $participacion->status->isParticipating()
            && FaseEvento::revelacionDisponible($event);

        $revelacion = null;

        if ($disponible) {
            $revelacion = $this->revelar($event, $user->id);
        }

        return view('gran-revelacion.index', [
            'event' => $event,
            'participacion' => $participacion,
            'disponible' => $disponible,
            'revelacion' => $revelacion,
        ]);
    }

    /**
     * Consulta para refrescar la revelación sin recargar la página.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoActual();
        $user = $request->user();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $participacion = $this->participacion($event, $user->id);

        if ($participacion === null || ! $participacion->status->isParticipating()) {
            return api_error('No participas activamente en este evento.', 409);
        }

        if (! FaseEvento::revelacionDisponible($event)) {
            return api_error(
                'La gran revelación se abre el día del intercambio: '.format_date($event->exchange_at, 'd/m/Y').'.',
                409
            );
        }

        $revelacion = $this->revelar($event, $user->id);

        return api_success('Gran revelación.', [
            'revelacion' => $revelacion,
            'html' => $revelacion !== null
                ? view('gran-revelacion.partials.revelacion', ['revelacion' => $revelacion])->render()
                : '<p class="sorteo-note"><i class="fa-solid fa-circle-info"></i> '
                    .'<span>No hay un amigo secreto que revelar para tu participación.</span></p>',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function revelar(Event $event, int $userId): ?array
    {
        $assignment = Assignment::query()
            ->where('event_id', $event->id)
            ->where('receiver_id', $userId)
            ->with(['giver', 'exchangeGift', 'consumableGifts'])
            ->first();

        if ($assignment === null) {
            return null;
        }

        if ($assignment->revealed_at === null) {
            $assignment->forceFill(['revealed_at' => now()])->save();
        }

        // La revelación cierra la etapa anterior para todo el evento.
        if ($event->status === EventStatus::Drawn) {
            $event->update([
                'status' => EventStatus::Revealed,
                'finalized_at' => $event->finalized_at,
            ]);
        }

        $tasa = dollar_rate();
        $gift = $assignment->exchangeGift;
        $consumibles = $assignment->consumableGifts;

        return [
            'amigo' => [
                'id' => $assignment->giver?->id,
                'name' => $assignment->giver?->name ?? 'Tu amigo secreto',
                'email' => $assignment->giver?->email,
            ],
            'regalo' => $gift !== null ? [
                'producto' => (string) $gift->product,
                'descripcion' => (string) ($gift->description ?? ''),
                'valor_usd' => 'US$ '.format_rate($gift->value),
                'valor_bs' => $tasa !== null ? 'Bs. '.format_rate((float) $gift->value * $tasa) : '—',
                'fecha' => format_date($gift->registered_at, 'd/m/Y'),
            ] : null,
            'consumibles' => $consumibles->map(fn ($item) => [
                'nombre' => (string) ($item->name ?: $item->description),
                'descripcion' => (string) ($item->description ?? ''),
                'valor_usd' => 'US$ '.format_rate($item->value),
                'valor_bs' => $tasa !== null ? 'Bs. '.format_rate((float) $item->value * $tasa) : '—',
            ])->values()->all(),
            'total' => 'US$ '.format_rate(
                (float) ($gift?->value ?? 0) + (float) $consumibles->sum('value')
            ),
            'tasa' => $tasa !== null ? 'Bs. '.format_rate($tasa) : null,
            'revelado' => true,
        ];
    }

    private function participacion(Event $event, int $userId): ?EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();
    }

    private function eventoActual(): ?Event
    {
        $event = Event::query()->orderByDesc('year')->first();
        $event = FaseEvento::sincronizar($event);

        return $event;
    }

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('gran-revelacion');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
