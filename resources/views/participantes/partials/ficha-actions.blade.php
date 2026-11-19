@php($status = $participation->status ?? null)

@if ($status === \App\Enums\ParticipantStatus::Pending)
    <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-email" data-processing-manual="true"
            data-id="{{ $participant->id }}"
            title="Enviar invitación por correo">
        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
        <span>Enviar por correo</span>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-chat" data-processing-manual="true"
            data-id="{{ $participant->id }}"
            title="Enviar invitación por chat">
        <i class="fa-solid fa-comments" aria-hidden="true"></i>
        <span>Enviar por chat</span>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-notify" data-processing-manual="true"
            data-id="{{ $participant->id }}"
            title="Notificar invitación">
        <i class="fa-solid fa-bell" aria-hidden="true"></i>
        <span>Notificar</span>
    </button>
    @if ($participation->invitationUrl())
        <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-link" data-processing-manual="true"
                data-url="{{ $participation->invitationUrl() }}"
                title="Copiar enlace personal">
            <i class="fa-solid fa-link" aria-hidden="true"></i>
            <span>Copiar enlace</span>
        </button>
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-discard" data-processing-manual="true"
            data-id="{{ $participant->id }}"
            title="Descartar invitación">
        <i class="fa-solid fa-ban" aria-hidden="true"></i>
        <span>Descartar</span>
    </button>
@elseif ($status === \App\Enums\ParticipantStatus::Active)
    <button type="button" class="btn btn-sm btn-outline-secondary btn-ficha-wishlist" data-processing-manual="true"
            data-id="{{ $participant->id }}">
        <i class="fa-solid fa-list" aria-hidden="true"></i>
        <span>Ver lista de deseos</span>
    </button>
    <button type="button" class="btn btn-sm btn-outline-danger btn-ficha-expel" data-processing-manual="true"
            data-id="{{ $participant->id }}">
        <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
        <span>Expulsar</span>
    </button>
@endif
