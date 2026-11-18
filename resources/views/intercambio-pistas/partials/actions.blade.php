<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-pista-ver"
            data-id="{{ $clue->id }}" title="Ver pista" aria-label="Ver pista">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>

    @if ($editable)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-pista-editar"
                data-id="{{ $clue->id }}" title="Modificar pista" aria-label="Modificar pista">
            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        </button>

        <button type="button" class="btn btn-sm btn-outline-danger btn-pista-eliminar"
                data-id="{{ $clue->id }}" title="Eliminar pista" aria-label="Eliminar pista">
            <i class="fa-solid fa-trash" aria-hidden="true"></i>
        </button>
    @endif
</div>
