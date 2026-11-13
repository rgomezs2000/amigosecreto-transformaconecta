@if ($event !== null && $event->status->isFinished())
    <p class="sorteo-note usuarios-asociados-aviso">
        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
        <span>
            El evento terminó: esta consulta es histórica y de solo lectura.
            Puedes elegir otro evento en el selector.
        </span>
    </p>
@elseif ($event !== null && $event->isSuspended())
    <p class="sorteo-note usuarios-asociados-aviso">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        <span>El evento está suspendido temporalmente; la consulta es de solo lectura.</span>
    </p>
@endif
