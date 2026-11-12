<button type="button"
        class="btn btn-sm btn-outline-secondary moderacion-baneados"
        data-id="{{ $group->id }}"
        data-name="{{ $group->name ?: 'Grupo sin nombre' }}"
        title="Ver baneados de {{ $group->name ?: 'Grupo sin nombre' }}"
        aria-label="Ver baneados de {{ $group->name ?: 'Grupo sin nombre' }}">
    <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
</button>
