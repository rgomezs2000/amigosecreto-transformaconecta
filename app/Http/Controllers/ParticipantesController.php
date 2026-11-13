<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResuelveEventoConsulta;
use App\Models\Assignment;
use App\Models\Clue;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Models\User;
use App\Models\Wishlist;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Participantes»: monitoreo de solo lectura del evento en curso.
 *
 * La administración elige un evento y consulta a sus participantes. Cada fila
 * abre una ficha en modal: datos personales, lista de deseos, pistas (las que
 * recibe y las que envía su amigo secreto) y obsequios (el regalo único y los
 * consumibles). No se crean ni se modifican datos desde aquí; la página de
 * eventos es la que inscribe y la del participante la que llena el resto.
 */
class ParticipantesController extends Controller
{
    use ResuelveEventoConsulta;

    /**
     * Solo la administración y solo si tiene el módulo concedido.
     *
     * La ficha de la lista de deseos incluye restricciones alimentarias y las
     * pistas se leen sin revelar al emisor: es información sensible, por eso
     * además del módulo se exige el rol de administración.
     */
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('participantes');

        if ($module === null) {
            abort(404);
        }

        $user = auth()->user();

        if (! $user?->hasModuleAccess($module)) {
            abort(403);
        }

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            abort(403, 'El módulo de participantes es solo para la administración.');
        }
    }

    public function index(): View
    {
        $this->ensureAccess();

        $events = Event::query()->orderByDesc('year')->get();
        $event = $this->eventoActual($events);

        return view('participantes.index', [
            'events' => $events,
            'event' => $event,
            'estado' => $this->estadoEvento($event),
        ]);
    }

    /**
     * DataTable de participantes del evento elegido.
     *
     * No hay criterios de filtro: solo la búsqueda libre y el evento. La
     * respuesta incluye el estado del evento para refrescar el aviso de la
     * página sin otra petición.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            $estado = $this->estadoEvento(null);

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'estado' => $estado,
                'estado_html' => view('participantes.partials.estado', ['estado' => $estado])->render(),
            ]);
        }

        $query = EventParticipant::query()
            ->where('event_participants.event_id', $event->id)
            ->join('users', 'users.id', '=', 'event_participants.user_id')
            ->select('event_participants.*')
            ->with('user');

        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.username', 'like', $like);
            });
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 1);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'users.email',
            3 => 'users.username',
            4 => 'event_participants.status',
            default => 'users.name',
        };

        // Desempate por id: sin él, con nombres repetidos MySQL no garantiza
        // un orden estable y al paginar una fila puede repetirse o saltarse.
        $query->orderBy($column, $direction)->orderBy('event_participants.id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $participations = $query->get();
        $assignments = Assignment::query()
            ->where('event_id', $event->id)
            ->get()
            ->keyBy('giver_id');
        $estado = $this->estadoEvento($event);

        $data = $participations->map(function (EventParticipant $participation) use ($assignments) {
            $user = $participation->user;

            return [
                'id' => (int) $user->id,
                'name' => e($user->name),
                'email' => e($user->email ?: '—'),
                'username' => e($user->username),
                'status' => view('participantes.partials.estado-participacion', [
                    'participation' => $participation,
                    'user' => $user,
                ])->render(),
                'secret' => view('participantes.partials.estado-amigo', [
                    'assignment' => $assignments->get($user->id),
                ])->render(),
                'actions' => view('participantes.partials.actions', ['participant' => $user])->render(),
            ];
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'estado' => $estado,
            'estado_html' => view('participantes.partials.estado', ['estado' => $estado])->render(),
        ]);
    }

    /**
     * Ficha del participante dentro del evento.
     */
    public function ficha(Request $request, User $participant): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return api_error('Todavía no hay un evento para consultar.', 409);
        }

        $participation = $this->participacion($event, $participant);
        $assignment = $this->asignacionDe($event, $participant->id);
        $estado = $this->estadoParticipante($event, $participant, $assignment);

        return api_success('Ficha del participante.', [
            'participant' => $this->participantData($participant),
            'estado' => $estado,
            'html' => view('participantes.partials.ficha', [
                'event' => $event,
                'participant' => $participant,
                'participation' => $participation,
                'assignment' => $assignment,
                'estado' => $estado,
            ])->render(),
        ]);
    }

    /**
     * Lista de deseos del participante (datos sensibles de la lista).
     */
    public function listaDeseos(Request $request, User $participant): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return api_error('Todavía no hay un evento para consultar.', 409);
        }

        $participation = $this->participacion($event, $participant);
        $wishlist = Wishlist::query()
            ->where('event_participant_id', $participation->id)
            ->first();

        $estado = $this->estadoParticipante($event, $participant);

        return api_success('Lista de deseos.', [
            'participant' => $this->participantData($participant),
            'estado' => $estado,
            'html' => view('participantes.partials.lista-deseos', [
                'event' => $event,
                'participant' => $participant,
                'wishlist' => $wishlist,
            ])->render(),
        ]);
    }

    /**
     * Pistas del participante, paginadas y por tipo:
     *
     *  - «recibidas»: las que le envía su amigo secreto (asignación donde él
     *    es el receptor).
     *  - «enviadas»: las que él le envía a su amigo secreto asignado
     *    (asignación donde él es quien regala).
     */
    public function pistas(Request $request, User $participant): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return api_error('Todavía no hay un evento para consultar.', 409);
        }

        $this->participacion($event, $participant);

        $tipo = $request->input('tipo') === 'enviadas' ? 'enviadas' : 'recibidas';
        $draw = (int) $request->input('draw', 1);

        $recibidas = Assignment::query()
            ->where('event_id', $event->id)
            ->where('receiver_id', $participant->id)
            ->pluck('id');

        $enviadas = Assignment::query()
            ->where('event_id', $event->id)
            ->where('giver_id', $participant->id)
            ->pluck('id');

        $assignmentIds = $tipo === 'enviadas' ? $enviadas : $recibidas;

        $query = Clue::query()->whereIn('assignment_id', $assignmentIds);
        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where('body', 'like', '%'.$search.'%');
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $column = $columnIndex === 1 ? 'body' : 'sent_at';

        $query->orderBy($column, $direction)->orderBy('id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $clues = $query->with('assignment.receiver')->get();
        $estado = $this->estadoParticipante($event, $participant);

        $data = $clues->map(function (Clue $clue) use ($tipo) {
            $row = [
                'fecha' => format_date($clue->sent_at),
                'pista' => e($clue->body),
                'leida' => $this->leidaBadge($clue),
            ];

            if ($tipo === 'enviadas') {
                $row['para'] = e($clue->assignment?->receiver?->name ?? '—');
            }

            return $row;
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'tipo' => $tipo,
            'participant' => $this->participantData($participant),
            'estado' => $estado,
            'aviso_html' => $estado['consulta']
                ? ''
                : view('participantes.partials.aviso-participante', [
                    'estado' => $estado,
                    'contexto' => 'pistas',
                ])->render(),
            'recibidas_count' => Clue::query()->whereIn('assignment_id', $recibidas)->count(),
            'enviadas_count' => Clue::query()->whereIn('assignment_id', $enviadas)->count(),
        ]);
    }

    /**
     * Obsequio único registrado por el participante para su amigo secreto.
     *
     * Los consumibles van en su propia tabla paginada (ver `consumibles`).
     */
    public function obsequios(Request $request, User $participant): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return api_error('Todavía no hay un evento para consultar.', 409);
        }

        $this->participacion($event, $participant);

        $assignment = $this->asignacionDe($event, $participant->id);
        $gift = $assignment?->exchangeGift;
        $estado = $this->estadoParticipante($event, $participant, $assignment);

        return api_success('Obsequios del participante.', [
            'participant' => $this->participantData($participant),
            'estado' => $estado,
            'totales' => $this->totalesConsumibles($assignment, $event),
            'aviso_html' => $estado['asignado']
                ? ''
                : view('participantes.partials.aviso-participante', [
                    'estado' => $estado,
                    'contexto' => 'obsequios',
                ])->render(),
            'html' => view('participantes.partials.obsequio', [
                'gift' => $gift,
                'valor' => $this->money($gift?->value, $event),
            ])->render(),
        ]);
    }

    /**
     * Obsequios consumibles del participante, paginados.
     */
    public function consumibles(Request $request, User $participant): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoSeleccionado($request);

        if ($event === null) {
            return api_error('Todavía no hay un evento para consultar.', 409);
        }

        $this->participacion($event, $participant);

        $assignment = $this->asignacionDe($event, $participant->id);
        $draw = (int) $request->input('draw', 1);

        $query = ConsumableGift::query()
            ->where('assignment_id', $assignment?->id ?? 0);

        $recordsTotal = (clone $query)->count();

        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where('description', 'like', '%'.$search.'%');
        }

        $recordsFiltered = (clone $query)->count();

        $columnIndex = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $column = match ($columnIndex) {
            1 => 'description',
            2 => 'quantity',
            3 => 'value',
            default => 'given_on',
        };

        $query->orderBy($column, $direction)->orderBy('id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $gifts = $query->get();

        $data = $gifts->map(fn (ConsumableGift $gift) => [
            'fecha' => format_date($gift->given_on, 'd/m/Y'),
            'nombre' => e($gift->description),
            'cantidad' => (int) $gift->quantity,
            'valor' => $this->money($gift->value, $event),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'estado' => $this->estadoParticipante($event, $participant, $assignment),
            'totales' => $this->totalesConsumibles($assignment, $event),
        ]);
    }

    /**
     * Participación del usuario en el evento; si no participa, 404.
     */
    private function participacion(Event $event, User $participant): EventParticipant
    {
        $participation = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $participant->id)
            ->with('user')
            ->first();

        if ($participation === null) {
            abort(404, 'El usuario no participa en este evento.');
        }

        return $participation;
    }

    private function asignacionDe(Event $event, int $giverId): ?Assignment
    {
        return Assignment::query()
            ->where('event_id', $event->id)
            ->where('giver_id', $giverId)
            ->with('exchangeGift')
            ->first();
    }

    /**
     * Estado del evento para la cabecera y los avisos de la página.
     *
     * @return array<string, mixed>
     */
    private function estadoEvento(?Event $event): array
    {
        if ($event === null) {
            return [
                'evento' => null,
                'fase' => 1,
                'fase_label' => FaseEvento::etiqueta(1),
                'estado_label' => 'Sin evento',
                'suspendido' => false,
                'pospuesto' => false,
                'participantes' => 0,
                'asignados' => 0,
                'sin_asignar' => 0,
                'reparto' => false,
                'mensaje' => 'Todavía no hay eventos registrados. Crea un evento para consultar a sus participantes.',
            ];
        }

        $paso = FaseEvento::paso($event);
        $participantes = EventParticipant::query()->where('event_id', $event->id)->count();
        $asignados = Assignment::query()->where('event_id', $event->id)->count();
        $sinAsignar = max(0, $participantes - $asignados);

        return [
            'evento' => [
                'id' => $event->id,
                'nombre' => $event->name,
                'anio' => $event->year,
                'estado' => $event->status->label(),
                'estado_visible' => $event->statusLabel(),
                'intercambio' => format_date($event->exchange_at, 'd/m/Y'),
            ],
            'fase' => $paso,
            'fase_label' => FaseEvento::etiqueta($paso),
            'estado_label' => $event->statusLabel(),
            'suspendido' => $event->isSuspended(),
            'pospuesto' => $event->isPostponed(),
            'participantes' => $participantes,
            'asignados' => $asignados,
            'sin_asignar' => $sinAsignar,
            'reparto' => $asignados > 0,
            'mensaje' => $this->mensajeEvento($event, $participantes, $asignados, $sinAsignar),
        ];
    }

    private function mensajeEvento(Event $event, int $participantes, int $asignados, int $sinAsignar): string
    {
        if ($participantes === 0) {
            return 'Este evento todavía no tiene participantes. Inscríbelos desde el módulo de Eventos.';
        }

        if ($event->isSuspended()) {
            return 'El evento está suspendido temporalmente. La información se muestra en solo lectura.';
        }

        if ($event->status->isFinished()) {
            return 'El evento ya finalizó. La información queda disponible en solo lectura.';
        }

        if ($asignados === 0) {
            if ($event->isPostponed()) {
                return 'El evento está pospuesto y todavía no se ha asignado el amigo secreto.';
            }

            return FaseEvento::paso($event) === 1
                ? 'El evento está en la fase «Creación / Unión»: todavía no se ha asignado el amigo secreto. Las pistas y los obsequios se registran después del sorteo.'
                : 'El evento está en la fase «Sorteo ciego» y todavía no se ha asignado el amigo secreto.';
        }

        if ($sinAsignar > 0) {
            return 'El amigo secreto ya está asignado a '.$asignados.' de '.$participantes.' participantes. Los que faltan aparecen como «Sin asignar».';
        }

        return 'El amigo secreto ya está asignado a todos los participantes.';
    }

    /**
     * Estado del amigo secreto para un participante concreto.
     *
     * `asignado` es «tiene amigo secreto al que regalar» (asignación como
     * quien regala); `recibe` es «tiene amigo secreto que le regala».
     * `consulta` indica si las pistas ya pueden existir en cualquier sentido.
     *
     * @return array<string, mixed>
     */
    private function estadoParticipante(Event $event, User $participant, ?Assignment $assignment = null): array
    {
        $assignment ??= $this->asignacionDe($event, $participant->id);
        $paso = FaseEvento::paso($event);

        $recibe = Assignment::query()
            ->where('event_id', $event->id)
            ->where('receiver_id', $participant->id)
            ->exists();

        $consulta = $assignment !== null || $recibe;

        return [
            'fase' => $paso,
            'fase_label' => FaseEvento::etiqueta($paso),
            'estado_label' => $event->statusLabel(),
            'asignado' => $assignment !== null,
            'recibe' => $recibe,
            'consulta' => $consulta,
            'visto' => $assignment?->drawn_at !== null,
            'suspendido' => $event->isSuspended(),
            'mensaje' => $consulta ? null : $this->mensajeSinAsignacion($event, $paso),
        ];
    }

    private function mensajeSinAsignacion(Event $event, int $paso): string
    {
        if ($event->isSuspended()) {
            return 'El evento está suspendido temporalmente. Todavía no se ha asignado el amigo secreto.';
        }

        return match ($paso) {
            1 => 'El evento está en la fase «Creación / Unión» y todavía no se ha asignado el amigo secreto. Esta ficha se completa cuando se realice el sorteo.',
            2 => 'El evento está en la fase «Sorteo ciego» y este participante todavía no tiene amigo secreto asignado.',
            default => 'Este participante todavía no tiene amigo secreto asignado, así que todavía no hay información que mostrar aquí.',
        };
    }

    /**
     * @return array{cantidad:int, valor:string}
     */
    private function totalesConsumibles(?Assignment $assignment, Event $event): array
    {
        if ($assignment === null) {
            return [
                'cantidad' => 0,
                'valor' => $this->money(0, $event),
            ];
        }

        $query = ConsumableGift::query()->where('assignment_id', $assignment->id);

        return [
            'cantidad' => (int) (clone $query)->sum('quantity'),
            'valor' => $this->money((clone $query)->sum('value'), $event),
        ];
    }

    /**
     * @return array{id:int, name:string, username:string}
     */
    private function participantData(User $participant): array
    {
        return [
            'id' => (int) $participant->id,
            'name' => (string) $participant->name,
            'username' => (string) $participant->username,
        ];
    }

    /**
     * Importe con la moneda del evento. Los valores se guardan en USD.
     */
    private function money(mixed $value, Event $event): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $symbol = $event->currency === 'USD' ? 'US$' : $event->currency;

        return $symbol.' '.format_rate($value);
    }

    private function leidaBadge(Clue $clue): string
    {
        if ($clue->read_at !== null) {
            return '<span class="status-badge status-badge--active">Leída</span>';
        }

        return '<span class="status-badge">Sin leer</span>';
    }
}
