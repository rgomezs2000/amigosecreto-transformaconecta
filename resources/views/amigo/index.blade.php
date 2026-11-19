@extends('layouts.app')

@section('title', 'Ver tu amigo · Amigo Secreto Inclusivo')
@section('shell-title', 'Ver tu amigo')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    @php
        $activo = $participacion?->status->isParticipating() ?? false;
        $pendiente = $participacion?->status === \App\Enums\ParticipantStatus::Pending;
        $puedeRetirar = $activo && (! $event?->hasAssignments() || $event?->status->allowsRegistration());
        $fase = $event !== null ? \App\Support\FaseEvento::paso($event) : 1;
    @endphp

    <section class="sorteo-page amigo-page" aria-label="Ver tu amigo">
        @if ($event === null)
            <article class="sorteo-card">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">Ver tu amigo</h2>
                        <p class="sorteo-card__text">Todavía no hay un evento activo.</p>
                    </div>
                </header>
            </article>
        @else
            <article class="sorteo-card amigo-resumen">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">{{ $event->name }}</h2>
                        <p class="sorteo-card__text">
                            Fase {{ \App\Support\FaseEvento::paso($event) }} ·
                            {{ \App\Support\FaseEvento::etiquetaDe($event) }}
                            · Intercambio: {{ $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha' }}
                        </p>
                    </div>

                    <span class="status-badge {{ $participacion?->status->badgeClass() ?? 'status-badge--inactive' }}">
                        {{ $participacion?->status->label() ?? 'Sin invitación' }}
                    </span>
                </header>

                @if ($event->budget() !== null)
                    <p class="amigo-tasa">
                        <i class="fa-solid fa-wallet" aria-hidden="true"></i>
                        <span>
                            Presupuesto máximo por obsequio:
                            <strong>US$ {{ format_rate($event->budget()) }}</strong>
                            · referencia
                            <strong data-bcv-usd="{{ $event->budget() }}">
                                Bs. {{ dollar_rate() !== null ? format_rate($event->budget() * dollar_rate()) : '—' }}
                            </strong>
                            a la tasa BCV
                        </span>
                    </p>
                @endif
            </article>

            {{-- Actividad de la invitación: muestra el estado de la
                 participación (activo, rechazado, retirado, expulsado…). --}}
            @if ($event !== null)
                <article class="sorteo-card amigo-invitacion"
                         data-status="{{ $participacion?->status->value ?? '' }}"
                         data-auto-open="{{ $pendiente ? '1' : '0' }}"
                         data-token="{{ $participacion?->invitation_token ?? '' }}">
                    <header class="sorteo-card__head">
                        <div>
                            <h2 class="sorteo-card__title">
                                <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                                <span>Invitación al evento</span>
                            </h2>
                            <p class="sorteo-card__text">
                                @if ($pendiente)
                                    Todavía no has respondido. Acepta para entrar al sorteo o rechaza indicando el motivo.
                                @elseif ($participacion?->status === \App\Enums\ParticipantStatus::Active)
                                    Aceptaste la invitación: estás activo en el evento.
                                @elseif ($participacion?->status === \App\Enums\ParticipantStatus::Declined)
                                    Rechazaste la invitación. El motivo quedó registrado.
                                @elseif ($participacion?->status === \App\Enums\ParticipantStatus::Withdrawn)
                                    Te retiraste del evento. El motivo quedó registrado.
                                @elseif ($participacion?->status === \App\Enums\ParticipantStatus::Expelled)
                                    La organización te retiró del evento.
                                @elseif ($participacion?->status === \App\Enums\ParticipantStatus::Dismissed)
                                    La invitación fue descartada por la organización.
                                @else
                                    No tienes una invitación para el evento actual.
                                @endif
                            </p>
                        </div>

                        <span class="status-badge {{ $participacion?->status->badgeClass() ?? 'status-badge--inactive' }}">
                            {{ $participacion?->status->label() ?? 'Sin invitación' }}
                        </span>
                    </header>

                    @if ($participacion?->status_reason)
                        <p class="evento-card__note">
                            <span class="evento-card__note-label">Motivo</span>
                            <span>{{ $participacion->status_reason }}</span>
                        </p>
                    @endif

                    @if ($pendiente)
                        <div class="sorteo-actions">
                            <button type="button" class="btn btn-primary" id="amigo-invitacion-abrir">
                                <i class="fa-solid fa-handshake" aria-hidden="true"></i>
                                <span>Responder invitación</span>
                            </button>
                        </div>
                    @endif
                </article>
            @endif

            @if ($activo && ! $estado['revelado'])
                {{-- Aceptó pero todavía no tiene amigo secreto descubierto. --}}
                <article class="sorteo-card amigo-fase" data-fase="{{ $fase }}">
                    <header class="sorteo-card__head">
                        <div>
                            <h2 class="sorteo-card__title">
                                @if ($fase === 2)
                                    Fase de unión
                                @elseif ($fase === 3)
                                    Sorteo ciego
                                @else
                                    Aún no tienes amigo secreto
                                @endif
                            </h2>
                            <p class="sorteo-card__text">
                                @if ($fase === 2)
                                    Estás en la fase de unión: favor esperar el sorteo.
                                @elseif ($fase === 3)
                                    Todavía no has descubierto a tu amigo secreto. Entra al sorteo ciego para verlo.
                                @else
                                    {{ $estado['mensaje'] }}
                                @endif
                            </p>
                        </div>
                    </header>

                    @if ($fase === 2)
                        <p class="sorteo-note">
                            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                            <span>Mientras esperas, puedes ir escribiendo tu lista de deseos.</span>
                        </p>

                        <div class="sorteo-actions">
                            <a class="btn btn-outline-secondary" href="{{ module_url('lista-de-deseos') }}">
                                <i class="fa-solid fa-list" aria-hidden="true"></i>
                                <span>Mi lista de deseos</span>
                            </a>

                            @if ($puedeRetirar)
                                <button type="button" class="btn btn-outline-danger btn-amigo-retirar">
                                    <i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i>
                                    <span>Retirar</span>
                                </button>
                            @endif
                        </div>
                    @elseif ($fase === 3)
                        <div class="sorteo-actions">
                            <a class="btn btn-primary" href="{{ module_url('asignar-amigo') }}">
                                <i class="fa-solid fa-dice" aria-hidden="true"></i>
                                <span>Sorteo ciego</span>
                            </a>

                            @if ($puedeRetirar)
                                <button type="button" class="btn btn-outline-danger btn-amigo-retirar">
                                    <i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i>
                                    <span>Retirar</span>
                                </button>
                            @endif
                        </div>
                    @elseif ($puedeRetirar)
                        <div class="sorteo-actions">
                            <button type="button" class="btn btn-outline-danger btn-amigo-retirar">
                                <i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i>
                                <span>Retirar</span>
                            </button>
                        </div>
                    @endif
                </article>
            @endif

            @if ($activo && $estado['revelado'])
                <nav class="amigo-tabs" aria-label="Secciones de tu amigo secreto">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="amigo-tab-mi-amigo" data-bs-toggle="tab"
                                    data-bs-target="#panel-mi-amigo" type="button" role="tab"
                                    aria-controls="panel-mi-amigo" aria-selected="true">
                                <i class="fa-solid fa-user-secret" aria-hidden="true"></i>
                                Mi amigo secreto
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="amigo-tab-deseos" data-bs-toggle="tab"
                                    data-bs-target="#panel-deseos" type="button" role="tab"
                                    aria-controls="panel-deseos" aria-selected="false">
                                <i class="fa-solid fa-list" aria-hidden="true"></i>
                                Lista de deseos del amigo
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="amigo-tab-pistas" data-bs-toggle="tab"
                                    data-bs-target="#panel-pistas" type="button" role="tab"
                                    aria-controls="panel-pistas" aria-selected="false">
                                <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                                Pistas de mi amigo secreto
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="amigo-tab-obsequios" data-bs-toggle="tab"
                                    data-bs-target="#panel-obsequios" type="button" role="tab"
                                    aria-controls="panel-obsequios" aria-selected="false">
                                <i class="fa-solid fa-gift" aria-hidden="true"></i>
                                Obsequios
                            </button>
                        </li>
                    </ul>
                </nav>

                <div class="tab-content amigo-tabs__content">
                    <div class="tab-pane fade show active" id="panel-mi-amigo" role="tabpanel"
                         aria-labelledby="amigo-tab-mi-amigo" tabindex="0">
                        <div id="amigo-tarjeta">
                            @include('sorteo.partials.tarjeta', ['amigo' => $estado['amigo']])
                        </div>

                        <div class="sorteo-actions">
                            <button type="button" class="btn btn-outline-secondary" id="amigo-ver-de-nuevo">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                <span>Ver de nuevo</span>
                            </button>

                            @if ($puedeRetirar)
                                <button type="button" class="btn btn-outline-danger btn-amigo-retirar">
                                    <i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i>
                                    <span>Retirar</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="tab-pane fade" id="panel-deseos" role="tabpanel"
                         aria-labelledby="amigo-tab-deseos" tabindex="0">
                        <div class="amigo-tabla-toolbar">
                            <label for="amigo-deseos-search" class="form-label">Buscar en la lista de deseos</label>
                            <div class="amigo-tabla-toolbar__row">
                                <input type="search" class="form-control" id="amigo-deseos-search"
                                       placeholder="Escribe la primera letra…" autocomplete="off">
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="amigo-deseos-clear">
                                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                                <span>Limpiar</span>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table id="amigo-deseos-table" class="table table-striped align-middle w-100 amigo-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col">Deseo</th>
                                        <th scope="col">Descripción</th>
                                        <th scope="col">Enlace</th>
                                        <th scope="col">Registrado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($deseosAmigo as $deseo)
                                        <tr>
                                            <td>{{ $deseo->title }}</td>
                                            <td>{{ format_text($deseo->description, '—') }}</td>
                                            <td>
                                                @if ($deseo->url)
                                                    <a href="{{ $deseo->url }}" target="_blank" rel="noopener noreferrer">Abrir</a>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>{{ format_date($deseo->created_at, 'd/m/Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="panel-pistas" role="tabpanel"
                         aria-labelledby="amigo-tab-pistas" tabindex="0">
                        <div class="amigo-tabla-toolbar">
                            <label for="amigo-pistas-search" class="form-label">Buscar en las pistas</label>
                            <div class="amigo-tabla-toolbar__row">
                                <input type="search" class="form-control" id="amigo-pistas-search"
                                       placeholder="Escribe la primera letra…" autocomplete="off">
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="amigo-pistas-clear">
                                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                                <span>Limpiar</span>
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table id="amigo-pistas-table" class="table table-striped align-middle w-100 amigo-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col">Fecha</th>
                                        <th scope="col">Pista</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pistas as $pista)
                                        <tr>
                                            <td>{{ format_date($pista->sent_at) }}</td>
                                            <td>{{ $pista->body }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="panel-obsequios" role="tabpanel"
                         aria-labelledby="amigo-tab-obsequios" tabindex="0">
                        @if ($giftsVisible)
                            @include('amigo.partials.obsequio')
                            @include('amigo.partials.consumibles')

                            @if ($puedeRetirar)
                                <div class="sorteo-actions">
                                    <button type="button" class="btn btn-outline-danger btn-amigo-retirar">
                                        <i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i>
                                        <span>Retirar del evento</span>
                                    </button>
                                </div>
                            @endif
                        @else
                            <p class="sorteo-note amigo-gifts-pending">
                                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                                <span>Los obsequios se habilitan cuando el sorteo concluya y el evento avance al intercambio de pistas.</span>
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        @endif

        <p class="sorteo-error" id="amigo-error" role="alert" hidden></p>
    </section>

    <div id="amigo-config"
         data-url-datos="{{ route('ver-tu-amigo.datos') }}"
         data-url-retirar="{{ route('ver-tu-amigo.retirar') }}"
         data-url-aceptar="{{ route('invitacion.aceptar', ['token' => '__TOKEN__']) }}"
         data-url-rechazar="{{ route('invitacion.rechazar', ['token' => '__TOKEN__']) }}"
         data-csrf="{{ csrf_token() }}"
         data-activo="{{ $activo ? '1' : '0' }}"
         hidden></div>

    @if ($giftsVisible)
        <div id="amigo-obsequio-config"
             data-url-tasa="{{ route('ver-tu-amigo.tasa') }}"
             data-url-obsequio="{{ route('ver-tu-amigo.obsequio.guardar') }}"
             data-url-obsequio-modificar="{{ route('ver-tu-amigo.obsequio.modificar') }}"
             data-url-consumibles="{{ route('ver-tu-amigo.consumibles.datos') }}"
             data-url-consumible-crear="{{ route('ver-tu-amigo.consumibles.crear') }}"
             data-url-consumible="{{ route('ver-tu-amigo.consumibles.ver', ['consumable' => '__ID__']) }}"
             data-puede-editar="{{ $puedeEditar ? '1' : '0' }}"
             data-tiene-obsequio="{{ $gift !== null ? '1' : '0' }}"
             data-tasa="{{ $tasa ?? '' }}"
             data-presupuesto="{{ $presupuesto ?? '' }}"
             data-csrf="{{ csrf_token() }}"
             hidden></div>

        @include('amigo.partials.modal-consumible')
        @include('amigo.partials.modal-consumible-ver')
    @endif

    {{-- Modal de respuesta a la invitación --}}
    <x-app-modal id="modal-invitacion" title="Invitación al evento">
        <x-slot:body>
            <p class="evento-modal__text" id="invitacion-resumen">
                @if ($invitacion)
                    Evento «{{ $invitacion['event_name'] }}» · intercambio
                    {{ $invitacion['exchange_at'] }}
                    @if ($invitacion['budget'])
                        · presupuesto {{ $invitacion['budget'] }}
                    @endif.
                @endif
                Si aceptas quedarás activo y entrarás al sorteo ciego.
            </p>

            <div class="invitacion-opciones">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="invitacion-respuesta"
                           id="invitacion-aceptar" value="aceptar" checked>
                    <label class="form-check-label" for="invitacion-aceptar">
                        Acepto la invitación y quiero participar
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="invitacion-respuesta"
                           id="invitacion-rechazar" value="rechazar">
                    <label class="form-check-label" for="invitacion-rechazar">
                        Rechazo la invitación
                    </label>
                </div>
            </div>

            <div class="field" id="invitacion-motivo-field" hidden>
                <label for="invitacion-motivo" class="form-label">Motivo del rechazo</label>
                <textarea class="form-control" id="invitacion-motivo" rows="3" maxlength="1000"></textarea>
                <p class="field-message" id="invitacion-motivo-error" role="status" aria-live="polite"></p>
            </div>

            <p class="field-message" id="invitacion-form-error" role="status" aria-live="polite"></p>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-primary" id="invitacion-responder">Enviar respuesta</button>
        </x-slot:footer>
    </x-app-modal>

    {{-- Modal de retiro voluntario --}}
    <x-app-modal id="modal-retirar" title="Retirar del evento">
        <x-slot:body>
            <p class="evento-modal__text">
                Si te retiras, tu participación queda como «Retirado» y ya no entras al sorteo.
            </p>
            <div class="field">
                <label for="retirar-motivo" class="form-label">Motivo del retiro</label>
                <textarea class="form-control" id="retirar-motivo" rows="3" maxlength="1000"></textarea>
                <p class="field-message" id="retirar-motivo-error" role="status" aria-live="polite"></p>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-outline-danger" id="retirar-confirm">Retirar</button>
        </x-slot:footer>
    </x-app-modal>
@endsection

@if ($activo && $estado['revelado'])
    @push('head')
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    @endpush

    @push('vendor')
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    @endpush
@endif

@push('scripts')
    <script src="{{ asset('js/sorteo.js') }}?v={{ filemtime(public_path('js/sorteo.js')) }}"></script>
    <script src="{{ asset('js/amigo.js') }}?v={{ filemtime(public_path('js/amigo.js')) }}"></script>

    @if ($giftsVisible)
        <script src="{{ asset('js/ver-tu-amigo.js') }}?v={{ filemtime(public_path('js/ver-tu-amigo.js')) }}"></script>
    @endif
@endpush




