<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Clue;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sorteo ciego del amigo secreto.
 *
 * El reparto completo se calcula en el primer ingreso al módulo (una sola vez,
 * en una transacción) con una permutación cíclica: nadie se saca a sí mismo y
 * no aparecen parejas cruzadas (A→B y B→A) salvo que el evento lo permita.
 * Cada participante, al entrar, ve su pareja una sola vez y puede volver a
 * verla sin límite; el sorteo se cierra cuando todos ya eligieron.
 */
class SorteoService
{
    /** Frases de aliento que el amigo secreto incógnito envía por correo. */
    private const FRASES_ALIENTO = [
        'Que esta etapa te traiga muchas sonrisas y una ilusión pequeñita cada día.',
        'Alguien está pensando en ti con cariño y ya quiere saber qué te gustaría recibir.',
        'No hay prisa: disfruta el proceso y deja que la sorpresa haga su parte.',
        'Prepárate para un detalle elegido con calma, pensando en ti.',
        'Esta época es para regalar sin apuro y recibir sin culpa.',
    ];

    public function __construct(
        private readonly PushNotifier $push,
        private readonly EventoChat $chat,
    ) {}

    /**
     * Participantes que entran al sorteo: los activos del evento.
     *
     * @return Collection<int, User>
     */
    public function participantes(Event $event): Collection
    {
        return $event->participants()
            ->wherePivot('status', ParticipantStatus::Active->value)
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Situación del sorteo para un usuario concreto.
     *
     * @return array{
     *     abierto: bool,
     *     motivo: ?string,
     *     mensaje: ?string,
     *     revelado: bool,
     *     puede_descubrir: bool,
     *     amigo: ?array{id:int,name:string,username:string,notas:?string},
     *     elegidos: int,
     *     total: int
     * }
     */
    public function estado(?Event $event, User $user): array
    {
        $motivo = $this->motivoCierre($event);
        $assignment = $event !== null ? $this->asignacionDe($event, $user) : null;
        $total = $event !== null ? $this->participantes($event)->count() : 0;
        $elegidos = $event !== null
            ? Assignment::query()
                ->where('event_id', $event->id)
                ->whereNotNull('drawn_at')
                ->count()
            : 0;

        $revelado = $assignment?->drawn_at !== null;

        return [
            'abierto' => $motivo === null,
            'motivo' => $motivo,
            'mensaje' => $this->mensajeCierre($motivo),
            'revelado' => $revelado,
            'puede_descubrir' => $motivo === null && ! $revelado,
            'amigo' => $revelado ? $this->tarjetaAmigo($event, $assignment) : null,
            'elegidos' => $elegidos,
            'total' => $total,
        ];
    }

    /**
     * Por qué el sorteo no está abierto. `null` = abierto.
     *
     * Reglas del evento:
     *  1. sin iniciar (borrador o inscripción) → cerrado;
     *  2. pospuesto en inscripción → cerrado; pospuesto en sorteo → sigue abierto;
     *  3. suspendido → cerrado (temporalmente);
     *  4. cerrado/revelado → cerrado;
     *  5. sorteo concluido (ya eligieron todos) → cerrado, avanza la fase.
     */
    public function motivoCierre(?Event $event): ?string
    {
        if ($event === null) {
            return 'sin-evento';
        }

        if ($event->status === EventStatus::Suspended) {
            return 'suspendido';
        }

        if ($event->status->isFinished()) {
            return 'cerrado';
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return $event->isPostponed() ? 'pospuesto' : 'sin-iniciar';
        }

        if ($event->status === EventStatus::Drawn) {
            return 'concluido';
        }

        return null;
    }

    public function mensajeCierre(?string $motivo): ?string
    {
        return match ($motivo) {
            'sin-evento' => 'Todavía no hay un evento activo.',
            'sin-iniciar' => 'El evento aún no ha comenzado. Cuando lo inicie la organización se abrirá el sorteo.',
            'pospuesto' => 'El evento fue pospuesto. El sorteo se abrirá cuando vuelva a estar en marcha.',
            'suspendido' => 'El evento está suspendido temporalmente. El sorteo se reactiva cuando se levante la suspensión.',
            'cerrado' => 'El evento ya finalizó; el sorteo está cerrado.',
            'concluido' => 'El sorteo ya concluyó: todos los participantes eligieron a su amigo secreto.',
            default => null,
        };
    }

    /**
     * Calcula el reparto si todavía no existe (idempotente).
     *
     * @return Collection<int, Assignment>
     */
    public function repartir(Event $event): Collection
    {
        $ids = $this->participantes($event)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($ids) < 2) {
            throw new RuntimeException('Se necesitan al menos 2 participantes activos para sortear.');
        }

        if (count($ids) === 2 && ! $event->allow_mutual_pairs) {
            throw new RuntimeException(
                'Con 2 participantes el único reparto posible es cruzado. Habilita «permitir parejas mutuas» o suma más participantes.'
            );
        }

        $asignaciones = DB::transaction(function () use ($event, $ids) {
            // Bloquea la fila del evento: dos primeras entradas simultáneas no
            // pueden generar dos repartos distintos.
            $bloqueado = Event::query()->lockForUpdate()->find($event->id);

            $existentes = Assignment::query()->where('event_id', $event->id)->get();

            if ($existentes->isNotEmpty()) {
                return $existentes;
            }

            // Permutación cíclica: se baraja la nómina y cada quien regala al
            // siguiente de esa lista. Al ser un solo ciclo, nadie se saca a sí
            // mismo y no hay parejas cruzadas cuando hay 3 o más participantes.
            $receptores = $ids;
            shuffle($receptores);
            $n = count($receptores);
            $creadas = new Collection;

            foreach ($receptores as $indice => $giverId) {
                $creadas->push(Assignment::create([
                    'event_id' => $bloqueado->id,
                    'giver_id' => $giverId,
                    'receiver_id' => $receptores[($indice + 1) % $n],
                ]));
            }

            return $creadas;
        });

        $this->crearChatsSecretos($event, $asignaciones);

        return $asignaciones;
    }

