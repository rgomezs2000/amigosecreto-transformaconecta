{{-- Acciones del reporte: solo icono, con la etiqueta para lectores de pantalla. --}}
<div class="moderacion-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary moderacion-detail"
            data-id="{{ $report->id }}" title="Ver detalle" aria-label="Ver detalle del reporte">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>

    @if ($conversationUrl !== null && $report->isAttending())
        <a class="btn btn-sm btn-primary" href="{{ $conversationUrl }}" title="Ir al chat" aria-label="Ir al chat">
            <i class="fa-solid fa-comments" aria-hidden="true"></i>
        </a>
    @endif
</div>
