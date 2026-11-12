<div class="table-actions">
    <button type="button" class="btn btn-sm btn-outline-secondary btn-user-edit"
            data-id="{{ $user->id }}" title="Modificar" aria-label="Modificar">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-user-view"
            data-id="{{ $user->id }}" title="Ver" aria-label="Ver">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary btn-user-reset"
            data-id="{{ $user->id }}" data-name="{{ $user->name }}"
            title="Restablecer contraseña" aria-label="Restablecer contraseña">
        <i class="fa-solid fa-key" aria-hidden="true"></i>
    </button>
    @if ($user->is_active && $user->locked_until === null)
        <button type="button" class="btn btn-sm btn-outline-secondary btn-user-block"
                data-id="{{ $user->id }}" data-name="{{ $user->name }}"
                title="Bloquear" aria-label="Bloquear">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
        </button>
    @else
        <button type="button" class="btn btn-sm btn-outline-secondary btn-user-unlock"
                data-id="{{ $user->id }}" data-name="{{ $user->name }}"
                title="Desbloquear" aria-label="Desbloquear">
            <i class="fa-solid fa-lock-open" aria-hidden="true"></i>
        </button>
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary btn-user-delete"
            data-id="{{ $user->id }}" data-name="{{ $user->name }}"
            title="Eliminar" aria-label="Eliminar">
        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
    </button>
</div>
