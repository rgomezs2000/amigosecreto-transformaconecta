@extends('layouts.app')

@section('title', 'Roles · Amigo Secreto Inclusivo')
@section('shell-title', 'Roles')
@section('shell-eyebrow', 'Maestros')

@section('page')
    @if (session('roles_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('roles_status') }}</span>
        </div>
    @endif

    <div class="roles-page">
        <section class="matriz-card roles-search-card" aria-label="Búsqueda de roles">
            <div class="roles-search">
                <label for="roles-search" class="form-label">Buscar rol</label>
                <input type="search" class="form-control" id="roles-search"
                       placeholder="Escribe para filtrar..." autocomplete="off">
            </div>

            <div class="roles-actions">
                <button type="button" class="btn btn-outline-secondary" id="roles-clear">
                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de roles">
            <div class="roles-table-actions">
                <button type="button" class="btn btn-primary" id="roles-new">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>Nuevo rol</span>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="roles-delete-selected" disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    <span>Eliminar roles</span>
                </button>
            </div>
            <table id="roles-table" class="table table-striped align-middle w-100 roles-table">
                <thead>
                    <tr>
                        <th scope="col" class="roles-check">
                            <input type="checkbox" id="roles-check-all" class="form-check-input"
                                   aria-label="Seleccionar todos los roles">
                        </th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Rol</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="roles-check"></th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Rol</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="roles-csrf" value="{{ csrf_token() }}">

    <div id="roles-config"
         data-url-datos="{{ route('roles.datos') }}"
         data-url-crear="{{ route('roles.crear') }}"
         data-url-rol="{{ url('/roles') }}"
         data-url-eliminar-varios="{{ route('roles.eliminar-varios') }}"
         data-url-index="{{ route('roles.index') }}"
         hidden></div>

    <x-app-modal id="modal-role-form" title="Nuevo rol">
        <x-slot:body>
            <form id="form-role" action="{{ route('roles.crear') }}" method="post" novalidate>
                @csrf
                <input type="hidden" id="roles-form-id" value="">

                <div class="field">
                    <label for="roles-name" class="form-label">Rol</label>
                    <input type="text" class="form-control" id="roles-name" name="name"
                           maxlength="255" autocomplete="off">
                    <p class="field-message" id="roles-name-error" role="status" aria-live="polite"></p>
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-role" class="btn btn-primary" id="roles-save">Guardar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-role-view" title="Detalle del rol">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>ID</dt>
                    <dd id="view-role-id"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Rol</dt>
                    <dd id="view-role-name"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Usuarios</dt>
                    <dd id="view-role-users"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Permisos / módulos</dt>
                    <dd id="view-role-permissions"></dd>
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
    <script src="{{ asset('js/roles.js') }}?v={{ filemtime(public_path('js/roles.js')) }}"></script>
@endpush
