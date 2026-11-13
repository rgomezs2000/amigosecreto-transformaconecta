@php($evento = $estado['evento'] ?? null)

<section class="participantes-estado" aria-label="Estado del evento">
    @if ($evento === null)
        <p class="participantes-estado__text">{{ $estado['mensaje'] }}</p>
    @else
        <div class="participantes-estado__row">
            <span class="participantes-estado__pill">
                Fase {{ $estado['fase'] }} · {{ $estado['fase_label'] }}
            </span>

            <span class="status-badge {{ $estado['suspendido'] ? 'status-badge--blocked' : 'status-badge--active' }}">
                {{ $estado['estado_label'] }}
            </span>

            <span class="participantes-estado__meta">
                {{ $evento['nombre'] }} · {{ $evento['anio'] }}
                @if ($evento['intercambio'] !== '—')
                    · Intercambio: {{ $evento['intercambio'] }}
                @endif
            </span>
        </div>

        <p class="participantes-estado__text">{{ $estado['mensaje'] }}</p>

        <p class="participantes-estado__meta">
            Participantes: <strong>{{ $estado['participantes'] }}</strong>
            · Asignados: <strong>{{ $estado['asignados'] }}</strong>
            · Sin asignar: <strong>{{ $estado['sin_asignar'] }}</strong>
        </p>
    @endif
</section>
