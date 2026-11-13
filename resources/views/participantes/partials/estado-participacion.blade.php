@php($retirado = $participation->status === \App\Enums\ParticipantStatus::Withdrawn)
@php($cuentaInactiva = ! $user->is_active || $user->locked_until !== null)

<span class="status-badge {{ $retirado ? 'status-badge--inactive' : 'status-badge--active' }}">
    {{ $participation->status->label() }}
</span>

@if ($cuentaInactiva)
    <span class="status-badge status-badge--blocked">Cuenta inactiva</span>
@endif
