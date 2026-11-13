<div class="participantes-modal">
    <p class="participantes-modal__eyebrow">Participante</p>
    <h3 class="participantes-modal__name">{{ $participant->name }}</h3>

    @if ($wishlist === null)
        <p class="participantes-empty">
            <i class="fa-solid fa-list" aria-hidden="true"></i>
            <span>Sin lista de deseos registrada.</span>
        </p>
    @else
        <dl class="perfil-data">
            <div class="perfil-data__item perfil-data__item--wide">
                <dt>Deseos</dt>
                <dd class="participantes-text">{{ format_text($wishlist->likes, 'Sin deseos registrados') }}</dd>
            </div>

            <div class="perfil-data__item perfil-data__item--wide">
                <dt>Evitar / restricciones</dt>
                <dd class="participantes-text">{{ format_text($wishlist->avoid, 'Sin restricciones registradas') }}</dd>
            </div>

            <div class="perfil-data__item perfil-data__item--wide">
                <dt>Categorías</dt>
                <dd>
                    @forelse (($wishlist->categories ?? []) as $category)
                        <span class="perfil-badge">{{ $category }}</span>
                    @empty
                        <span class="participantes-empty">Sin categorías seleccionadas.</span>
                    @endforelse
                </dd>
            </div>

            <div class="perfil-data__item">
                <dt>Actualizada</dt>
                <dd>{{ format_date($wishlist->updated_at) }}</dd>
            </div>
        </dl>
    @endif
</div>
