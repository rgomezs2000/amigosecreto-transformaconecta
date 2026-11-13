@if ($assignment === null)
    <span class="status-badge status-badge--blocked"
          title="Todavía no se le asignó un amigo secreto">
        Sin asignar
    </span>
@elseif ($assignment->drawn_at === null)
    <span class="status-badge status-badge--active"
          title="Amigo secreto asignado, todavía no descubierto por el participante">
        Asignado
    </span>
@else
    <span class="status-badge status-badge--active"
          title="Amigo secreto asignado y ya consultado por el participante">
        Visto
    </span>
@endif
