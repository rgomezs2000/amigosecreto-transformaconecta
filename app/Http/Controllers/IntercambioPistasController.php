<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Assignment;
use App\Models\Clue;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Module;
use App\Services\PushNotifier;
use App\Support\FaseEvento;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Intercambio de pistas (fase 4).
 *
 * Cada participante activo escribe pistas para su amigo secreto asignado y, a
 * la vez, lee las pistas que le deja su amigo secreto incógnito. Las pistas
 * enviadas se pueden crear, modificar, ver y eliminar; las recibidas son de
 * solo lectura y nunca revelan al emisor.
 */
class IntercambioPistasController extends Controller
{
    public function __construct(private readonly PushNotifier $push) {}

    public function index(): View
    {
        $this->ensureAccess();

        $event = $this->eventoActual();
        $user = request()->user();
        $participacion = $event !== null ? $this->participacion($event, $user->id) : null;
        $enviadas = $event !== null ? $this->asignacionEnviada($event, $user->id) : null;
        $recibidas = $event !== null ? $this->asignacionRecibida($event, $user->id) : null;

        $puedeEscribir = $participacion !== null
            && $participacion->status->isParticipating()
            && $event?->status === EventStatus::Drawn
            && $enviadas !== null;

        return view('intercambio-pistas.index', [
            'event' => $event,
            'participacion' => $participacion,
            'puedeEscribir' => $puedeEscribir,
            'tieneEnviadas' => $enviadas !== null,
            'tieneRecibidas' => $recibidas !== null,
            'amigo' => $enviadas?->receiver?->name,
            'incognito' => $recibidas !== null,
        ]);
    }

    /**
     * DataTable de pistas enviadas o recibidas.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoActual();
        $draw = (int) $request->input('draw', 1);

        if ($event === null) {
            return $this->vacio($draw);
        }

        $participacion = $this->participacion($event, $request->user()->id);

        if ($participacion === null || ! $participacion->status->isParticipating()) {
            return $this->vacio($draw);
        }

        $tipo = $request->input('tipo') === 'enviadas' ? 'enviadas' : 'recibidas';
        $assignment = $tipo === 'enviadas'
            ? $this->asignacionEnviada($event, $request->user()->id)
            : $this->asignacionRecibida($event, $request->user()->id);

        if ($assignment === null) {
            return $this->vacio($draw, $tipo);
        }

        $puedeEscribir = $tipo === 'enviadas'
            && $event->status === EventStatus::Drawn;

        $query = Clue::query()->where('assignment_id', $assignment->id);
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where('body', 'like', '%'.$search.'%');
        }

        $recordsFiltered = (clone $query)->count();
        $columnIndex = (int) $request->input('order.0.column', 0);
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $column = $columnIndex === 1 ? 'body' : 'sent_at';
        $query->orderBy($column, $direction)->orderBy('id');

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $data = $query->get()->map(fn (Clue $clue) => [
            'id' => $clue->id,
            'fecha' => format_date($clue->sent_at),
            'pista' => e($clue->body),
            'estado' => $tipo === 'recibidas'
                ? ($clue->read_at !== null
                    ? '<span class="status-badge status-badge--active">Leída</span>'
                    : '<span class="status-badge">Nueva</span>')
                : '',
            'actions' => $tipo === 'enviadas'
                ? view('intercambio-pistas.partials.actions', [
                    'clue' => $clue,
                    'editable' => $puedeEscribir,
                ])->render()
                : '',
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'tipo' => $tipo,
            'editable' => $puedeEscribir,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        [$event, $assignment, $error] = $this->contextoEscritura($request);

        if ($error !== null) {
            return $error;
        }

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $clue = Clue::create([
            'assignment_id' => $assignment->id,
            'body' => trim((string) $validator->validated()['body']),
            'sent_at' => now(),
        ]);

        $this->avisarReceptor($event, $assignment, $clue);

        return api_success('Pista enviada a tu amigo secreto.', [
            'pista' => $this->clueData($clue),
        ]);
    }

    public function modificar(Request $request, Clue $clue): JsonResponse
    {
        $this->ensureAccess();

        [$event, $assignment, $error] = $this->contextoEscritura($request);

        if ($error !== null) {
            return $error;
        }

        $this->propia($clue, $assignment);

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->errorValidacion($validator);
        }

        $clue->update(['body' => trim((string) $validator->validated()['body'])]);

        return api_success('Pista modificada correctamente.', [
            'pista' => $this->clueData($clue->fresh()),
        ]);
    }

    public function ver(Request $request, Clue $clue): JsonResponse
    {
        $this->ensureAccess();

        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $user = $request->user();
        $enviada = $this->asignacionEnviada($event, $user->id);
        $recibida = $this->asignacionRecibida($event, $user->id);

        $propia = $enviada !== null && (int) $clue->assignment_id === (int) $enviada->id;
        $ajena = $recibida !== null && (int) $clue->assignment_id === (int) $recibida->id;

        if (! $propia && ! $ajena) {
            abort(404, 'La pista no pertenece a tu intercambio.');
        }

        if ($ajena && $clue->read_at === null) {
            $clue->forceFill(['read_at' => now()])->save();
        }

        return api_success('Pista.', [
            'pista' => $this->clueData($clue),
            'html' => view('intercambio-pistas.partials.ficha', [
                'clue' => $clue,
                'propia' => $propia,
                'amigo' => $propia ? $enviada?->receiver?->name : null,
            ])->render(),
        ]);
    }

    public function eliminar(Request $request, Clue $clue): JsonResponse
    {
        $this->ensureAccess();

        [$event, $assignment, $error] = $this->contextoEscritura($request);

        if ($error !== null) {
            return $error;
        }

        $this->propia($clue, $assignment);
        $clue->delete();

        return api_success('Pista eliminada correctamente.');
    }

    /**
     * @return array{0: Event, 1: Assignment, 2: JsonResponse|null}
     */
    private function contextoEscritura(Request $request): array
    {
        $event = $this->eventoActual();

        if ($event === null) {
            return [new Event, new Assignment, api_error('Todavía no hay un evento activo.', 409)];
        }

        if ($event->status !== EventStatus::Drawn) {
            return [new Event, new Assignment, api_error(
                'Las pistas se escriben durante el intercambio de pistas, después del sorteo.',
                409
            )];
        }

        $participacion = $this->participacion($event, $request->user()->id);

        if ($participacion === null || ! $participacion->status->isParticipating()) {
            return [new Event, new Assignment, api_error('No participas activamente en este evento.', 409)];
        }

        $asignacion = $this->asignacionEnviada($event, $request->user()->id);

        if ($asignacion === null) {
            return [new Event, new Assignment, api_error('Todavía no tienes un amigo secreto asignado.', 409)];
        }

        return [$event, $asignacion, null];
    }

