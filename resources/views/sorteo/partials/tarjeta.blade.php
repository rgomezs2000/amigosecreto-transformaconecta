@php
    $amigo = $amigo ?? null;
@endphp

<article class="amigo-card">
    <p class="amigo-card__eyebrow">Tu amigo secreto es</p>

    <p class="amigo-card__name">{{ $amigo['name'] ?? '—' }}</p>

    @if (! empty($amigo['username']))
        <p class="amigo-card__user">&#64;{{ $amigo['username'] }}</p>
    @endif

    @if (! empty($amigo['notas']))
        <p class="amigo-card__notes">
            <span class="amigo-card__label">Preferencias</span>
            {{ $amigo['notas'] }}
        </p>
    @endif
</article>
