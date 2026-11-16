@extends('layouts.app')

@section('title', 'Contacto y soporte · Amigo Secreto Inclusivo')
@section('shell-title', 'Contacto y soporte')
@section('shell-eyebrow', 'Ayuda')

@section('page')
    <div class="contacto-page">
        <div class="moderacion-tabs" role="tablist" aria-label="Secciones de Contacto y soporte">
            <button type="button" class="moderacion-tab is-active" id="contacto-tab-contacto"
                    role="tab" aria-selected="true" aria-controls="contacto-panel-contacto"
                    data-tab="contacto">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                <span>Contacto</span>
            </button>
            <button type="button" class="moderacion-tab" id="contacto-tab-seguimiento"
                    role="tab" aria-selected="false" aria-controls="contacto-panel-seguimiento"
                    data-tab="seguimiento">
                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                <span>Seguimiento</span>
            </button>
        </div>

        <section id="contacto-panel-contacto" class="contacto-panel" data-panel="contacto"
                 role="tabpanel" aria-labelledby="contacto-tab-contacto">
            <div class="matriz-card contacto-card">
                <header class="contacto-card__head">
                    <p class="dashboard-eyebrow">Nuevo caso</p>
                    <h2 class="contacto-card__title">Crear ticket</h2>
                    <p class="contacto-card__text">
                        Cuéntanos qué ocurrió o qué necesitas. La administración recibirá el caso con
                        su número de ticket y podrás seguirlo desde la pestaña Seguimiento.
                    </p>
                </header>

                @include('contacto-soporte.partials.form', [
                    'types' => $types,
                    'endpoint' => route('contacto-soporte.crear'),
                    'mode' => 'user',
                ])

                <div class="contacto-actions">
                    <button type="button" class="btn btn-primary" id="reporte-crear">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        <span>Crear reporte</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="reporte-limpiar">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </section>

        <section id="contacto-panel-seguimiento" class="contacto-panel" data-panel="seguimiento"
                 role="tabpanel" aria-labelledby="contacto-tab-seguimiento" hidden>
            <section class="matriz-card roles-search-card contacto-search-card" aria-label="Búsqueda de casos">
                <div class="roles-search">
                    <label for="seguimiento-search" class="form-label">Buscar caso</label>
                    <input type="search" class="form-control" id="seguimiento-search"
                           placeholder="Ticket, tipo, motivo o mensaje..." autocomplete="off">
                </div>

                <div class="matriz-filter-card">
                    <div class="matriz-filter">
                        <label for="seguimiento-filter-type" class="form-label">Filtrar por</label>
                        <select class="form-select" id="seguimiento-filter-type">
                            <option value="all">Todos los casos</option>
                            <option value="date">Por fecha</option>
                            <option value="case_type">Tipo de caso</option>
                            <option value="status">Estado</option>
                        </select>
                    </div>

                    <div class="matriz-filter d-none" id="seguimiento-filter-date-wrap">
                        <label for="seguimiento-filter-from" class="form-label">Desde</label>
                        <input type="date" class="form-control" id="seguimiento-filter-from">
                    </div>

                    <div class="matriz-filter d-none" id="seguimiento-filter-to-wrap">
                        <label for="seguimiento-filter-to" class="form-label">Hasta</label>
                        <input type="date" class="form-control" id="seguimiento-filter-to">
                    </div>

                    <div class="matriz-filter d-none" id="seguimiento-filter-case-type-wrap">
                        <label for="seguimiento-filter-case-type" class="form-label">Tipo de caso</label>
                        <select class="form-select" id="seguimiento-filter-case-type">
                            <option value="">Todos los tipos</option>
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="matriz-filter d-none" id="seguimiento-filter-status-wrap">
                        <label for="seguimiento-filter-status" class="form-label">Estado</label>
                        <select class="form-select" id="seguimiento-filter-status">
                            <option value="pendiente">Por atender</option>
                            <option value="atendiendo">Atendiendo</option>
                            <option value="cerrado">Cerrado</option>
                        </select>
                    </div>

                    <div class="matriz-filter matriz-filter__action">
                        <button type="button" class="btn btn-outline-secondary" id="seguimiento-clear">
                            <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                            <span>Limpiar</span>
                        </button>
                    </div>
                </div>
            </section>

            <section class="matriz-card" aria-label="Casos del usuario">
                <div class="table-responsive">
                    <table id="seguimiento-table" class="table align-middle w-100">
                        <thead>
                            <tr>
                                <th scope="col" class="roles-id">Nº Ticket</th>
                                <th scope="col" class="roles-name">Tipo</th>
                                <th scope="col" class="roles-name">Motivo</th>
                                <th scope="col" class="roles-name">Estado</th>
                                <th scope="col" class="roles-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>
        </section>

        <x-app-modal id="modal-seguimiento" title="Seguimiento del caso" size="xl" scrollable>
            <x-slot:body>
                <ul class="nav nav-tabs moderacion-detail-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="seguimiento-tab-detalles"
                                data-bs-toggle="tab" data-bs-target="#seguimiento-pane-detalles"
                                type="button" role="tab" aria-controls="seguimiento-pane-detalles"
                                aria-selected="true">Detalles</button>
                    </li>
                    <li class="nav-item" role="presentation" id="seguimiento-tab-chat-wrap">
                        <button class="nav-link" id="seguimiento-tab-chat"
                                data-bs-toggle="tab" data-bs-target="#seguimiento-pane-chat"
                                type="button" role="tab" aria-controls="seguimiento-pane-chat"
                                aria-selected="false">Chat</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="seguimiento-tab-adjuntos"
                                data-bs-toggle="tab" data-bs-target="#seguimiento-pane-adjuntos"
                                type="button" role="tab" aria-controls="seguimiento-pane-adjuntos"
                                aria-selected="false">Adjuntos</button>
                    </li>
                    <li class="nav-item" role="presentation" id="seguimiento-tab-sugerencia-wrap" hidden>
                        <button class="nav-link" id="seguimiento-tab-sugerencia"
                                data-bs-toggle="tab" data-bs-target="#seguimiento-pane-sugerencia"
                                type="button" role="tab" aria-controls="seguimiento-pane-sugerencia"
                                aria-selected="false">Sugerencia</button>
                    </li>
                </ul>

                <div class="tab-content moderacion-detail-content">
                    <div class="tab-pane fade show active" id="seguimiento-pane-detalles"
                         role="tabpanel" aria-labelledby="seguimiento-tab-detalles" tabindex="0">
                        <dl class="perfil-data moderacion-detail-data">
                            <div class="perfil-data__item">
                                <dt>Nº de caso</dt>
                                <dd id="seguimiento-ticket"></dd>
                            </div>
                            <div class="perfil-data__item">
                                <dt>Tipo</dt>
                                <dd id="seguimiento-type"></dd>
                            </div>
                            <div class="perfil-data__item">
                                <dt>Estado</dt>
                                <dd id="seguimiento-status"></dd>
                            </div>
                            <div class="perfil-data__item">
                                <dt>Fecha</dt>
                                <dd id="seguimiento-date"></dd>
                            </div>
                            <div class="perfil-data__item perfil-data__item--wide">
                                <dt>Motivo</dt>
                                <dd id="seguimiento-reason"></dd>
                            </div>
                            <div class="perfil-data__item perfil-data__item--wide">
                                <dt>Mensaje</dt>
                                <dd id="seguimiento-message"></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="tab-pane fade" id="seguimiento-pane-chat"
                         role="tabpanel" aria-labelledby="seguimiento-tab-chat" tabindex="0">
                        <div class="seguimiento-chat" id="seguimiento-chat-thread"></div>
                        <form id="seguimiento-chat-form" class="seguimiento-chat-form" hidden>
                            <label for="seguimiento-chat-body" class="form-label">Mensaje para la administración</label>
                            <textarea class="form-control" id="seguimiento-chat-body" rows="3"
                                      maxlength="4000"></textarea>
                            <p class="field-message" id="seguimiento-chat-error" role="status"></p>
                            <button type="button" class="btn btn-primary" id="seguimiento-chat-send">
                                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                <span>Enviar mensaje</span>
                            </button>
                        </form>
                        <p class="seguimiento-chat__readonly" id="seguimiento-chat-readonly" hidden>
                            El chat está en solo lectura.
                        </p>
                    </div>

                    <div class="tab-pane fade" id="seguimiento-pane-adjuntos"
                         role="tabpanel" aria-labelledby="seguimiento-tab-adjuntos" tabindex="0">
                        <div class="seguimiento-attachments">
                            <section aria-labelledby="seguimiento-media-title">
                                <h3 class="seguimiento-attachments__title" id="seguimiento-media-title">Fotos y videos</h3>
                                <div class="seguimiento-attachments__grid" id="seguimiento-media"></div>
                            </section>
                            <section aria-labelledby="seguimiento-audios-title">
                                <h3 class="seguimiento-attachments__title" id="seguimiento-audios-title">Audios</h3>
                                <div class="seguimiento-attachments__list" id="seguimiento-audios"></div>
                            </section>
                            <section aria-labelledby="seguimiento-docs-title">
                                <h3 class="seguimiento-attachments__title" id="seguimiento-docs-title">Documentos</h3>
                                <div class="seguimiento-attachments__list" id="seguimiento-documentos"></div>
                            </section>
                            <section aria-labelledby="seguimiento-links-title">
                                <h3 class="seguimiento-attachments__title" id="seguimiento-links-title">Enlaces</h3>
                                <div class="seguimiento-attachments__list" id="seguimiento-links"></div>
                            </section>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="seguimiento-pane-sugerencia"
                         role="tabpanel" aria-labelledby="seguimiento-tab-sugerencia" tabindex="0">
                        <div class="moderacion-sugerencia">
                            <p class="moderacion-sugerencia__label">Tu sugerencia</p>
                            <p class="moderacion-sugerencia__text" id="seguimiento-sugerencia-text"></p>
                            <p class="moderacion-sugerencia__label">Cómo valoraste el sistema</p>
                            <div class="seguimiento-ratings" id="seguimiento-ratings"></div>
                        </div>
                    </div>
                </div>
            </x-slot:body>

            <x-slot:footer>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            </x-slot:footer>
        </x-app-modal>

        @include('contacto-soporte.partials.modales', ['mode' => 'user'])

        <div id="contacto-config"
             data-url-datos="{{ route('contacto-soporte.datos') }}"
             data-url-detalle="{{ route('contacto-soporte.detalle', ['report' => '__ID__']) }}"
             data-url-mensajes="{{ route('contacto-soporte.mensajes', ['report' => '__ID__']) }}"
             data-csrf="{{ csrf_token() }}"
             hidden></div>
    </div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
@endpush

@push('vendor')
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
@endpush

@push('scripts')
    <script src="{{ asset('js/reporte-form.js') }}?v={{ filemtime(public_path('js/reporte-form.js')) }}"></script>
    <script src="{{ asset('js/contacto-soporte.js') }}?v={{ filemtime(public_path('js/contacto-soporte.js')) }}"></script>
@endpush