    private function avisarReceptor(Event $event, Assignment $assignment, Clue $clue): void
    {
        $receptor = $assignment->receiver;

        if ($receptor === null) {
            return;
        }

        $this->push->send(
            $receptor,
            'pista.nueva',
            'Tu amigo secreto te dejó una pista',
            'Entra a «Intercambio de pistas» para leerla.',
            ['event_id' => $event->id, 'clue_id' => $clue->id],
            module_url('intercambio-de-pista'),
            'evento'
        );
    }

    private function validator(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:280'],
        ], [
            'body.required' => 'Escribe la pista.',
            'body.max' => 'La pista no puede superar los 280 caracteres.',
        ]);
    }

    private function propia(Clue $clue, Assignment $assignment): void
    {
        if ((int) $clue->assignment_id !== (int) $assignment->id) {
            abort(404, 'La pista no pertenece a tu intercambio.');
        }
    }

    private function asignacionEnviada(Event $event, int $userId): ?Assignment
    {
        return Assignment::query()
            ->where('event_id', $event->id)
            ->where('giver_id', $userId)
            ->with('receiver')
            ->first();
    }

    private function asignacionRecibida(Event $event, int $userId): ?Assignment
    {
        return Assignment::query()
            ->where('event_id', $event->id)
            ->where('receiver_id', $userId)
            ->first();
    }

    private function participacion(Event $event, int $userId): ?EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->first();
    }

    private function vacio(int $draw, ?string $tipo = null): JsonResponse
    {
        return response()->json([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'tipo' => $tipo,
            'editable' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clueData(Clue $clue): array
    {
        return [
            'id' => $clue->id,
            'body' => (string) $clue->body,
            'fecha' => format_date($clue->sent_at),
            'leida' => $clue->read_at !== null,
        ];
    }

    private function errorValidacion(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return api_response(false, (string) $validator->errors()->first(), [
            'errors' => $validator->errors(),
        ], 422);
    }

    private function eventoActual(): ?Event
    {
        $event = Event::query()->orderByDesc('year')->first();

        return FaseEvento::sincronizar($event);
    }

    private function ensureAccess(): void
    {
        $module = Module::activeByCode('intercambio-de-pista');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }
}
