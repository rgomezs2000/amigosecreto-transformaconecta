<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Services\InvitacionesEvento;
use App\Support\FaseEvento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Invitación personal al evento (fase Unión).
 *
 * El enlace personal abre el evento: si no hay sesión se manda al acceso y,
 * después de entrar, vuelve a la invitación. La respuesta (aceptar o rechazar)
 * se da desde «Ver tu amigo», donde también queda la actividad de la
 * invitación cuando el modal no se abre.
 */
class InvitacionController extends Controller
{
    public function __construct(private readonly InvitacionesEvento $invitaciones) {}

    /**
     * Enlace personalizado. Sin sesión, al acceso con retorno automático.
     */
    public function abrir(Request $request, string $token): RedirectResponse
    {
        $participacion = $this->buscar($token);

        if ($participacion === null) {
            return redirect()->route('dashboard')
                ->with('invitacion_aviso', 'El enlace de invitación no es válido o ya no existe.');
        }

        if (! $request->user()) {
            // Laravel guarda esta URL como destino después del acceso.
            $request->session()->put('url.intended', route('invitacion.abrir', ['token' => $token]));

            return redirect()->route('login');
        }

        if ((int) $participacion->user_id !== (int) $request->user()->id) {
            return redirect()
                ->route('dashboard', ['invitacion' => 'usuario-invalido'])
                ->with(
                    'invitacion_usuario_invalido',
                    'Usuario inválido para esta invitación. Favor intentarlo con el usuario correcto.'
                );
        }

        FaseEvento::sincronizar($participacion->event);

        return redirect()->to(
            module_url('ver-tu-amigo').'?invitacion='.urlencode($token)
        );
    }

    /**
     * Datos de la invitación para el modal de «Ver tu amigo».
     */
    public function datos(Request $request, string $token): JsonResponse
    {
        $participacion = $this->buscar($token);

        if ($participacion === null) {
            return api_error('La invitación no es válida o ya no existe.', 404);
        }

        if ((int) $participacion->user_id !== (int) $request->user()->id) {
            return api_error('Esta invitación es personal y no corresponde a tu cuenta.', 403);
        }

        return api_success('Invitación.', [
            'invitacion' => $this->datosDe($participacion),
        ]);
    }

    public function aceptar(Request $request, string $token): JsonResponse
    {
        if ($request->user()?->isAdmin()) {
            return api_error('La administración no acepta ni rechaza invitaciones.', 403);
        }

        $participacion = $this->buscar($token);

        if ($participacion === null) {
            return api_error('La invitación no es válida o ya no existe.', 404);
        }

        try {
            $this->invitaciones->aceptar($participacion, $request->user());
        } catch (RuntimeException $exception) {
            return api_error($exception->getMessage(), 409);
        }

        return api_success('Aceptaste la invitación. Ya estás activo en el evento.', [
            'redirect' => module_url('ver-tu-amigo'),
            'invitacion' => $this->datosDe($participacion->fresh()),
        ]);
    }

    public function rechazar(Request $request, string $token): JsonResponse
    {
        if ($request->user()?->isAdmin()) {
            return api_error('La administración no acepta ni rechaza invitaciones.', 403);
        }

        $participacion = $this->buscar($token);

        if ($participacion === null) {
            return api_error('La invitación no es válida o ya no existe.', 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo por el que rechazas la invitación.',
            'reason.min' => 'El motivo es demasiado corto.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->invitaciones->rechazar(
                $participacion,
                $request->user(),
                (string) $validator->validated()['reason']
            );
        } catch (RuntimeException $exception) {
            return api_error($exception->getMessage(), 409);
        }

        return api_success('Rechazaste la invitación. Quedas como rechazado en el evento.', [
            'invitacion' => $this->datosDe($participacion->fresh()),
        ]);
    }

    /**
     * Retiro voluntario desde «Ver tu amigo», con motivo obligatorio.
     */
    public function retirar(Request $request): JsonResponse
    {
        $event = $this->eventoActual();

        if ($event === null) {
            return api_error('Todavía no hay un evento activo.', 409);
        }

        $participacion = $this->invitaciones->participacion($event, $request->user());

        if ($participacion === null) {
            return api_error('No tienes participación en el evento actual.', 409);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'reason.required' => 'Escribe el motivo por el que te retiras.',
            'reason.min' => 'El motivo es demasiado corto.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->invitaciones->retirar(
                $participacion,
                $request->user(),
                (string) $validator->validated()['reason']
            );
        } catch (RuntimeException $exception) {
            return api_error($exception->getMessage(), 409);
        }

        return api_success('Te retiraste del evento. Tu participación quedó como retirado.', [
            'redirect' => module_url('ver-tu-amigo'),
        ]);
    }

    private function buscar(string $token): ?EventParticipant
    {
        if (trim($token) === '') {
            return null;
        }

        return EventParticipant::query()
            ->with(['event', 'user'])
            ->where('invitation_token', $token)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDe(EventParticipant $participacion): array
    {
        $event = $participacion->event;

        return [
            'token' => $participacion->invitation_token,
            'status' => $participacion->status->value,
            'status_label' => $participacion->status->label(),
            'reason' => $participacion->status_reason,
            'can_accept' => $participacion->status->canRespondInvitation()
                && ! $event->status->isFinished()
                && ! $event->isSuspended(),
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'exchange_at' => format_date($event->exchange_at, 'd/m/Y'),
                'budget' => $event->budget() !== null ? 'US$ '.format_rate($event->budget()) : null,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
        ];
    }

    private function eventoActual(): ?Event
    {
        $event = Event::query()->orderByDesc('year')->first();

        return FaseEvento::sincronizar($event);
    }
}
