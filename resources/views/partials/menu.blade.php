@if ($groups->isEmpty())
    <p class="menu-empty">No tienes módulos asignados. Contacta al administrador.</p>
@else
    @foreach ($groups as $group => $modules)
        <div class="menu-group menu-group--{{ \Illuminate\Support\Str::slug($group) }}">
            <p class="menu-group__title">{{ $group }}</p>

            <ul class="menu-list">
                @foreach ($modules as $module)
                    <li>
                        <a class="menu-link" href="{{ route('modules.show', $module) }}">
                            <i class="fa-solid {{ $module->icon }}" aria-hidden="true"></i>
                            <span>{{ $module->name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
@endif

<div class="menu-group menu-group--sesion">
    <p class="menu-group__title">Sesión</p>

    <form method="POST" action="{{ route('logout') }}" class="menu-form">
        @csrf

        <button type="submit" class="menu-link menu-link--logout">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>Salir</span>
        </button>
    </form>
</div>

