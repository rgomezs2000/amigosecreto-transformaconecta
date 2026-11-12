<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Module;
use App\Models\User;
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
    private function ensureAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'eventos')
            ->where('is_active', true)
            ->first();

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

        $participants = User::query()
            ->where('role', UserRole::Participant->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('eventos.index', [
            'events' => $events,
            'participantUsers' => $participants,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'exchange_at' => ['required', 'date'],
            'status_reason' => ['nullable', 'string', 'max:1000'],
            'participants' => ['nullable', 'array'],
            'participants.*.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => 'El nombre del evento es obligatorio.',
            'exchange_at.required' => 'La fecha del intercambio es obligatoria.',
            'exchange_at.date' => 'Escribe una fecha válida.',
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
            ]);

            $notes = $request->input('participants', []);

            $participants = User::query()
                ->where('role', UserRole::Participant->value)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            foreach ($participants as $participant) {
                $participantNotes = is_array($notes[$participant->id] ?? null)
                    ? ($notes[$participant->id]['notes'] ?? null)
                    : null;

                $event->participants()->attach($participant->id, [
                    'status' => ParticipantStatus::Active->value,
                    'confirmed_at' => now(),
                    'notes' => $participantNotes,
                ]);
            }

            $request->session()->flash('eventos_status', 'Evento creado correctamente.');

            return api_success('Evento creado correctamente.', [
                'event_id' => $event->id,
                'redirect' => route('eventos.index'),
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
            return api_error('Ya existe un evento en curso para el año ' . $event->year . '.', 409);
        }

        $participants = $this->activeParticipants($event);

        if ($participants->isEmpty()) {
            return api_error('El evento no tiene participantes activos.', 422);
        }

        try {
            $event->update([
                'status' => EventStatus::DrawEnabled,
                'previous_status' => null,
                'started_at' => now(),
                'suspended_at' => null,
            ]);

            $this->sendToParticipants(
                $participants,
                'El evento ha comenzado',
                'Tu Amigo Secreto ha comenzado',
                [
                    'Hola, {name}.',
                    'El evento «' . $event->name . '» ha comenzado.',
                    'Se da paso al sorteo: ya puedes descubrir a tu amigo secreto.',
                    'Fecha del intercambio: ' . $event->exchange_at?->format('d/m/Y') . '.',
                ],
                ['label' => 'Entrar al sistema', 'url' => route('login')]
            );

            $request->session()->flash('eventos_status', 'Evento iniciado. Se enviaron las notificaciones.');

            return api_success('Evento iniciado correctamente.', [
                'redirect' => route('eventos.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al iniciar el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo iniciar el evento. Intenta de nuevo.', 500);
        }
    }

    public function suspender(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

        if ($event->status->isFinished()) {
            return api_error('El evento ya finalizó y no se puede suspender.', 409);
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
                    'El evento «' . $event->name . '» fue suspendido.',
                    'Motivo: ' . $reason,
                    'El plazo de la suspensión es indefinido. Te avisaremos cuando se reactive.',
                ],
                null
            );

            $request->session()->flash('eventos_status', 'Evento suspendido. Se enviaron las notificaciones.');

            return api_success('Evento suspendido correctamente.', [
                'redirect' => route('eventos.index'),
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
            return api_error('El evento ya finalizó y no se puede posponer.', 409);
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
                    'El evento «' . $event->name . '» fue pospuesto.',
                    'Motivo: ' . $data['reason'],
                    'Nueva fecha del intercambio: ' . $date->format('d/m/Y') . '.',
                    $suspend
                        ? 'El evento queda suspendido hasta nuevo aviso.'
                        : 'El evento sigue activo con la nueva fecha.',
                ],
                null
            );

            $request->session()->flash('eventos_status', 'Evento pospuesto. Se enviaron las notificaciones.');

            return api_success('Evento pospuesto correctamente.', [
                'redirect' => route('eventos.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al posponer el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo posponer el evento. Intenta de nuevo.', 500);
        }
    }

    public function cancelar(Request $request, Event $event): JsonResponse
    {
        $this->ensureAccess();

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
                    'El evento «' . $event->name . '» fue cancelado.',
                    'Motivo: ' . $reason,
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
                'redirect' => route('eventos.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al cancelar el evento', ['event_id' => $event->id, 'error' => $exception->getMessage()]);

            return api_error('No se pudo cancelar el evento. Intenta de nuevo.', 500);
        }
    }

    public function participantes(Event $event): JsonResponse
    {
        $this->ensureAccess();

        $participants = $event->participants()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'status' => $user->is_active ? 'Activo' : 'Inactivo',
                'notes' => $user->pivot?->notes ?? '',
            ]);

        return api_success('Participantes encontrados.', [
            'participants' => $participants,
            'count' => $participants->count(),
        ]);
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
