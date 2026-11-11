<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-module-edit"
            data-id="{{ $module->id }}" title="Modificar" aria-label="Modificar">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-module-view"
            data-id="{{ $module->id }}" title="Ver" aria-label="Ver">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-module-delete"
            data-id="{{ $module->id }}" data-name="{{ $module->name }}"
            title="Eliminar" aria-label="Eliminar">
        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
    </button>
</div>
