<div class="participantes-modal">
    <p class="participantes-modal__eyebrow">{{ $propia ? 'Pista que enviaste' : 'Pista recibida' }}</p>
    <p class="amigo-clue__body">{{ $clue->body }}</p>

    <dl class="perfil-data">
        <div class="perfil-data__item">
            <dt>Fecha</dt>
            <dd>{{ format_date($clue->sent_at) }}</dd>
        </div>

        @if ($propia && $amigo)
            <div class="perfil-data__item">
                <dt>Para</dt>
                <dd>{{ $amigo }}</dd>
            </div>
        @endif

        @unless ($propia)
            <div class="perfil-data__item">
                <dt>De</dt>
                <dd>Tu amigo secreto (anónimo)</dd>
            </div>
        @endunless
    </dl>
</div>
