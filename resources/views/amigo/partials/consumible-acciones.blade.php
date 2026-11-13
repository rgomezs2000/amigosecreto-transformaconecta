<div class="table-actions">
    @if ($puedeEditar)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-consumible-editar"
                data-id="{{ $consumible->id }}"
                title="Modificar" aria-label="Modificar el consumible {{ $consumible->description }}">
            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        </button>
    @endif

    <button type="button" class="btn btn-sm btn-outline-secondary btn-consumible-ver"
            data-id="{{ $consumible->id }}"
            title="Ver" aria-label="Ver el consumible {{ $consumible->description }}">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>

    @if ($puedeEditar)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-consumible-eliminar"
                data-id="{{ $consumible->id }}"
                data-nombre="{{ $consumible->description }}"
                title="Eliminar" aria-label="Eliminar el consumible {{ $consumible->description }}">
            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
        </button>
    @endif
</div>
