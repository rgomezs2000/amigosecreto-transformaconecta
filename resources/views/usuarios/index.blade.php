@extends('layouts.app')

@section('title', 'Usuarios · Amigo Secreto Inclusivo')
@section('shell-title', 'Usuarios')
@section('shell-eyebrow', 'Usuarios')

@section('page')
    @if (session('usuarios_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('usuarios_status') }}</span>
        </div>
    @endif

    <div class="roles-page">
        <section class="matriz-card roles-search-card" aria-label="Búsqueda de usuarios">
            <div class="roles-search">
                <label for="usuarios-search" class="form-label">Buscar usuario</label>
                <input type="search" class="form-control" id="usuarios-search"
                       placeholder="Busca por nombre, correo o usuario..." autocomplete="off">
            </div>

            <div class="matriz-filter-card">
                <div class="matriz-filter">
                    <label for="usuarios-filter-type" class="form-label">Filtrar por</label>
                    <select id="usuarios-filter-type" class="form-select">
                        <option value="all">Todos los usuarios</option>
                        <option value="role">Por rol</option>
                        <option value="status">Por estado</option>
                    </select>
                </div>

                <div class="matriz-filter d-none" id="usuarios-filter-role-wrap">
                    <label for="usuarios-filter-role" class="form-label">Rol</label>
                    <select id="usuarios-filter-role" class="form-select">
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="matriz-filter d-none" id="usuarios-filter-status-wrap">
                    <label for="usuarios-filter-status" class="form-label">Estado</label>
                    <select id="usuarios-filter-status" class="form-select">
                        <option value="active">Activo</option>
                        <option value="blocked">Bloqueado</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <div class="matriz-filter matriz-filter__action">
                    <button type="button" class="btn btn-outline-secondary" id="usuarios-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de usuarios">
            <div class="roles-table-actions">
                <button type="button" class="btn btn-primary" id="usuarios-new">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>Nuevo usuario</span>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="usuarios-delete-selected" disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    <span>Eliminar usuarios</span>
                </button>
            </div>
            <table id="usuarios-table" class="table table-striped align-middle w-100 roles-table" style="width:100% !important;">
                <thead>
                    <tr>
                        <th scope="col" class="roles-check">
                            <input type="checkbox" id="usuarios-check-all" class="form-check-input"
                                   aria-label="Seleccionar todos los usuarios">
                        </th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Nombre</th>
                        <th scope="col" class="roles-name">Correo</th>
                        <th scope="col" class="roles-name">Usuario</th>
                        <th scope="col" class="roles-name">Rol</th>
                        <th scope="col" class="roles-name">Estado</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="roles-check"></th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Nombre</th>
                        <th scope="col" class="roles-name">Correo</th>
                        <th scope="col" class="roles-name">Usuario</th>
                        <th scope="col" class="roles-name">Rol</th>
                        <th scope="col" class="roles-name">Estado</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="usuarios-csrf" value="{{ csrf_token() }}">

    <div id="usuarios-config"
         data-url-datos="{{ route('usuarios.datos') }}"
         data-url-crear="{{ route('usuarios.crear') }}"
         data-url-usuario="{{ url('/usuarios') }}"
         data-url-eliminar-varios="{{ route('usuarios.eliminar-varios') }}"
         data-url-index="{{ route('usuarios.index') }}"
         data-url-login-existe="{{ route('perfil.login-existe') }}"
         hidden></div>

    <x-app-modal id="modal-user-form" title="Nuevo usuario">
        <x-slot:body>
            <form id="form-user" action="{{ route('usuarios.crear') }}" method="post" novalidate>
                @csrf
                <input type="hidden" id="usuarios-form-id" value="">

                <div class="field">
                    <label for="usuarios-name" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="usuarios-name" name="name"
                           maxlength="255" autocomplete="off">
                    <p class="field-message" id="usuarios-name-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="usuarios-email" class="form-label">Correo</label>
                    <input type="email" class="form-control" id="usuarios-email" name="email"
                           maxlength="255" autocomplete="off">
                    <p class="field-message" id="usuarios-email-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="usuarios-username" class="form-label">Usuario</label>
                    <input type="text" class="form-control" id="usuarios-username" name="username"
                           maxlength="255" autocomplete="off">
                    <p class="field-message" id="usuarios-username-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="usuarios-role" class="form-label">Rol</label>
                    <select class="form-select" id="usuarios-role" name="profile_id">
                        <option value="">Selecciona un rol</option>
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-message" id="usuarios-role-error" role="status" aria-live="polite"></p>
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-user" class="btn btn-primary" id="usuarios-save">Guardar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-user-view" title="Detalle del usuario">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>ID</dt>
                    <dd id="view-user-id"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Nombre</dt>
                    <dd id="view-user-name"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Correo</dt>
                    <dd id="view-user-email"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Usuario</dt>
                    <dd id="view-user-username"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Rol</dt>
                    <dd id="view-user-role"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Estado</dt>
                    <dd id="view-user-status"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Creado</dt>
                    <dd id="view-user-created"></dd>
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
    <script src="{{ asset('js/usuarios.js') }}?v={{ filemtime(public_path('js/usuarios.js')) }}"></script>
@endpush
