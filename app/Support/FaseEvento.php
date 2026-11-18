<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * Fase del amigo secreto (los cinco pasos del flujo reestructurado).
 *
 * La fase no se guarda en la base: se calcula con el estado del evento y su
 * calendario. Así no hay una tabla de progreso que se desincronice.
 *
 *  1. Creación            → borrador (el evento aún no se ha guardado)
 *  2. Unión               → inscripción con invitaciones en curso
 *  3. Sorteo ciego        → sorteo habilitado
 *  4. Intercambio de pistas→ sorteo concluido, antes de la fecha del evento
 *  5. Gran revelación     → el día del intercambio y después, hasta finalizar
 *
 * La lista de deseos dejó de ser una fase propia: vive dentro de la Unión y de
 * «Ver tu amigo», porque se habilita en cuanto el usuario acepta la invitación.
 *
 * Un evento suspendido conserva la fase en la que estaba: la suspensión es
 * temporal y, al reactivarlo, la dinámica sigue donde se quedó.
 */
final class FaseEvento
{
    /** @var array<int, string> */
    public const PASOS = [
        1 => 'Creación',
        2 => 'Unión',
        3 => 'Sorteo ciego',
        4 => 'Intercambio de pistas',
        5 => 'Gran revelación',
    ];

    public static function paso(?Event $event): int
    {
        if ($event === null) {
            return 1;
        }

        if ($event->isSuspended()) {
            return self::pasoPorEstado($event->statusBeforeSuspend(), $event);
        }

        return self::pasoPorEstado($event->status, $event);
    }

    public static function etiqueta(int $paso): string
    {
        return self::PASOS[$paso] ?? self::PASOS[1];
    }

    public static function etiquetaDe(?Event $event): string
    {
        return self::etiqueta(self::paso($event));
    }

    /**
     * ¿La gran revelación ya está disponible? Se abre el día exacto del
     * intercambio y queda disponible después de la fecha.
     */
    public static function revelacionDisponible(?Event $event): bool
    {
        if ($event === null || $event->isSuspended()) {
            return false;
        }

        return $event->status->allowsReveal() && $event->isRevealDay();
    }

    /**
     * Cierra el evento cuando la fecha del intercambio ya pasó. Se llama de
     * forma perezosa al entrar a los módulos del amigo secreto, para que el
     * estado final no dependa de un proceso programado del servidor.
     */
    public static function sincronizar(?Event $event): ?Event
    {
        if ($event === null || ! $event->shouldBeFinalized()) {
            return $event;
        }

        $event->update([
            'status' => EventStatus::Closed,
            'finalized_at' => $event->finalized_at ?? now(),
        ]);

        return $event->fresh();
    }

    private static function pasoPorEstado(EventStatus $status, Event $event): int
    {
        if (in_array($status, [EventStatus::Draft], true)) {
            return 1;
        }

        if ($status === EventStatus::Registration) {
            return 2;
        }

        if ($status === EventStatus::DrawEnabled) {
            return 3;
        }

        if ($status === EventStatus::Drawn) {
            return $event->isRevealDay() ? 5 : 4;
        }

        return 5;
    }
}
