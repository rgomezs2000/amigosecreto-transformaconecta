<div class="participantes-aviso" role="status">
    <p class="participantes-aviso__title">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>Fase {{ $estado['fase'] }} · {{ $estado['fase_label'] }} — {{ $estado['estado_label'] }}</span>
    </p>

    <p class="participantes-aviso__text">{{ $estado['mensaje'] }}</p>

    @if (($contexto ?? '') === 'pistas')
        <p class="participantes-aviso__hint">
            Las pistas del amigo secreto y las pistas enviadas aparecen aquí en cuanto existan.
        </p>
    @elseif (($contexto ?? '') === 'obsequios')
        <p class="participantes-aviso__hint">
            El obsequio y los obsequios consumibles aparecen aquí cuando el participante los registre.
        </p>
    @endif
</div>
