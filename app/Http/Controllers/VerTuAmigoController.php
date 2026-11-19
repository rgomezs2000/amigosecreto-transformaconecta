<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Models\User;
use App\Models\WishItem;
use App\Services\SorteoService;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Ver tu amigo»: el espacio del participante activo.
 *
 * Reúne cuatro pestañas: el amigo secreto (solo el nombre), la lista de deseos
 * del amigo, las pistas que deja el amigo secreto incógnito y los obsequios
 * (regalo único + consumibles) con su presupuesto. La actividad de la
 * invitación queda siempre visible mientras no se haya respondido.
 */
class VerTuAmigoController extends Controller
{
    public function __construct(private readonly SorteoService $sorteo) {}

    public function index(Request $request): View
    {
        $this->ensureModuleAccess();

        $user = $request->user();
        $event = $this->eventoActual($request, $user);
        $participacion = $event !== null ? $this->participacion($event, $user->id) : null;
        $estado = $this->sorteo->estado($event, $user);
        $assignment = $event !== null ? $this->sorteo->asignacionDe($event, $user) : null;
        $gift = $assignment?->exchangeGift;

        $giftsVisible = $estado['revelado']
            && $assignment !== null
            && in_array($event?->status, [
                EventStatus::Drawn,
                EventStatus::Revealed,
                EventStatus::Closed,
            ], true);
        $puedeEditar = $giftsVisible && $event?->status === EventStatus::Drawn;
        $tasa = $giftsVisible ? dollar_rate() : null;

        // Lista de deseos del amigo asignado (solo si ya hay reparto).
        $deseosAmigo = $assignment !== null && $event !== null
            ? WishItem::query()
                ->where('event_id', $event->id)
                ->where('user_id', $assignment->receiver_id)
                ->orderBy('title')
                ->get()
            : collect();

        $invitacion = null;

        if ($participacion !== null) {
            $invitacion = $this->invitacionData($participacion);
            $invitacion['auto_open'] = $participacion->status->canRespondInvitation();
        }

        return view('amigo.index', [
            'event' => $event,
            'participacion' => $participacion,
            'invitacion' => $invitacion,
            'estado' => $estado,
            'pistas' => $event !== null && $estado['revelado']
                ? $this->sorteo->pistasRecibidas($event, $user)
                : collect(),
            'gift' => $gift,
            'giftsVisible' => $giftsVisible,
            'puedeEditar' => $puedeEditar,
            'tasa' => $tasa,
            'giftBs' => $gift !== null ? $this->bsDe($gift->value, $tasa) : null,
            'deseosAmigo' => $deseosAmigo,
            'presupuesto' => $event?->budget(),
        ]);
    }

    /**
     * Refresco sin recargar la página.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureModuleAccess();

        $event = $this->eventoActual($request, $request->user());

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

    /**
     * @return array<string, mixed>
     */
    private function invitacionData(EventParticipant $participacion): array
    {
        return [
            'token' => $participacion->invitation_token,
            'status' => $participacion->status->value,
            'status_label' => $participacion->status->label(),
            'reason' => $participacion->status_reason,
            'can_accept' => $participacion->status->canRespondInvitation()
                && ! $participacion->event->status->isFinished()
                && ! $participacion->event->isSuspended(),
            'url' => $participacion->invitationUrl(),
            'event_name' => $participacion->event->name,
            'exchange_at' => format_date($participacion->event->exchange_at, 'd/m/Y'),
            'budget' => $participacion->event->budget() !== null
                ? 'US$ '.format_rate($participacion->event->budget())
                : null,
        ];
    }

    private function participacion(Event $event, int $userId): ?EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Evento que corresponde al usuario.
     *
     * Primero manda la invitación abierta (parámetro `invitacion`) y después el
     * evento en el que el usuario tiene participación: con varios eventos del
     * mismo año, tomar el último creado mostraba «Sin invitación» aunque la
     * persona ya hubiera aceptado la suya.
     */
    private function eventoActual(Request $request, User $user): ?Event
    {
        $token = trim((string) $request->query('invitacion', ''));

        if ($token !== '') {
            $participacion = EventParticipant::query()
                ->with('event')
                ->where('invitation_token', $token)
                ->first();

            if ($participacion !== null && (int) $participacion->user_id === (int) $user->id) {
                return FaseEvento::sincronizar($participacion->event);
            }
        }

        $propio = Event::query()
            ->whereHas('participants', fn ($query) => $query->whereKey($user->id))
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        return FaseEvento::sincronizar(
            $propio ?? Event::query()->orderByDesc('year')->orderByDesc('id')->first()
        );
    }

    /**
     * Equivalente en bolívares a la tasa oficial del BCV.
     */
    private function bsDe(mixed $usd, ?float $tasa): ?string
    {
        if ($usd === null || $usd === '' || $tasa === null) {
            return null;
        }

        return 'Bs. '.format_rate((float) $usd * $tasa);
    }

    private function ensureModuleAccess(): void
    {
        $module = Module::activeByCode('ver-tu-amigo');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
