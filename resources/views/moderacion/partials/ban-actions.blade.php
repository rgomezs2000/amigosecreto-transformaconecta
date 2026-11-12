<button type="button"
        class="btn btn-sm btn-outline-secondary moderacion-baneados"
        data-id="{{ $group->id }}"
        data-name="{{ $group->name ?: 'Grupo sin nombre' }}">
    <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
    <span>Ver baneados</span>
</button>
