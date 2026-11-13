@php
    $tiene = $gift !== null;
    $bloqueado = $tiene || ! $puedeEditar;
@endphp

<article class="sorteo-card amigo-gift" aria-labelledby="amigo-gift-title">
    <header class="sorteo-card__head">
        <div>
            <h2 class="sorteo-card__title" id="amigo-gift-title">
                <i class="fa-solid fa-gift" aria-hidden="true"></i>
                <span>Obsequio para tu amigo secreto</span>
            </h2>
            <p class="sorteo-card__text">
                Le regalas a <strong>{{ $estado['amigo']['name'] ?? 'tu amigo secreto' }}</strong>.
                Escribe el valor en dólares (US$); abajo verás su equivalente en bolívares a la tasa
                oficial del BCV.
            </p>
        </div>
    </header>

    <p class="amigo-tasa" id="amigo-tasa">
        <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
        <span>
            Tasa oficial BCV:
            <strong id="amigo-tasa-valor">{{ $tasa !== null ? 'Bs. '.format_rate($tasa) : 'No disponible' }}</strong>
            por US$
        </span>
    </p>

    @unless ($puedeEditar)
        <p class="sorteo-note amigo-gift__note">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span>El evento ya finalizó: el obsequio y los consumibles quedan en solo lectura.</span>
        </p>
    @endunless

    <form id="amigo-obsequio-form" novalidate>
        @csrf

        <div class="amigo-gift__fields">
            <div class="field">
                <label for="amigo-obsequio-nombre" class="form-label">Nombre del obsequio</label>
                <input type="text" class="form-control" id="amigo-obsequio-nombre" name="nombre"
                       maxlength="150" autocomplete="off" value="{{ $gift?->product }}"
                       @disabled($bloqueado)>
                <p class="field-message" id="amigo-obsequio-nombre-error" role="status" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="amigo-obsequio-valor" class="form-label">Valor en US$</label>
                <input type="number" class="form-control" id="amigo-obsequio-valor" name="valor"
                       min="0.01" step="0.01" inputmode="decimal"
                       placeholder="0,00" value="{{ $gift?->value }}"
                       @disabled($bloqueado)>
                <p class="field-message" id="amigo-obsequio-valor-error" role="status" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="amigo-obsequio-bs" class="form-label">Valor en Bs. (tasa BCV)</label>
                <input type="text" class="form-control" id="amigo-obsequio-bs"
                       value="{{ $giftBs ?? '' }}" readonly aria-readonly="true"
                       placeholder="—" @disabled($bloqueado)>
                <p class="field-message amigo-hint" id="amigo-obsequio-bs-hint">
                    Se calcula con la tasa oficial del BCV.
                </p>
            </div>
        </div>

        <div class="amigo-gift__actions">
            <button type="button" class="btn btn-primary" id="amigo-obsequio-guardar"
                    @if (! ($puedeEditar && ! $tiene)) hidden @endif>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <span>Guardar</span>
            </button>

            <button type="button" class="btn btn-outline-secondary" id="amigo-obsequio-limpiar"
                    @if (! ($puedeEditar && ! $tiene)) hidden @endif>
                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                <span>Limpiar</span>
            </button>

            <button type="button" class="btn btn-outline-secondary" id="amigo-obsequio-cancelar" hidden>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                <span>Cancelar</span>
            </button>

            <button type="button" class="btn btn-outline-secondary" id="amigo-obsequio-modificar"
                    @unless ($puedeEditar && $tiene) hidden @endunless>
                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                <span>Modificar</span>
            </button>
        </div>
    </form>

    <p class="amigo-gift__meta" id="amigo-obsequio-meta" @unless ($tiene) hidden @endunless>
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        <span id="amigo-obsequio-meta-texto">
            Registrado el {{ format_date($gift?->registered_at) }}
        </span>
    </p>

    <p class="amigo-form-status" id="amigo-obsequio-status" role="status" aria-live="polite"></p>
</article>
