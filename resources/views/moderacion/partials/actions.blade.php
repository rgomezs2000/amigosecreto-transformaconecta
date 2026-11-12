<div class="moderacion-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary moderacion-detail" data-id="{{ $report->id }}">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        <span>Detalle</span>
    </button>

    @if ($conversationUrl !== null)
        <a class="btn btn-sm btn-primary" href="{{ $conversationUrl }}">
            <i class="fa-solid fa-comments" aria-hidden="true"></i>
            <span>Ir al chat</span>
        </a>
    @endif
</div>
