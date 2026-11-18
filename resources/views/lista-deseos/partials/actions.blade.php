<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-deseo-ver"
            data-id="{{ $item->id }}" title="Ver deseo" aria-label="Ver deseo">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>

    @if ($editable)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-deseo-editar"
                data-id="{{ $item->id }}" title="Modificar deseo" aria-label="Modificar deseo">
            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        </button>

        <button type="button" class="btn btn-sm btn-outline-danger btn-deseo-eliminar"
                data-id="{{ $item->id }}" data-nombre="{{ $item->title }}"
                title="Eliminar deseo" aria-label="Eliminar deseo">
            <i class="fa-solid fa-trash" aria-hidden="true"></i>
        </button>
    @endif
</div>
