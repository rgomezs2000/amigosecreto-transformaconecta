@php
    $amigo = $revelacion['amigo'] ?? null;
    $regalo = $revelacion['regalo'] ?? null;
    $consumibles = $revelacion['consumibles'] ?? [];
@endphp

<div class="revelacion-grid">
    <article class="amigo-card revelacion-amigo">
        <p class="amigo-card__eyebrow">Tu amigo secreto era</p>
        <p class="amigo-card__name">{{ $amigo['name'] ?? '—' }}</p>
        <p class="amigo-card__user">
            Te regaló sin que supieras quién era. Ahora la sorpresa se revela.
        </p>
    </article>

    <article class="revelacion-regalo">
        <h3 class="revelacion-regalo__title">
            <i class="fa-solid fa-gift" aria-hidden="true"></i>
            <span>El regalo que recibiste</span>
        </h3>

        @if ($regalo === null)
            <p class="sorteo-note">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span>Tu amigo secreto no registró un obsequio principal.</span>
            </p>
        @else
            <p class="revelacion-regalo__name">{{ $regalo['producto'] }}</p>

            @if (! empty($regalo['descripcion']))
                <p class="revelacion-regalo__desc">{{ $regalo['descripcion'] }}</p>
            @endif

            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>Valor</dt>
                    <dd>{{ $regalo['valor_usd'] }}</dd>
                </div>
                <div class="perfil-data__item">
                    <dt>En bolívares</dt>
                    <dd>{{ $regalo['valor_bs'] }}</dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Registrado</dt>
                    <dd>{{ $regalo['fecha'] }}</dd>
                </div>
            </dl>
        @endif

        @if (! empty($consumibles))
            <h4 class="revelacion-regalo__subtitle">Obsequios consumibles</h4>

            <ul class="revelacion-consumibles">
                @foreach ($consumibles as $consumible)
                    <li>
                        <span>
                            {{ $consumible['nombre'] }}
                            @if (! empty($consumible['descripcion']) && $consumible['descripcion'] !== $consumible['nombre'])
                                <small class="revelacion-consumibles__bs">{{ $consumible['descripcion'] }}</small>
                            @endif
                        </span>
                        <strong>{{ $consumible['valor_usd'] }}</strong>
                        <span class="revelacion-consumibles__bs">{{ $consumible['valor_bs'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="revelacion-total">
            <i class="fa-solid fa-calculator" aria-hidden="true"></i>
            <span>Total recibido: <strong>{{ $revelacion['total'] ?? '—' }}</strong></span>
        </p>

        @if ($revelacion['tasa'])
            <p class="amigo-hint">Tasa oficial BCV: {{ $revelacion['tasa'] }} por US$.</p>
        @endif
    </article>
</div>
