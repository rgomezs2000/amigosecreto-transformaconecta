<div class="participantes-modal">
    <p class="participantes-modal__eyebrow">Deseo</p>
    <h3 class="participantes-modal__name">{{ $item->title }}</h3>

    <dl class="perfil-data">
        <div class="perfil-data__item perfil-data__item--wide">
            <dt>Descripción</dt>
            <dd class="participantes-text">{{ format_text($item->description, 'Sin descripción') }}</dd>
        </div>

        <div class="perfil-data__item perfil-data__item--wide">
            <dt>Enlace</dt>
            <dd>
                @if ($item->url)
                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">{{ $item->url }}</a>
                @else
                    —
                @endif
            </dd>
        </div>

        <div class="perfil-data__item">
            <dt>Registrado</dt>
            <dd>{{ format_date($item->created_at, 'd/m/Y H:i') }}</dd>
        </div>
    </dl>
</div>
