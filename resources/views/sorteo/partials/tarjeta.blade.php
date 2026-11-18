@php
    $amigo = $amigo ?? null;
@endphp

<article class="amigo-card">
    <p class="amigo-card__eyebrow">Tu amigo secreto es</p>

    <p class="amigo-card__name">{{ $amigo['name'] ?? '—' }}</p>

    <p class="amigo-card__user">Solo verás su nombre hasta la gran revelación.</p>
</article>
