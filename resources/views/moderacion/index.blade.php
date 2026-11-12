@extends('layouts.app')

@section('title', 'Moderación · Amigo Secreto Inclusivo')
@section('shell-title', 'Moderación')
@section('shell-eyebrow', 'Administración')

@section('page')
    <div class="roles-page">
        <section class="matriz-card moderacion-summary" aria-label="Resumen de reportes">
            <div>
                <p class="dashboard-eyebrow">Reportes del chat</p>
                <p class="moderacion-summary__total">{{ format_count($total) }}</p>
                <p class="notification-row__hint">
                    Este módulo es de solo lectura: recopila los reportes hechos en el chat.
                    Desde cada fila puedes abrir la conversación para revisarla.
                </p>
            </div>
        </section>

        <section class="matriz-card roles-search-card" aria-label="Búsqueda de reportes">
            <div class="roles-search">
                <label for="moderacion-search" class="form-label">Buscar reporte</label>
                <input type="search" class="form-control" id="moderacion-search"
                       placeholder="Motivo, persona o mensaje..." autocomplete="off">
            </div>

            <div class="roles-actions">
                <button type="button" class="btn btn-outline-secondary" id="moderacion-clear">
                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de reportes">
            <table id="moderacion-table" class="table table-striped align-middle w-100 roles-table">
                <thead>
                    <tr>
                        <th scope="col" class="roles-id">Fecha</th>
                        <th scope="col" class="roles-name">Reportado por</th>
                        <th scope="col" class="roles-name">Autor del mensaje</th>
                        <th scope="col" class="roles-name">Motivo</th>
                        <th scope="col" class="roles-name">Conversación</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </section>
    </div>

    <div id="moderacion-config"
         data-url-datos="{{ route('moderacion.datos') }}"
         hidden></div>

    <x-app-modal id="modal-moderacion-detail" title="Detalle del reporte">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>Fecha</dt>
                    <dd id="moderacion-detail-date"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Reportado por</dt>
                    <dd id="moderacion-detail-reporter"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Autor del mensaje</dt>
                    <dd id="moderacion-detail-author"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Conversación</dt>
                    <dd id="moderacion-detail-conversation"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Motivo</dt>
                    <dd id="moderacion-detail-reason"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Mensaje reportado</dt>
                    <dd id="moderacion-detail-message"></dd>
                </div>
            </dl>
        </x-slot:body>

        <x-slot:footer>
            <a class="btn btn-primary" id="moderacion-detail-chat" href="#" hidden>
                <i class="fa-solid fa-comments" aria-hidden="true"></i>
                <span>Ir al chat</span>
            </a>
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
    <script src="{{ asset('js/moderacion.js') }}?v={{ filemtime(public_path('js/moderacion.js')) }}"></script>
@endpush
