{{-- Acciones del caso: solo icono, con la etiqueta para lectores de pantalla. --}}
<div class="moderacion-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary moderacion-detail"
            data-id="{{ $report->id }}" title="Ver detalle" aria-label="Ver detalle del caso">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>

    {{-- Chat del caso: se abre al tomarlo y queda en solo lectura al cerrarlo.
         En una sugerencia no hay chat: se responde desde la pestaña Sugerencia. --}}
    @if (! empty($ticketChatUrl))
        <a class="btn btn-sm btn-primary" href="{{ $ticketChatUrl }}"
           title="Chat" aria-label="Abrir el chat del caso">
            <i class="fa-solid fa-comments" aria-hidden="true"></i>
        </a>
    @endif

    {{-- Solo para moderación: revisar el mensaje original reportado. --}}
    @if (! empty($conversationUrl) && $report->isAttending())
        <a class="btn btn-sm btn-outline-secondary" href="{{ $conversationUrl }}"
           title="Ver mensaje reportado" aria-label="Ver el mensaje reportado">
            <i class="fa-solid fa-message" aria-hidden="true"></i>
        </a>
    @endif

    {{-- Soporte: ir al módulo o funcionalidad donde ocurrió el inconveniente. --}}
    @if (! empty($moduleUrl))
        <a class="btn btn-sm btn-outline-secondary" href="{{ $moduleUrl }}"
           title="Ir al módulo" aria-label="Ir al módulo donde ocurrió el caso">
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
        </a>
    @endif
</div>