    /**
     * El usuario descubre a su amigo secreto. La primera vez queda marcada;
     * después solo se vuelve a leer.
     */
    public function descubrir(Event $event, User $user): Assignment
    {
        if (! $event->drawIsOpen()) {
            throw new RuntimeException($this->mensajeCierre($this->motivoCierre($event)) ?? 'El sorteo está cerrado.');
        }

        $assignment = $this->asignacionDe($event, $user);

        if ($assignment === null) {
            $this->repartir($event);
            $assignment = $this->asignacionDe($event, $user);
        }

        if ($assignment === null) {
            throw new RuntimeException('No se pudo asignar un amigo secreto a tu cuenta.');
        }

        if ($assignment->drawn_at === null) {
            $assignment->forceFill(['drawn_at' => now()])->save();

            $this->enviarAliento($event, $user);
            $this->cerrarSiCompleto($event);
        }

        return $assignment->fresh(['receiver']);
    }

    /**
     * Cierra el sorteo cuando ya eligieron todos y avanza de fase.
     */
    public function cerrarSiCompleto(Event $event): bool
    {
        $event->refresh();

        if ($event->status !== EventStatus::DrawEnabled) {
            return false;
        }

        $participantes = $this->participantes($event);

        if ($participantes->isEmpty()) {
            return false;
        }

        $elegidos = Assignment::query()
            ->where('event_id', $event->id)
            ->whereIn('giver_id', $participantes->pluck('id'))
            ->whereNotNull('drawn_at')
            ->count();

        if ($elegidos < $participantes->count()) {
            return false;
        }

        DB::transaction(function () use ($event, $participantes) {
            $event->update([
                'status' => EventStatus::Drawn,
                'draw_closed_at' => now(),
            ]);

            $this->chat->anunciar(
                $event,
                'El sorteo ciego ha concluido: todos los participantes ya eligieron a su amigo secreto. Comienza la etapa de la lista de deseos.'
            );

            foreach ($participantes as $participante) {
                $this->push->send(
                    $participante,
                    'sorteo.concluido',
                    'El sorteo ha concluido',
                    'Todos eligieron a su amigo secreto. Ya puedes preparar tu lista de deseos.',
                    ['event_id' => $event->id],
                    module_url('ver-tu-amigo'),
                    'evento'
                );
            }
        });

        return true;
    }

