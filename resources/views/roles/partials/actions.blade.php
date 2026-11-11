<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-role-edit"
            data-id="{{ $role->id }}" title="Modificar" aria-label="Modificar">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-role-view"
            data-id="{{ $role->id }}" title="Ver" aria-label="Ver">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-role-delete"
            data-id="{{ $role->id }}" data-name="{{ $role->name }}"
            title="Eliminar" aria-label="Eliminar">
        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
    </button>
</div>
