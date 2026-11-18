@extends('layouts.app')

@section('title', 'Intercambio de pistas · Amigo Secreto Inclusivo')
@section('shell-title', 'Intercambio de pistas')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    <section class="pistas-page" aria-label="Intercambio de pistas">
        @if ($event === null)
            <article class="sorteo-card">
                <p class="sorteo-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <span>Todavía no hay un evento activo.</span>
                </p>
            </article>
        @else
            <article class="sorteo-card amigo-resumen">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">{{ $event->name }}</h2>
                        <p class="sorteo-card__text">
                            Fase {{ \App\Support\FaseEvento::paso($event) }} ·
                            {{ \App\Support\FaseEvento::etiquetaDe($event) }}
                            @if ($amigo)
                                · Le escribes a <strong>{{ $amigo }}</strong>
                            @endif
                        </p>
                    </div>

                    <span class="status-badge {{ $participacion?->status->badgeClass() ?? 'status-badge--inactive' }}">
                        {{ $participacion?->status->label() ?? 'Sin invitación' }}
                    </span>
                </header>

                @unless ($participacion?->status->isParticipating())
                    <p class="sorteo-note">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <span>Debes aceptar la invitación y estar activo para intercambiar pistas.</span>
                    </p>
                @elseif (! $puedeEscribir)
                    <p class="sorteo-note">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <span>
                            El intercambio de pistas se activa cuando el sorteo ciego concluye.
                            @if ($event->status->isFinished())
                                El evento ya finalizó: las pistas quedan en solo lectura.
                            @endif
                        </span>
                    </p>
                @endunless
            </article>

            @if ($participacion?->status->isParticipating())
                <nav class="amigo-tabs" aria-label="Pistas del intercambio">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="pistas-tab-enviadas" data-bs-toggle="tab"
                                    data-bs-target="#pistas-panel-enviadas" type="button" role="tab"
                                    aria-controls="pistas-panel-enviadas" aria-selected="true">
                                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                Pistas al amigo secreto
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="pistas-tab-recibidas" data-bs-toggle="tab"
                                    data-bs-target="#pistas-panel-recibidas" type="button" role="tab"
                                    aria-controls="pistas-panel-recibidas" aria-selected="false">
                                <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                                Pistas del amigo secreto
                            </button>
                        </li>
                    </ul>
                </nav>

                <div class="tab-content amigo-tabs__content">
                    <div class="tab-pane fade show active" id="pistas-panel-enviadas" role="tabpanel"
                         aria-labelledby="pistas-tab-enviadas" tabindex="0">
                        <div class="pistas-toolbar">
                            <div class="pistas-toolbar__search">
                                <label for="pistas-enviadas-search" class="form-label">Buscar pista enviada</label>
                                <input type="search" class="form-control" id="pistas-enviadas-search"
                                       placeholder="Escribe la primera letra…" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="pistas-enviadas-clear">
                                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                                    <span>Limpiar</span>
                                </button>
                            </div>

                            <div class="pistas-toolbar__actions">
                                @if ($puedeEscribir)
                                    <button type="button" class="btn btn-primary" id="pista-nueva">
                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                        <span>Agregar pista</span>
                                    </button>
                                @endif
                            </div>
                        </div>

                        <p class="amigo-form-status" id="pistas-status" role="status" aria-live="polite"></p>

                        <div class="table-responsive">
                            <table id="pistas-enviadas-table"
                                   class="table table-striped align-middle w-100 roles-table"
                                   style="width:100% !important;">
                                <thead>
                                    <tr>
                                        <th scope="col">Fecha</th>
                                        <th scope="col">Pista</th>
                                        <th scope="col">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pistas-panel-recibidas" role="tabpanel"
                         aria-labelledby="pistas-tab-recibidas" tabindex="0">
                        <div class="pistas-toolbar">
                            <div class="pistas-toolbar__search">
                                <label for="pistas-recibidas-search" class="form-label">Buscar pista recibida</label>
                                <input type="search" class="form-control" id="pistas-recibidas-search"
                                       placeholder="Escribe la primera letra…" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="pistas-recibidas-clear">
                                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                                    <span>Limpiar</span>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="pistas-recibidas-table"
                                   class="table table-striped align-middle w-100 roles-table"
                                   style="width:100% !important;">
                                <thead>
                                    <tr>
                                        <th scope="col">Fecha</th>
                                        <th scope="col">Pista</th>
                                        <th scope="col">Estado</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </section>

    <div id="pistas-config"
         data-url-datos="{{ route('intercambio-pistas.datos') }}"
         data-url-crear="{{ route('intercambio-pistas.crear') }}"
         data-url-pista="{{ route('intercambio-pistas.ver', ['clue' => '__ID__']) }}"
         data-puede-escribir="{{ $puedeEscribir ? '1' : '0' }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>

    <x-app-modal id="modal-pista" title="Agregar pista">
        <x-slot:body>
            <form id="form-pista" novalidate>
                <input type="hidden" id="pista-id" value="">

                <div class="field">
                    <label for="pista-body" class="form-label">Pista para tu amigo secreto</label>
                    <textarea class="form-control" id="pista-body" rows="4" maxlength="280"
                              placeholder="Una pista breve, sin revelar tu identidad…"></textarea>
                    <p class="field-message" id="pista-body-error" role="status" aria-live="polite"></p>
                </div>

                <p class="field-message" id="pista-form-error" role="status" aria-live="polite"></p>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" id="pista-limpiar">
                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                <span>Limpiar</span>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-primary" id="pista-guardar">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                <span>Agregar</span>
            </button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-pista-ver" title="Pista">
        <x-slot:body>
            <div id="pista-ver-body"></div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </x-slot:footer>
    </x-app-modal>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
@endpush

@push('vendor')
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
@endpush

@push('scripts')
    <script src="{{ asset('js/intercambio-pistas.js') }}?v={{ filemtime(public_path('js/intercambio-pistas.js')) }}"></script>
@endpush
