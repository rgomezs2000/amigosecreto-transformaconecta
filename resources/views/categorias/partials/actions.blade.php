<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-category-edit"
            data-id="{{ $category->id }}" title="Modificar" aria-label="Modificar">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-category-view"
            data-id="{{ $category->id }}" title="Ver" aria-label="Ver">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-category-delete"
            data-id="{{ $category->id }}" data-name="{{ $category->name }}"
            title="Eliminar" aria-label="Eliminar">
        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
    </button>
</div>
