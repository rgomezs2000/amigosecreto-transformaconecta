@php
    $status = $participation->status;
    $cuentaInactiva = ! $user->is_active || $user->locked_until !== null;
@endphp

<span class="status-badge {{ $status->badgeClass() }}">
    {{ $status->label() }}
</span>

@if ($cuentaInactiva)
    <span class="status-badge status-badge--blocked">Cuenta inactiva</span>
@endif

@if ($participation->status_reason)
    <p class="participantes-motivo">{{ $participation->status_reason }}</p>
@endif
