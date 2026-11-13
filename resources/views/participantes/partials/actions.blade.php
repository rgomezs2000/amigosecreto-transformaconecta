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
</div>
