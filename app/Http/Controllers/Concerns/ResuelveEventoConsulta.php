<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Evento que consultan los módulos de monitoreo (Participantes y Usuarios
 * asociados): por defecto el actual y en curso, o el elegido en la consulta.
 */
trait ResuelveEventoConsulta
{
    /**
     * Evento «actual y en curso»: el más reciente que sigue abierto; si ya
     * terminaron todos, el más reciente del sistema.
     *
     * @param  Collection<int, Event>|null  $events
     */
    protected function eventoActual(?Collection $events = null): ?Event
    {
        $events ??= Event::query()->orderByDesc('year')->get();

        return $events->first(fn (Event $event) => ! $event->status->isFinished() && ! $event->isSuspended())
            ?? $events->first();
    }

    /**
     * Evento de la consulta (`evento` en la petición); si no llega o no
     * existe, se usa el evento actual.
     */
    protected function eventoSeleccionado(Request $request): ?Event
    {
        $id = (int) $request->input('evento', 0);

        if ($id > 0) {
            $event = Event::query()->find($id);

            if ($event !== null) {
                return $event;
            }
        }

        return $this->eventoActual();
    }
}
