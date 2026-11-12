<button type="button"
        class="btn btn-sm btn-outline-secondary moderacion-bloqueos"
        data-id="{{ $user->id }}"
        data-name="{{ $user->name }}"
        title="Gestionar bloqueos de {{ $user->name }}"
        aria-label="Gestionar bloqueos de {{ $user->name }}">
    <i class="fa-solid fa-user-lock" aria-hidden="true"></i>
</button>
