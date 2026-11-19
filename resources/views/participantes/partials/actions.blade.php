@php
    $status = $participation->status ?? null;
@endphp

<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-view"
            data-id="{{ $participant->id }}"
            title="Ver ficha" aria-label="Ver ficha del participante">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-wishlist"
            data-id="{{ $participant->id }}"
            title="Lista de deseos" aria-label="Ver lista de deseos">
        <i class="fa-solid fa-list" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-clues"
            data-id="{{ $participant->id }}"
            title="Pistas" aria-label="Ver pistas del amigo secreto">
        <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-gifts"
            data-id="{{ $participant->id }}"
            title="Obsequios" aria-label="Ver obsequios">
        <i class="fa-solid fa-gift" aria-hidden="true"></i>
    </button>

    @if ($status === \App\Enums\ParticipantStatus::Pending)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-email" data-processing-manual="true"
                data-id="{{ $participant->id }}"
                title="Enviar invitación por correo" aria-label="Enviar invitación por correo">
            <i class="fa-solid fa-envelope" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-chat" data-processing-manual="true"
                data-id="{{ $participant->id }}"
                title="Enviar invitación por chat" aria-label="Enviar invitación por chat">
            <i class="fa-solid fa-comments" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-notify" data-processing-manual="true"
                data-id="{{ $participant->id }}"
                title="Notificar invitación" aria-label="Notificar invitación">
            <i class="fa-solid fa-bell" aria-hidden="true"></i>
        </button>
        @if ($participation->invitationUrl())
            <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-link" data-processing-manual="true"
                    data-url="{{ $participation->invitationUrl() }}"
                    title="Copiar enlace personal" aria-label="Copiar enlace personal">
                <i class="fa-solid fa-link" aria-hidden="true"></i>
            </button>
        @endif
        <button type="button" class="btn btn-sm btn-outline-secondary btn-participant-discard" data-processing-manual="true"
                data-id="{{ $participant->id }}"
                title="Descartar invitación" aria-label="Descartar invitación">
            <i class="fa-solid fa-ban" aria-hidden="true"></i>
        </button>
    @endif

    @if ($status === \App\Enums\ParticipantStatus::Active)
        <button type="button" class="btn btn-sm btn-outline-danger btn-participant-expel" data-processing-manual="true"
                data-id="{{ $participant->id }}"
                title="Expulsar del evento" aria-label="Expulsar del evento">
            <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
        </button>
    @endif
</div>
