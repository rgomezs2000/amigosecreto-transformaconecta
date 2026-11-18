<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Models\User;
use App\Services\EventoChat;
use App\Services\InvitacionesEvento;
use App\Services\PushNotifier;
use App\Services\SorteoService;
use App\Support\FaseEvento;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EventosController extends Controller
{
    public function __construct(
        private readonly SorteoService $sorteo,
        private readonly EventoChat $chat,
        private readonly PushNotifier $push,
        private readonly InvitacionesEvento $invitaciones,
    ) {}

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('eventos');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    public function index(): View
    {
        $this->ensureAccess();

        $events = Event::query()->orderByDesc('year')->get();

        // Si se crearon usuarios después del evento, al abrir la página ya
        // aparecen sus invitaciones pendientes.
        $events->each(function (Event $event) {
            if ($event->status->allowsInvitations()) {
                $this->invitaciones->generar($event);
            }
        });

        return view('eventos.index', [
            'events' => $events,
            'tasa' => dollar_rate(),
        ]);
    }

    /**
     * Estado de los eventos para refrescar las tarjetas sin recargar.
     */
    public function estado(): JsonResponse
    {
        $this->ensureAccess();

        $events = Event::query()
            ->withCount('participants')
            ->orderByDesc('year')
            ->get();

        $data = $events->map(fn (Event $event) => $this->tarjetaData($event))->values();

        return api_success('Estado de eventos.', [
            'version' => md5($data->toJson()),
            'events' => $data,
            'html' => view('eventos.partials.lista', ['events' => $events])->render(),
            'can_create' => ! $events->contains(fn (Event $event) => (int) $event->year === (int) now()->year),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tarjetaData(Event $event): array
    {
        $participaciones = $event->participations()->get()->groupBy(fn (EventParticipant $item) => $item->status->value);

        return [
            'id' => $event->id,
            'year' => $event->year,
            'name' => $event->name,
            'status' => $event->statusLabel(),
            'badge' => $event->isSuspended() ? 'blocked' : ($event->status->isFinished() ? 'inactive' : 'active'),
            'phase' => FaseEvento::paso($event),
            'phase_label' => FaseEvento::etiquetaDe($event),
            'exchange' => $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha',
            'participants' => $event->participants_count,
            'active' => (int) ($participaciones->get(ParticipantStatus::Active->value)?->count() ?? 0),
            'pending' => (int) ($participaciones->get(ParticipantStatus::Pending->value)?->count() ?? 0),
            'budget' => $event->budget() !== null ? 'US$ '.format_rate($event->budget()) : 'Sin presupuesto',
            'created' => $event->created_at?->format('d/m/Y') ?? '—',
        ];
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        if ($request->filled('max_value') && ! $request->filled('presupuesto')) {
            $request->merge(['presupuesto' => $request->input('max_value')]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'exchange_at' => ['required', 'date', 'after_or_equal:today'],
            'presupuesto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'status_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'El nombre del evento es obligatorio.',
            'exchange_at.required' => 'La fecha del intercambio es obligatoria.',
            'exchange_at.date' => 'Escribe una fecha válida.',
            'exchange_at.after_or_equal' => 'La fecha del intercambio no puede ser anterior a hoy.',
            'presupuesto.required' => 'El presupuesto es obligatorio.',
            'presupuesto.numeric' => 'Escribe un presupuesto válido en US$.',
            'presupuesto.min' => 'El presupuesto debe ser mayor que cero.',
            'status_reason.max' => 'Las observaciones son demasiado largas.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $date = Carbon::parse($data['exchange_at']);
        $year = (int) $date->year;

        if (Event::query()->where('year', $year)->exists()) {
            return api_response(false, "Ya existe un evento para el año {$year}. Solo se permite un evento por año.", [
                'errors' => ['exchange_at' => ["Ya existe un evento para el año {$year}."]],
            ], 422);
        }

        try {
            $event = Event::create([
                'name' => $data['name'],
                'year' => $year,
                'status' => EventStatus::Registration,
                'status_reason' => $data['status_reason'] ?? null,
                'exchange_at' => $date,
                'max_value' => (float) $data['presupuesto'],
                'currency' => 'USD',
            ]);

            // Se genera una invitación personal por cada usuario participante.
            // Nadie queda inscrito hasta que acepte.
            $this->invitaciones->generar($event);
            $this->invitaciones->anunciarCreacion($event);

            AuditLog::create([
                'event_id' => $event->id,
                'user_id' => $request->user()?->id,
                'action' => 'event.created',
                'payload' => [
                    'name' => $event->name,
                    'year' => $event->year,
                    'budget_usd' => (float) $data['presupuesto'],
                ],
            ]);

            $request->session()->flash('eventos_status', 'Evento creado. Las invitaciones están listas para enviarse.');

            return api_success('Evento creado correctamente.', [
                'event_id' => $event->id,
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el evento', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el evento. Intenta de nuevo.', 500);
        }
    }

    public function iniciar(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return api_error('El evento no está en una etapa que permita iniciarlo.', 409);
        }

        if (Event::query()->where('year', $event->year)->whereKeyNot($event->id)->exists()) {
            return api_error('Ya existe un evento en curso para el año '.$event->year.'.', 409);
        }

        $pending = $event->pendingParticipationsCount();

        if ($pending > 0) {
            return api_error(
                'Todavía hay '.$pending.' invitación(es) en espera. La organización debe descartarlas o esperar la respuesta antes de iniciar.',
                422
            );
        }

        $participants = $this->activeParticipants($event);

        if ($participants->count() < 2) {
            return api_error('El evento necesita al menos 2 participantes activos para sortear.', 422);
        }

        try {
            $event->update([
                'status' => EventStatus::DrawEnabled,
                'previous_status' => null,
                'started_at' => now(),
                'suspended_at' => null,
                'draw_closed_at' => null,
                'dynamic_starts_at' => null,
            ]);

            // Chat fijo del evento: solo lectura, con el aviso de apertura.
            $this->chat->anunciar(
                $event,
                'La Unión terminó y el sorteo está abierto. Entra a «Sorteo ciego» y descubre a tu amigo secreto.'
            );

            // Aviso en pantalla (push) para cada participante activo.
            foreach ($participants as $participant) {
                $this->push->send(
                    $participant,
                    'evento.iniciado',
                    'El sorteo está abierto',
                    'El evento ha comenzado. Ya puedes elegir a tu amigo secreto.',
                    ['event_id' => $event->id],
                    module_url('asignar-amigo'),
                    'evento'
                );
            }

            $this->sendToParticipants(
                $participants,
                'El evento ha comenzado',
                'Tu Amigo Secreto ha comenzado',
                [
                    'Hola, {name}.',
                    'El evento «'.$event->name.'» ha comenzado.',
                    'Ha llegado el momento de elegir a tu amigo secreto: el sorteo está disponible y abierto.',
                    'Entra a «Sorteo ciego» y pulsa «Descubrir a mi Amigo Secreto».',
                    'Fecha del intercambio: '.$event->exchange_at?->format('d/m/Y').'.',
                ],
                ['label' => 'Descubrir a mi amigo secreto', 'url' => module_url('asignar-amigo')]
            );

            $request->session()->flash('eventos_status', 'Evento iniciado. Se enviaron las notificaciones.');

            return api_success('Evento iniciado correctamente.', [
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al iniciar el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo iniciar el evento. Intenta de nuevo.', 500);
        }
    }

    /**
     * Envía (o reenvía) las invitaciones pendientes por correo, chat y push.
     */
    public function invitar(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if (! $event->status->allowsInvitations()) {
            return api_error('Las invitaciones ya no se pueden enviar en esta fase.', 409);
        }

        try {
            $resultado = $this->invitaciones->enviar($event);

            if ($resultado['enviadas'] === 0) {
                return api_error('No hay invitaciones pendientes por enviar.', 409);
            }

            $request->session()->flash(
                'eventos_status',
                'Invitaciones enviadas: '.$resultado['enviadas'].'. Quedan '.$resultado['pendientes'].' por responder.'
            );

            return api_success('Invitaciones enviadas correctamente.', [
                'enviadas' => $resultado['enviadas'],
                'pendientes' => $resultado['pendientes'],
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al enviar invitaciones', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudieron enviar las invitaciones. Intenta de nuevo.', 500);
        }
    }

    /**
     * Reenvía la invitación a una sola persona y devuelve su enlace personal.
     */
    public function invitarParticipante(Request $request, Event $event, User $user): JsonResponse
    {
        $this->ensureAccess();

        $participacion = $this->participacion($event, $user);

        if ($participacion->status !== ParticipantStatus::Pending) {
            return api_error('Solo se puede invitar a quien está pendiente.', 409);
        }

        try {
            $this->invitaciones->enviar($event, $participacion);
        } catch (Throwable $exception) {
            Log::error('Error al enviar la invitación individual', [
                'event_id' => $event->id,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo enviar la invitación. Intenta de nuevo.', 500);
        }

        return api_success('Invitación enviada.', [
            'invitation_url' => $participacion->fresh()?->invitationUrl(),
        ]);
    }

    /**
     * Expulsa a un participante activo, con el motivo obligatorio.
     */
    public function expulsar(Request $request, Event $event, User $user): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo de la expulsión.',
            'reason.min' => 'El motivo es demasiado corto.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $participacion = $this->participacion($event, $user);

        try {
            $this->invitaciones->expulsar($participacion, $request->user(), $validator->validated()['reason']);
        } catch (Throwable $exception) {
            return api_error($exception->getMessage(), 409);
        }

        return api_success('Participante expulsado del evento.');
    }

    /**
     * Descarta a quien nunca respondió, para poder iniciar el evento.
     */
    public function descartar(Request $request, Event $event, User $user): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $participacion = $this->participacion($event, $user);

        try {
            $this->invitaciones->descartar(
                $participacion,
                $request->user(),
                (string) ($validator->validated()['reason'] ?? '')
            );
        } catch (Throwable $exception) {
            return api_error($exception->getMessage(), 409);
        }

        return api_success('Invitación descartada.');
    }

    public function suspender(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if ($event->status->isFinished()) {
            return api_error('El evento ya se celebró y no se puede suspender.', 409);
        }

        if ($event->isSuspended()) {
            return api_error('El evento ya está suspendido.', 409);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo de la suspensión.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $reason = $validator->validated()['reason'];

        try {
            $event->update([
                'previous_status' => $event->status->value,
                'status' => EventStatus::Suspended,
                'suspended_at' => now(),
                'status_reason' => $reason,
            ]);

            $this->sendToParticipants(
                $this->activeParticipants($event),
                'El evento fue suspendido',
                'El evento fue suspendido',
                [
                    'Hola, {name}.',
                    'El evento «'.$event->name.'» fue suspendido.',
                    'Motivo: '.$reason,
                    'El plazo de la suspensión es indefinido. Te avisaremos cuando se reactive.',
                ],
                null
            );

            $request->session()->flash('eventos_status', 'Evento suspendido. Se enviaron las notificaciones.');

            return api_success('Evento suspendido correctamente.', [
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al suspender el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo suspender el evento. Intenta de nuevo.', 500);
        }
    }

    public function posponer(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if ($event->status->isFinished()) {
            return api_error('El evento ya se celebró y no se puede posponer.', 409);
        }

        $validator = Validator::make($request->all(), [
            'exchange_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
            'suspend' => ['nullable', 'boolean'],
        ], [
            'exchange_at.required' => 'Escribe la nueva fecha del intercambio.',
            'exchange_at.date' => 'Escribe una fecha válida.',
            'exchange_at.after' => 'La nueva fecha debe ser posterior a hoy.',
            'reason.required' => 'Escribe el motivo de la postergación.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $date = Carbon::parse($data['exchange_at']);
        $suspend = (bool) ($data['suspend'] ?? false);
        $wasSuspended = $event->isSuspended();
        $previousStatus = $wasSuspended ? $event->statusBeforeSuspend() : $event->status;

        try {
            $event->update([
                'status' => $suspend ? EventStatus::Suspended : $previousStatus,
                'previous_status' => $suspend
                    ? ($wasSuspended ? $event->previous_status : $event->status->value)
                    : null,
                'exchange_at' => $date,
                'postponed_at' => now(),
                'suspended_at' => $suspend ? ($event->suspended_at ?? now()) : null,
                'status_reason' => $data['reason'],
            ]);

            $this->sendToParticipants(
                $this->activeParticipants($event),
                'El evento fue pospuesto',
                'El evento fue pospuesto',
                [
                    'Hola, {name}.',
                    'El evento «'.$event->name.'» fue pospuesto.',
                    'Motivo: '.$data['reason'],
                    'Nueva fecha del intercambio: '.$date->format('d/m/Y').'.',
                    $suspend
                        ? 'El evento queda suspendido hasta nuevo aviso.'
                        : 'El evento sigue activo con la nueva fecha.',
                ],
                null
            );

            $request->session()->flash('eventos_status', 'Evento pospuesto. Se enviaron las notificaciones.');

            return api_success('Evento pospuesto correctamente.', [
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al posponer el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo posponer el evento. Intenta de nuevo.', 500);
        }
    }

    public function cancelar(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if ($event->status->isFinished()) {
            return api_error('El evento ya se celebró y no se puede cancelar.', 409);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo de la cancelación.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $reason = $validator->validated()['reason'];

        try {
            $this->sendToParticipants(
                $this->activeParticipants($event),
                'El evento fue cancelado',
                'El evento fue cancelado',
                [
                    'Hola, {name}.',
                    'El evento «'.$event->name.'» fue cancelado.',
                    'Motivo: '.$reason,
                ],
                null
            );

            AuditLog::create([
                'event_id' => $event->id,
                'user_id' => $request->user()?->id,
                'action' => 'event.cancelled',
                'payload' => [
                    'name' => $event->name,
                    'year' => $event->year,
                    'reason' => $reason,
                ],
            ]);

            $event->delete();

            $request->session()->flash('eventos_status', 'Evento cancelado y eliminado. Se enviaron las notificaciones.');

            return api_success('Evento cancelado y eliminado.', [
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al cancelar el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo cancelar el evento. Intenta de nuevo.', 500);
        }
    }

    public function participantes(Event $event): JsonResponse
    {
        $this->ensureAccess();

        // Asegura que también aparezcan los usuarios participantes creados
        // después del evento (con su invitación pendiente).
        if ($event->status->allowsInvitations()) {
            $this->invitaciones->generar($event);
        }

        $participations = $event->participations()
            ->with('user')
            ->get()
            ->filter(fn (EventParticipant $item) => $item->user !== null)
            ->sortBy(fn (EventParticipant $item) => mb_strtolower($item->user->name))
            ->values();

        $participants = $participations->map(fn (EventParticipant $participacion) => [
            'id' => $participacion->user->id,
            'name' => $participacion->user->name,
            'email' => $participacion->user->email,
            'username' => $participacion->user->username,
            'status' => $participacion->status->label(),
            'status_key' => $participacion->status->value,
            'reason' => $participacion->status_reason,
            'notes' => $participacion->notes ?? '',
            'invited_at' => format_date($participacion->invited_at, 'd/m/Y H:i', ''),
            'invitation_url' => $participacion->invitationUrl(),
        ]);

        return api_success('Participantes encontrados.', [
            'participants' => $participants,
            'count' => $participations->where('status', ParticipantStatus::Active)->count(),
            'total' => $participations->count(),
            'pending' => $participations->where('status', ParticipantStatus::Pending)->count(),
            'sorteo' => [
                'pares' => $event->assignments()->count(),
                'elegidos' => $event->assignments()->whereNotNull('drawn_at')->count(),
                'abierto' => $event->drawIsOpen(),
            ],
        ]);
    }

    /**
     * Reinicia el sorteo del evento: borra las parejas y los chats secretos
     * para que todos vuelvan a elegir. Queda en auditoría.
     */
    public function reiniciarSorteo(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if ($event->status->isFinished()) {
            return api_error('El evento ya finalizó; el sorteo no se puede reiniciar.', 409);
        }

        if ($event->isSuspended()) {
            return api_error('El evento está suspendido. Reactívalo antes de reiniciar el sorteo.', 409);
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return api_error('El evento aún no ha comenzado; todavía no hay sorteo que reiniciar.', 409);
        }

        try {
            $this->sorteo->reiniciar($event, $request->user());

            $request->session()->flash('eventos_status', 'Sorteo reiniciado. Los participantes pueden volver a elegir.');

            return api_success('Sorteo reiniciado correctamente.', [
                'redirect' => module_url('eventos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al reiniciar el sorteo', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo reiniciar el sorteo. Intenta de nuevo.', 500);
        }
    }

    private function participacion(Event $event, User $user): EventParticipant
    {
        $participacion = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->first();

        abort_if($participacion === null, 404, 'El usuario no tiene invitación en este evento.');

        return $participacion;
    }

    /**
     * @return Collection<int, User>
     */
    private function activeParticipants(Event $event): Collection
    {
        return $event->participants()
            ->wherePivot('status', ParticipantStatus::Active->value)
            ->where('users.is_active', true)
            ->get();
    }

    /**
     * @param  Collection<int, User>  $participants
     * @param  array<int, string>  $lines
     * @param  array{label: string, url: string}|null  $action
     */
    private function sendToParticipants(
        Collection $participants,
        string $subject,
        string $title,
        array $lines,
        ?array $action
    ): void {
        foreach ($participants as $participant) {
            $personalLines = array_map(
                fn (string $line) => str_replace('{name}', $participant->name, $line),
                $lines
            );

            send_mail(
                $participant->email,
                $subject,
                $title,
                $personalLines,
                $action,
                [
                    'recipient_name' => $participant->name,
                    'eyebrow' => 'Evento',
                    'preheader' => $subject,
                ]
            );
        }
    }
}
