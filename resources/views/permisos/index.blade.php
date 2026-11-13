@extends('layouts.app')

@section('title', 'Permisos · Amigo Secreto Inclusivo')
@section('shell-title', 'Permisos')
@section('shell-eyebrow', 'Maestros')

@section('page')
    @if (session('permisos_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('permisos_status') }}</span>
        </div>
    @endif

    <div class="roles-page">
        <section class="matriz-card roles-search-card" aria-label="Búsqueda de permisos">
            <div class="roles-search">
                <label for="permisos-search" class="form-label">Buscar permiso</label>
                <input type="search" class="form-control" id="permisos-search"
                       placeholder="Escribe para filtrar..." autocomplete="off">
            </div>

            <div class="roles-actions">
                <button type="button" class="btn btn-outline-secondary" id="permisos-clear">
                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de permisos">
            <div class="roles-table-actions">
                <button type="button" class="btn btn-primary" id="permisos-new">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>Nuevo permiso</span>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="permisos-delete-selected" disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    <span>Eliminar permisos</span>
                </button>
            </div>
            <table id="permisos-table" class="table table-striped align-middle w-100 roles-table">
                <thead>
                    <tr>
                        <th scope="col" class="roles-check">
                            <input type="checkbox" id="permisos-check-all" class="form-check-input"
                                   aria-label="Seleccionar todos los permisos">
                        </th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Permiso</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="roles-check"></th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Permiso</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="permisos-csrf" value="{{ csrf_token() }}">

    <div id="permisos-config"
         data-url-datos="{{ route('permisos.datos') }}"
         data-url-crear="{{ route('permisos.crear') }}"
         data-url-permiso="{{ url('/permisos') }}"
         data-url-eliminar-varios="{{ route('permisos.eliminar-varios') }}"
         data-url-index="{{ module_url('permisos') }}"
         hidden></div>

    <x-app-modal id="modal-permission-form" title="Nuevo permiso">
        <x-slot:body>
            <form id="form-permission" action="{{ route('permisos.crear') }}" method="post" novalidate>
                @csrf
                <input type="hidden" id="permissions-form-id" value="">

                <div class="field">
                    <label for="permissions-name" class="form-label">Permiso</label>
                    <input type="text" class="form-control" id="permissions-name" name="name"
                           maxlength="60" autocomplete="off">
                    <p class="field-message" id="permissions-name-error" role="status" aria-live="polite"></p>
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-permission" class="btn btn-primary" id="permissions-save">Guardar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-permission-view" title="Detalle del permiso">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>ID</dt>
                    <dd id="view-permission-id"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Permiso</dt>
                    <dd id="view-permission-name"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Módulos</dt>
                    <dd id="view-permission-modules"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Roles</dt>
                    <dd id="view-permission-roles"></dd>
                </div>
            </dl>
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
    <script src="{{ asset('js/permisos.js') }}?v={{ filemtime(public_path('js/permisos.js')) }}"></script>
@endpush
