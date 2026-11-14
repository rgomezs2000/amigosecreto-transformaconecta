@props([
    'title' => 'Administración',
    'description' => 'Este contenido solo lo ven las cuentas con rol Administrador. No aparece en la ayuda de los participantes.',
])

@php($adminId = 'admin-' . \Illuminate\Support\Str::slug($title))

<section {{ $attributes->merge(['class' => 'help-admin']) }} aria-labelledby="{{ $adminId }}">
    <header class="help-admin__header">
        <span class="help-admin__icon" aria-hidden="true">
            <i class="fa-solid fa-lock"></i>
        </span>

        <div class="help-admin__body">
            <p class="help-eyebrow">Solo administración</p>
            <h2 class="help-admin__title" id="{{ $adminId }}">{{ $title }}</h2>
            <p class="help-text">{{ $description }}</p>
        </div>
    </header>

    {{ $slot }}
</section>
