<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-permission-edit"
            data-id="{{ $permission->id }}" title="Modificar" aria-label="Modificar">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-permission-view"
            data-id="{{ $permission->id }}" title="Ver" aria-label="Ver">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-permission-delete"
            data-id="{{ $permission->id }}" data-name="{{ $permission->name }}"
            title="Eliminar" aria-label="Eliminar">
        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
    </button>
</div>
