@extends('layouts.app')

@section('title', 'Matriz de perfiles · Amigo Secreto Inclusivo')
@section('shell-title', 'Matriz de perfiles')
@section('shell-eyebrow', 'Maestros')

@section('page')
    @if (session('matriz_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('matriz_status') }}</span>
        </div>
    @endif

    <div class="matriz-page">
        <section class="matriz-card matriz-search-card" aria-label="Buscar y filtrar la matriz">
            <label for="matriz-search" class="form-label">Buscar en la matriz</label>
            <input type="search" class="form-control" id="matriz-search"
                   placeholder="Escribe una letra para buscar en la tabla..." autocomplete="off">

            <div class="matriz-filter-card">
                <div class="matriz-filter">
                    <label for="matriz-filter-type" class="form-label">Filtrar por</label>
                    <select id="matriz-filter-type" class="form-select">
                        <option value="all">Todos los módulos</option>
                        <option value="assigned">Asignados al rol</option>
                        <option value="permission">Permiso</option>
                    </select>
                </div>

                <div class="matriz-filter d-none" id="matriz-filter-permission-wrap">
                    <label for="matriz-filter-permission" class="form-label">Permiso</label>
                    <select id="matriz-filter-permission" class="form-select"></select>
                </div>

                <div class="matriz-filter matriz-filter__action">
                    <button type="button" class="btn btn-outline-secondary" id="matriz-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="matriz-card matriz-role-card" aria-label="Rol y aplicación">
            <label for="matriz-role" class="form-label">Rol</label>
            <select id="matriz-role" class="form-select">
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected($initialRole?->id === $role->id)>
                        {{ $role->name }}
                    </option>
                @endforeach
            </select>

            <div class="matriz-role-actions">
                <button type="button" class="btn btn-primary" id="matriz-apply" @disabled(! $canGrant || ! $canRevoke)>
                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                    <span>Aceptar</span>
                </button>
            </div>

            <p id="matriz-status" class="form-status" role="status" aria-live="polite"></p>
        </section>

        <p class="matriz-help">
            Asocia o desasocia módulos y marca los permisos. Al terminar, pulsa Aceptar
            para aplicar todos los cambios al rol.
        </p>

        <section class="matriz-card" aria-label="Matriz de roles, módulos y permisos">
            <table id="matriz-table" class="table matriz-table align-middle w-100">
                <thead>
                    <tr>
                        <th scope="col" class="matriz-col-group">Grupo</th>
                        <th scope="col">Módulo</th>
                        <th scope="col" class="matriz-col-associated">Asociado</th>
                        <th scope="col">Permisos</th>
                        <th scope="col" class="matriz-col-search">Búsqueda</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="matriz-col-group">Grupo</th>
                        <th scope="col">Módulo</th>
                        <th scope="col" class="matriz-col-associated">Asociado</th>
                        <th scope="col">Permisos</th>
                        <th scope="col" class="matriz-col-search">Búsqueda</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="matriz-csrf" value="{{ csrf_token() }}">

    <div id="matriz-config"
         data-url-datos="{{ route('matriz.datos') }}"
         data-url-aplicar="{{ route('matriz.aplicar') }}"
         data-url-index="{{ module_url('matriz') }}"
         data-can-grant="{{ $canGrant ? '1' : '0' }}"
         data-can-revoke="{{ $canRevoke ? '1' : '0' }}"
         hidden></div>
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
@endpush

@push('vendor')
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>
@endpush

@push('scripts')
    <script src="{{ asset('js/matriz.js') }}?v={{ filemtime(public_path('js/matriz.js')) }}"></script>
@endpush
