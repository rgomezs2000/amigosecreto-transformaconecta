@php($cuentaInactiva = ! $participant->is_active || $participant->locked_until !== null)
@php($retirado = $participation->status === \App\Enums\ParticipantStatus::Withdrawn)

<div class="participantes-modal">
    <p class="participantes-modal__eyebrow">Participante</p>
    <h3 class="participantes-modal__name">{{ $participant->name }}</h3>

    <dl class="perfil-data">
        <div class="perfil-data__item">
            <dt>ID</dt>
            <dd>{{ $participant->id }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Usuario</dt>
            <dd>{{ format_text($participant->username) }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Correo</dt>
            <dd>{{ format_text($participant->email) }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Teléfono</dt>
            <dd>{{ format_text($participant->phone) }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Rol</dt>
            <dd>{{ $participant->profile?->name ?? $participant->role->label() }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Cuenta</dt>
            <dd>
                @if ($cuentaInactiva)
                    <span class="status-badge status-badge--blocked">Inactiva</span>
                @else
                    <span class="status-badge status-badge--active">Activa</span>
                @endif
            </dd>
        </div>

        <div class="perfil-data__item">
            <dt>Participación</dt>
            <dd>
                <span class="status-badge {{ $retirado ? 'status-badge--inactive' : 'status-badge--active' }}">
                    {{ $participation->status->label() }}
                </span>
            </dd>
        </div>

        <div class="perfil-data__item">
            <dt>Amigo secreto</dt>
            <dd>@include('participantes.partials.estado-amigo', ['assignment' => $assignment])</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Confirmado</dt>
            <dd>{{ format_date($participation->confirmed_at) }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Alta en el sistema</dt>
            <dd>{{ format_date($participant->created_at) }}</dd>
        </div>

        <div class="perfil-data__item perfil-data__item--wide">
            <dt>Observaciones</dt>
            <dd class="participantes-text">{{ format_text($participation->notes, 'Sin observaciones') }}</dd>
        </div>
    </dl>

    <p class="participantes-modal__phase">
        <i class="fa-solid fa-diagram-project" aria-hidden="true"></i>
        <span>
            Fase {{ $estado['fase'] }} · {{ $estado['fase_label'] }} — {{ $estado['estado_label'] }}
            @if ($event)
                · {{ $event->name }} {{ $event->year }}
            @endif
        </span>
    </p>
</div>
