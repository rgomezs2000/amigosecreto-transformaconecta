<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ParticipantStatus;
use App\Models\Assignment;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Support\FaseEvento;

/**
 * Estado del evento para las cabeceras y avisos de los módulos de monitoreo.
 *
 * Lo comparten Participantes, Usuarios asociados y los módulos de consulta de
 * lista de deseos, obsequios y pistas.
 */
trait ResuelveEstadoEvento
{
    /**
     * Estado del evento para la cabecera y los avisos de la página.
     *
     * @return array<string, mixed>
     */
    protected function estadoEvento(?Event $event): array
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
                'activos' => 0,
                'pendientes' => 0,
                'asignados' => 0,
                'sin_asignar' => 0,
                'reparto' => false,
                'mensaje' => 'Todavía no hay eventos registrados. Crea un evento para consultar a sus participantes.',
            ];
        }

        $paso = FaseEvento::paso($event);
        $base = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereHas('user');

        $participantes = (clone $base)->count();
        $activos = (clone $base)
            ->where('status', ParticipantStatus::Active->value)
            ->count();
        $pendientes = (clone $base)
            ->where('status', ParticipantStatus::Pending->value)
            ->count();
        $asignados = Assignment::query()->where('event_id', $event->id)->count();
        $sinAsignar = max(0, $activos - $asignados);

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
            'activos' => $activos,
            'pendientes' => $pendientes,
            'asignados' => $asignados,
            'sin_asignar' => $sinAsignar,
            'reparto' => $asignados > 0,
            'mensaje' => $this->mensajeEvento($event, $participantes, $activos, $pendientes, $asignados, $sinAsignar),
        ];
    }

    private function mensajeEvento(
        Event $event,
        int $participantes,
        int $activos,
        int $pendientes,
        int $asignados,
        int $sinAsignar
    ): string {
        if ($event->isSuspended()) {
            return 'El evento está suspendido temporalmente. La información se muestra en solo lectura.';
        }

        if ($event->status->isFinished()) {
            return 'El evento ya finalizó. La información queda disponible en solo lectura.';
        }

        if ($participantes === 0) {
            return 'Este evento todavía no tiene invitaciones generadas.';
        }

        if ($event->status->allowsInvitations() && $pendientes > 0) {
            return 'La Unión sigue abierta: hay '.$pendientes.' invitación(es) en espera. '
                .'El evento no puede iniciarse hasta que todos respondan o se descarten.';
        }

        if ($event->status->allowsInvitations()) {
            return 'Todos respondieron la invitación. La organización ya puede iniciar el sorteo.';
        }

        if ($asignados === 0) {
            return 'El evento está en la fase «'.FaseEvento::etiquetaDe($event).'» y todavía no se ha asignado el amigo secreto.';
        }

        if ($sinAsignar > 0) {
            return 'El amigo secreto ya está asignado a '.$asignados.' de '.$activos.' participantes activos. Los que faltan aparecen como «Sin asignar».';
        }

        return 'El amigo secreto ya está asignado a todos los participantes activos.';
    }
}
