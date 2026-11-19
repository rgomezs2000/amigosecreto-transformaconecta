@php
    $paso = max(1, min(5, (int) ($fase ?? 1)));
    $texto = $texto ?? ('Fase '.$paso.' · '.\App\Support\FaseEvento::etiqueta($paso));
@endphp
<span class="fase-badge fase-badge--{{ $paso }}"@if (! empty($campo)) data-evento-field="{{ $campo }}"@endif>{{ $texto }}</span>