    /**
     * Reinicia el reparto: borra las parejas y los chats secretos para que se
     * pueda volver a sortear. Solo lo usa la administración, con auditoría.
     */
    public function reiniciar(Event $event, ?User $admin): void
    {
        DB::transaction(function () use ($event, $admin) {
            $assignmentIds = Assignment::query()->where('event_id', $event->id)->pluck('id');

            if ($assignmentIds->isNotEmpty()) {
                Conversation::query()
                    ->whereIn('assignment_id', $assignmentIds)
                    ->get()
                    ->each(fn (Conversation $conversation) => $conversation->delete());
            }

            Assignment::query()->where('event_id', $event->id)->delete();

            $event->update([
                'status' => EventStatus::DrawEnabled,
                'draw_closed_at' => null,
            ]);

            AuditLog::create([
                'event_id' => $event->id,
                'user_id' => $admin?->id,
                'action' => 'draw.reset',
                'payload' => ['participants' => $this->participantes($event)->count()],
            ]);
        });

        $this->chat->anunciar(
            $event,
            'La organización reinició el sorteo. Vuelve a «Sorteo ciego» para elegir de nuevo a tu amigo secreto.'
        );
    }

    public function asignacionDe(Event $event, User $user): ?Assignment
    {
        return Assignment::query()
            ->where('event_id', $event->id)
            ->where('giver_id', $user->id)
            ->with('receiver')
            ->first();
    }

    /**
     * Tarjeta con lo único que el usuario puede ver de su amigo secreto.
     *
     * @return array{id:int,name:string,username:string,notas:?string}
     */
    public function tarjetaAmigo(Event $event, Assignment $assignment): array
    {
        $receiver = $assignment->receiver;

        $notas = $event->participants()
            ->where('users.id', $receiver?->id)
            ->first()?->pivot?->notes;

        return [
            'id' => (int) $receiver?->id,
            'name' => (string) ($receiver?->name ?? ''),
            'username' => (string) ($receiver?->username ?? ''),
            'notas' => $notas !== null && trim((string) $notas) !== '' ? (string) $notas : null,
        ];
    }

    /**
     * Pistas que el usuario ha recibido de su amigo secreto incógnito.
     *
     * Se consultan siempre por el receptor: el anonimato no se rompe.
     *
     * @return Collection<int, Clue>
     */
    public function pistasRecibidas(Event $event, User $user): Collection
    {
        return Clue::query()
            ->whereHas('assignment', function ($query) use ($event, $user) {
                $query->where('event_id', $event->id)->where('receiver_id', $user->id);
            })
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get();
    }

    /**
     * Correo de aliento: lo envía el amigo secreto incógnito del usuario (quien
     * le regala a él, no a quien él eligió) y nunca revela quién es.
     */
    private function enviarAliento(Event $event, User $user): void
    {
        $email = trim((string) $user->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Solo si alguien le regala a este usuario (su incógnito existe).
        $tieneIncognito = Assignment::query()
            ->where('event_id', $event->id)
            ->where('receiver_id', $user->id)
            ->exists();

        if (! $tieneIncognito) {
            return;
        }

        $frase = self::FRASES_ALIENTO[array_rand(self::FRASES_ALIENTO)];

        send_mail(
            $email,
            'Tu amigo secreto te dejó un mensaje',
            'Tienes un mensaje de tu amigo secreto',
            [
                'Hola, '.$user->name.'.',
                'Tu amigo secreto del evento «'.$event->name.'» ya te eligió y quiso dejarte unas palabras:',
                '«'.$frase.'»',
                'No sabrás quién es hasta la gran revelación. Mientras tanto, puedes ir pensando en tu lista de deseos.',
            ],
            ['label' => 'Abrir el sistema', 'url' => module_url('ver-tu-amigo')],
            [
                'recipient_name' => $user->name,
                'eyebrow' => 'Amigo secreto',
                'preheader' => 'Un mensaje anónimo para ti.',
            ]
        );
    }

    /**
     * Cada pareja tiene su chat privado desde que se reparte el sorteo.
     *
     * @param  Collection<int, Assignment>  $asignaciones
     */
    private function crearChatsSecretos(Event $event, Collection $asignaciones): void
    {
        foreach ($asignaciones as $assignment) {
            $conversation = Conversation::query()->firstOrCreate(
                ['assignment_id' => $assignment->id],
                [
                    'type' => 'secret',
                    'event_id' => $event->id,
                    'created_by' => $assignment->receiver_id,
                    'is_moderated' => true,
                ]
            );

            $conversation->users()->syncWithoutDetaching([
                $assignment->giver_id,
                $assignment->receiver_id,
            ]);
        }
    }
}
