<h4 class="participantes-subtitle">Obsequio</h4>

@if ($gift === null)
    <p class="participantes-empty">
        <i class="fa-solid fa-gift" aria-hidden="true"></i>
        <span>Sin obsequio registrado.</span>
    </p>
@else
    <dl class="perfil-data">
        <div class="perfil-data__item">
            <dt>Obsequio</dt>
            <dd>{{ $gift->product }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Valor</dt>
            <dd class="participantes-valor">{{ $valor }}</dd>
        </div>

        <div class="perfil-data__item perfil-data__item--wide">
            <dt>Descripción</dt>
            <dd class="participantes-text">{{ format_text($gift->description, 'Sin descripción') }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Registrado</dt>
            <dd>{{ format_date($gift->registered_at) }}</dd>
        </div>
    </dl>
@endif
