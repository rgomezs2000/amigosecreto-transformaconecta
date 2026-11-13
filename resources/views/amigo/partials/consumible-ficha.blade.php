<div class="amigo-consumible-ficha">
    <dl class="perfil-data">
        <div class="perfil-data__item perfil-data__item--wide">
            <dt>Obsequio</dt>
            <dd class="amigo-texto">{{ $consumible->description }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Valor en US$</dt>
            <dd class="amigo-valor">{{ $valorUsd }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Valor en Bs. (BCV)</dt>
            <dd class="amigo-valor">{{ $valorBs }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Fecha</dt>
            <dd>{{ format_date($consumible->given_on, 'd/m/Y') }}</dd>
        </div>

        <div class="perfil-data__item">
            <dt>Registrado</dt>
            <dd>{{ format_date($consumible->created_at) }}</dd>
        </div>
    </dl>
</div>
