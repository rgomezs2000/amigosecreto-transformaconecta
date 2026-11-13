<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * Fase del amigo secreto (los cinco pasos del flujo).
 *
 * La fase no se guarda en la base: se calcula con el estado del evento y su
 * calendario. Así no hay una tabla de progreso que se desincronice.
 *
 *  1. Creación / Unión     → borrador o inscripción
 *  2. Sorteo ciego         → sorteo habilitado
 *  3. Lista de deseos      → sorteado
 *  4. Intercambio de pistas→ sorteado con la dinámica ya iniciada
 *  5. Gran revelación      → cerrado, revelado o con la fecha de intercambio pasada
 *
 * Un evento suspendido conserva la fase en la que estaba: la suspensión es
 * temporal y, al reactivarlo, la dinámica sigue donde se quedó.
 */
final class FaseEvento
{
    /** @var array<int, string> */
    public const PASOS = [
        1 => 'Creación / Unión',
        2 => 'Sorteo ciego',
        3 => 'Lista de deseos',
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

    private static function pasoPorEstado(EventStatus $status, Event $event): int
    {
        if (in_array($status, [EventStatus::Draft, EventStatus::Registration], true)) {
            return 1;
        }

        if ($status === EventStatus::DrawEnabled) {
            return 2;
        }

        if ($status === EventStatus::Drawn) {
            if ($event->exchange_at !== null && $event->exchange_at->isPast()) {
                return 5;
            }

            if ($event->dynamic_starts_at !== null && $event->dynamic_starts_at->isPast()) {
                return 4;
            }

            return 3;
        }

        return 5;
    }
}
