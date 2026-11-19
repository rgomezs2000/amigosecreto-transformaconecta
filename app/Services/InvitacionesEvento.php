<?php

namespace App\Services;

use App\Enums\ParticipantStatus;
use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invitaciones del evento (fase Unión).
 *
 * El evento no inscribe a nadie al crearse: genera una invitación personal por
 * cada usuario participante. La persona acepta o rechaza desde «Ver tu amigo»;
 * solo al aceptar queda activa y entra al sorteo. La administración puede
 * reenviar la invitación, descartar a quien no responde o expulsar a un activo.
 */
class InvitacionesEvento
{
    public function __construct(
        private readonly PushNotifier $push,
        private readonly EventoChat $chat,
    ) {}

    /**
     * Crea las invitaciones pendientes de todos los usuarios participantes.
     */
    public function generar(Event $event): int
    {
        $creadas = 0;

        User::query()
            ->where('role', UserRole::Participant->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(function (User $user) use ($event, &$creadas) {
                $participacion = EventParticipant::query()->firstOrCreate(
                    ['event_id' => $event->id, 'user_id' => $user->id],
                    [
                        'status' => ParticipantStatus::Pending->value,
                        'invitation_token' => Str::random(64),
                        'status_changed_at' => now(),
                    ]
                );

                if ($participacion->wasRecentlyCreated) {
                    $creadas++;
                } elseif ($participacion->status === ParticipantStatus::Pending
                    && blank($participacion->invitation_token)) {
                    $participacion->forceFill([
                        'invitation_token' => Str::random(64),
                        'status_changed_at' => now(),
                    ])->save();
                }
            });

        return $creadas;
    }

    /**
     * Aviso inicial: el evento se creó y la invitación llegará.
     */
    public function anunciarCreacion(Event $event): void
    {
        $users = User::query()
            ->where('role', UserRole::Participant->value)
            ->where('is_active', true)
            ->get();

        $this->push->sendMany(
            $users,
            'evento.creado',
            'Se creó el evento «'.$event->name.'»',
            'La organización te hará llegar tu invitación personal. Cuando la recibas podrás aceptarla desde «Ver tu amigo».',
            ['event_id' => $event->id],
            module_url('ver-tu-amigo'),
            'evento'
        );
    }

    /**
     * Envía la invitación por correo, chat del sistema y notificación push.
     *
     * @return array{enviadas:int,pendientes:int}
     */
    public function enviar(Event $event, ?EventParticipant $solo = null): array
    {
        // Incluye a los usuarios participantes creados después del evento.
        $this->generar($event);

        $pendientes = $event->participations()
            ->where('status', ParticipantStatus::Pending->value)
            ->when($solo !== null, fn ($query) => $query->whereKey($solo->id))
            ->with('user')
            ->get()
            ->filter(fn (EventParticipant $participacion) => $participacion->user !== null);

        $enviadas = 0;

        foreach ($pendientes as $participacion) {
            $token = $this->rotarToken($participacion);
            $url = route('invitacion.abrir', ['token' => $token]);

            $this->notificar($event, $participacion, $url);
            $this->enviarCorreo($event, $participacion, $url);
            $this->enviarChat($event, $participacion, $token);
            $enviadas++;
        }

        if ($enviadas > 0) {
            $event->forceFill(['invitations_sent_at' => now()])->save();
        }

        return [
            'enviadas' => $enviadas,
            'pendientes' => $event->pendingParticipationsCount(),
        ];
    }

    /**
     * Envía la invitación por un solo canal. Cada envío rota el token para
     * invalidar los enlaces y las fichas de chat anteriores.
     *
     * @return array{channel:string,invitation_url:string}
     */
    public function enviarCanal(Event $event, EventParticipant $participacion, string $channel): array
    {
        if ($participacion->status !== ParticipantStatus::Pending) {
            throw new RuntimeException('La invitación ya fue respondida; no se pueden crear nuevas invitaciones.');
        }

        if (! $event->status->allowsInvitations()) {
            throw new RuntimeException('La etapa de Unión ya cerró; no se pueden enviar invitaciones.');
        }

        if ($participacion->user === null) {
            throw new RuntimeException('La persona de esta invitación ya no está disponible.');
        }

        $token = $this->rotarToken($participacion);
        $url = route('invitacion.abrir', ['token' => $token]);

        match ($channel) {
            'notify' => $this->notificar($event, $participacion, $url),
            'email' => $this->enviarCorreo($event, $participacion, $url),
            'chat' => $this->enviarChat($event, $participacion, $token),
            'link' => null,
            default => throw new RuntimeException('Canal de invitación no válido.'),
        };

        $event->forceFill(['invitations_sent_at' => now()])->save();

        return [
            'channel' => $channel,
            'invitation_url' => $url,
        ];
    }

    /**
     * Rota el token personal. Los enlaces y las fichas de chat anteriores
     * quedan vencidos aunque la invitación no se haya respondido.
     */
    private function rotarToken(EventParticipant $participacion): string
    {
        $token = Str::random(64);

        $participacion->forceFill([
            'invitation_token' => $token,
            'invited_at' => now(),
            'status_changed_at' => now(),
        ])->save();

        return $token;
    }

    private function notificar(Event $event, EventParticipant $participacion, string $url): void
    {
        $user = $participacion->user;

        if ($user === null) {
            return;
        }

        $this->push->send(
            $user,
            'evento.invitacion',
            'Invitación al Amigo Secreto «'.$event->name.'»',
            'Tu invitación está lista. Ábrela para aceptarla o rechazarla; solo al aceptar entrarás al sorteo.',
            [
                'event_id' => $event->id,
                'invitation_token' => $participacion->invitation_token,
            ],
            $url,
            'evento'
        );
    }

    private function enviarCorreo(Event $event, EventParticipant $participacion, string $url): void
    {
        $user = $participacion->user;

        if ($user === null) {
            return;
        }

        send_mail(
            $user->email,
            'Invitación al Amigo Secreto «'.$event->name.'»',
            'Te invitaron al Amigo Secreto',
            [
                'Hola, '.$user->name.'.',
                'La organización te invita al evento «'.$event->name.'».',
                'Fecha del intercambio: '.format_date($event->exchange_at, 'd/m/Y').'.',
                'Abre tu invitación y elige si participas. Si aceptas, quedarás activo y entrarás al sorteo ciego.',
                'Este enlace es personal: no lo compartas.',
            ],
            ['label' => 'Abrir mi invitación', 'url' => $url],
            [
                'recipient_name' => $user->name,
                'eyebrow' => 'Invitación',
                'preheader' => 'Tu invitación al Amigo Secreto está lista.',
            ]
        );
    }

    private function enviarChat(Event $event, EventParticipant $participacion, string $token): void
    {
        $user = $participacion->user;

        if ($user === null) {
            return;
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'event_id' => $event->id,
                'type' => 'system',
                'name' => 'Sistema · '.$event->name,
            ],
            [
                'created_by' => null,
                'is_moderated' => false,
                'is_read_only' => true,
            ]
        );

        $conversation->users()->syncWithoutDetaching([$user->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'body' => 'Tienes una invitación al evento «'.$event->name.'».',
            'type' => 'event',
            'event_id' => $event->id,
            'event_invitation_token' => $token,
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $conversation->touch();
    }

    /**
     * El usuario acepta la invitación: queda activo en el evento.
     */
    public function aceptar(EventParticipant $participacion, User $user): EventParticipant
    {
        $event = $participacion->event;

        if ((int) $participacion->user_id !== (int) $user->id) {
            throw new RuntimeException('Esta invitación no corresponde a tu cuenta.');
        }

        if ($event->status->isFinished()) {
            throw new RuntimeException('El evento ya finalizó; la invitación ya no está disponible.');
        }

        if (! $participacion->status->canRespondInvitation()) {
            throw new RuntimeException('La invitación ya fue respondida como «'.$participacion->status->label().'».');
        }

        if (! $event->status->allowsInvitations()) {
            throw new RuntimeException('La etapa de Unión ya cerró; la invitación ya no se puede aceptar.');
        }

        DB::transaction(function () use ($participacion) {
            $participacion->forceFill([
                'status' => ParticipantStatus::Active->value,
                'confirmed_at' => now(),
                'responded_at' => now(),
                'status_changed_at' => now(),
                'status_reason' => null,
            ])->save();
        });

        $this->avisarAdministracion(
            $event,
            'Invitación aceptada',
            $user->name.' aceptó la invitación al evento «'.$event->name.'».'
        );

        // El chat del evento se comparte solo con quien ya está activo.
        $this->chat->abrir($event);

        return $participacion->fresh();
    }

    /**
     * El usuario rechaza la invitación: queda declinado con su motivo.
     */
    public function rechazar(EventParticipant $participacion, User $user, string $reason): EventParticipant
    {
        $event = $participacion->event;

        if ((int) $participacion->user_id !== (int) $user->id) {
            throw new RuntimeException('Esta invitación no corresponde a tu cuenta.');
        }

        if (! $participacion->status->canRespondInvitation()) {
            throw new RuntimeException('La invitación ya fue respondida como «'.$participacion->status->label().'».');
        }

        if ($event->status->isFinished()) {
            throw new RuntimeException('El evento ya finalizó; la invitación ya no está disponible.');
        }

        $participacion->forceFill([
            'status' => ParticipantStatus::Declined->value,
            'responded_at' => now(),
            'status_changed_at' => now(),
            'status_reason' => trim($reason),
        ])->save();

        $this->avisarAdministracion(
            $event,
            'Invitación rechazada',
            $user->name.' rechazó la invitación al evento «'.$event->name.'». Motivo: '.trim($reason)
        );

        return $participacion->fresh();
    }

    /**
     * El participante se retira del evento (antes del sorteo).
     */
    public function retirar(EventParticipant $participacion, User $user, string $reason): EventParticipant
    {
        $event = $participacion->event;

        if ((int) $participacion->user_id !== (int) $user->id) {
            throw new RuntimeException('Esta participación no corresponde a tu cuenta.');
        }

        if (! $participacion->status->isParticipating()) {
            throw new RuntimeException('Solo un participante activo puede retirarse.');
        }

        if ($event->status->isFinished()) {
            throw new RuntimeException('El evento ya finalizó; la participación quedó cerrada.');
        }

        if (! $event->status->allowsRegistration() && $event->hasAssignments()) {
            throw new RuntimeException(
                'El sorteo ya se realizó. Para retirarte después del sorteo, escribe a la organización.'
            );
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Escribe el motivo del retiro.');
        }

        $participacion->forceFill([
            'status' => ParticipantStatus::Withdrawn->value,
            'responded_at' => now(),
            'status_changed_at' => now(),
            'status_reason' => trim($reason),
        ])->save();

        $this->avisarAdministracion(
            $event,
            'Participante retirado',
            $user->name.' se retiró del evento «'.$event->name.'». Motivo: '.trim($reason)
        );

        $this->sacarDelChatDelEvento($event, $user);

        return $participacion->fresh();
    }

    /**
     * La administración expulsa a un participante activo.
     */
    public function expulsar(EventParticipant $participacion, ?User $admin, string $reason): EventParticipant
    {
        $event = $participacion->event;

        if (! $participacion->status->canBeRemoved()) {
            throw new RuntimeException('Solo se puede expulsar a un participante activo o pendiente.');
        }

        if ($event->status->isFinished()) {
            throw new RuntimeException('El evento ya finalizó; la participación quedó cerrada.');
        }

        DB::transaction(function () use ($participacion, $event, $reason) {
            $asignaciones = Assignment::query()
                ->where('event_id', $event->id)
                ->where(function ($query) use ($participacion) {
                    $query->where('giver_id', $participacion->user_id)
                        ->orWhere('receiver_id', $participacion->user_id);
                })
                ->get();

            if ($asignaciones->isNotEmpty()) {
                Conversation::query()
                    ->whereIn('assignment_id', $asignaciones->pluck('id'))
                    ->get()
                    ->each(fn (Conversation $conversation) => $conversation->delete());

                Assignment::query()->whereKey($asignaciones->pluck('id'))->delete();
            }

            $participacion->forceFill([
                'status' => ParticipantStatus::Expelled->value,
                'responded_at' => now(),
                'status_changed_at' => now(),
                'status_reason' => trim($reason),
            ])->save();
        });

        $user = $participacion->user;

        if ($user !== null) {
            $this->push->send(
                $user,
                'evento.expulsado',
                'Ya no participas en «'.$event->name.'»',
                'La organización te retiró del evento. Motivo: '.trim($reason),
                ['event_id' => $event->id],
                module_url('ver-tu-amigo'),
                'evento'
            );

            $this->mensajeSistema(
                $event,
                $user,
                'La organización te retiró del evento «'.$event->name.'». Motivo: '.trim($reason)
            );
        }

        $this->registrarAuditoria($event, $admin, 'participant.expelled', $participacion, $reason);

        if ($user !== null) {
            $this->sacarDelChatDelEvento($event, $user);
        }

        return $participacion->fresh();
    }

    /**
     * Deja de compartir el chat del evento con quien ya no participa.
     */
    private function sacarDelChatDelEvento(Event $event, User $user): void
    {
        Conversation::query()
            ->where('event_id', $event->id)
            ->where('type', 'event')
            ->get()
            ->each(fn (Conversation $conversation) => $conversation->users()->detach($user->id));
    }

    /**
     * Descarta a quien nunca respondió para poder iniciar el evento.
     */
    public function descartar(EventParticipant $participacion, ?User $admin, string $reason = ''): EventParticipant
    {
        $event = $participacion->event;

        if ($participacion->status !== ParticipantStatus::Pending) {
            throw new RuntimeException('Solo se puede descartar una invitación pendiente.');
        }

        if ($event->status->isFinished()) {
            throw new RuntimeException('El evento ya finalizó; la invitación quedó cerrada.');
        }

        $participacion->forceFill([
            'status' => ParticipantStatus::Dismissed->value,
            'responded_at' => now(),
            'status_changed_at' => now(),
            'status_reason' => trim($reason),
        ])->save();

        $this->registrarAuditoria($event, $admin, 'participant.dismissed', $participacion, $reason);

        return $participacion->fresh();
    }

    /**
     * Devuelve la participación del usuario en el evento.
     */
    public function participacion(Event $event, User $user): ?EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Mensaje del chat del sistema en el buzón personal del usuario.
     */
    public function mensajeSistema(Event $event, User $user, string $body): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            [
                'event_id' => $event->id,
                'type' => 'system',
                'name' => 'Sistema · '.$event->name,
            ],
            [
                'created_by' => null,
                'is_moderated' => false,
                'is_read_only' => true,
            ]
        );

        $conversation->users()->syncWithoutDetaching([$user->id]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'body' => $body,
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $conversation->touch();
    }

    private function avisarAdministracion(Event $event, string $title, string $body): void
    {
        $this->push->sendToAdmins(
            'evento.invitacion.respuesta',
            $title,
            $body,
            ['event_id' => $event->id],
            module_url('participantes'),
            'evento'
        );
    }

    private function registrarAuditoria(
        Event $event,
        ?User $admin,
        string $action,
        EventParticipant $participacion,
        string $reason
    ): void {
        AuditLog::create([
            'event_id' => $event->id,
            'user_id' => $admin?->id,
            'action' => $action,
            'payload' => [
                'user_id' => $participacion->user_id,
                'reason' => $reason,
            ],
        ]);
    }

    /**
     * Participaciones activas del evento.
     *
     * @return Collection<int, EventParticipant>
     */
    public function activas(Event $event): Collection
    {
        return $event->participations()
            ->where('status', ParticipantStatus::Active->value)
            ->with('user')
            ->get();
    }
}
