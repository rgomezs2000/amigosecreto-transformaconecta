@extends('layouts.app')

@section('title', 'Módulos · Amigo Secreto Inclusivo')
@section('shell-title', 'Módulos')
@section('shell-eyebrow', 'Maestros')

@section('page')
    @if (session('modulos_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('modulos_status') }}</span>
        </div>
    @endif

    <div class="roles-page">
        <section class="matriz-card roles-search-card" aria-label="Búsqueda de módulos">
            <div class="roles-search">
                <label for="modulos-search" class="form-label">Buscar módulo</label>
                <input type="search" class="form-control" id="modulos-search"
                       placeholder="Escribe para filtrar..." autocomplete="off">
            </div>

            <div class="matriz-filter-card">
                <div class="matriz-filter">
                    <label for="modulos-filter-type" class="form-label">Filtrar por</label>
                    <select id="modulos-filter-type" class="form-select">
                        <option value="all">Todos los módulos</option>
                        <option value="category">Por categoría</option>
                    </select>
                </div>

                <div class="matriz-filter d-none" id="modulos-filter-category-wrap">
                    <label for="modulos-filter-category" class="form-label">Categoría</label>
                    <select id="modulos-filter-category" class="form-select">
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="matriz-filter matriz-filter__action">
                    <button type="button" class="btn btn-outline-secondary" id="modulos-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de módulos">
            <div class="roles-table-actions">
                <button type="button" class="btn btn-primary" id="modulos-new">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>Nuevo módulo</span>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="modulos-delete-selected" disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    <span>Eliminar módulos</span>
                </button>
            </div>
            <table id="modulos-table" class="table table-striped align-middle w-100 roles-table" style="width:100% !important;">
                <thead>
                    <tr>
                        <th scope="col" class="roles-check">
                            <input type="checkbox" id="modulos-check-all" class="form-check-input"
                                   aria-label="Seleccionar todos los módulos">
                        </th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Nombre</th>
                        <th scope="col" class="roles-name">Categoría</th>
                        <th scope="col" class="roles-icon">Icono</th>
                        <th scope="col" class="roles-id">Slug</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="roles-check"></th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Nombre</th>
                        <th scope="col" class="roles-name">Categoría</th>
                        <th scope="col" class="roles-icon">Icono</th>
                        <th scope="col" class="roles-id">Slug</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="modulos-csrf" value="{{ csrf_token() }}">

    <div id="modulos-config"
         data-url-datos="{{ route('modulos.datos') }}"
         data-url-crear="{{ route('modulos.crear') }}"
         data-url-modulo="{{ url('/modulos') }}"
         data-url-eliminar-varios="{{ route('modulos.eliminar-varios') }}"
         data-url-index="{{ module_url('modulos') }}"
         hidden></div>

    <x-app-modal id="modal-module-form" title="Nuevo módulo" :scrollable="true">
        <x-slot:body>
            <form id="form-module" action="{{ route('modulos.crear') }}" method="post" novalidate>
                @csrf
                <input type="hidden" id="modulos-form-id" value="">

                <div class="field">
                    <label for="modulos-name" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="modulos-name" name="name"
                           maxlength="80" autocomplete="off">
                    <p class="field-message" id="modulos-name-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="modulos-category" class="form-label">Categoría</label>
                    <select class="form-select" id="modulos-category" name="category_id">
                        <option value="">Selecciona una categoría</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" data-slug="{{ $category->slug }}">
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="field-message" id="modulos-category-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="modulos-icon" class="form-label">Icono</label>
                    <div class="icon-field">
                        <span class="icon-field__preview" aria-hidden="true">
                            <i class="fa-solid fa-cube" id="modulos-icon-preview"></i>
                        </span>
                        <input type="text" class="form-control" id="modulos-icon" name="icon"
                               maxlength="60" autocomplete="off" placeholder="ejemplo: fa-house">
                    </div>
                    <p class="form-text">
                        Clase de Font Awesome (estilo solid). Si se deja vacío se usa
                        <code>fa-cube</code>.
                    </p>
                    <div class="icon-palette" role="group" aria-label="Iconos sugeridos">
                        @foreach ($iconSuggestions as $icon)
                            <button type="button" class="icon-palette__item" data-icon="{{ $icon }}"
                                    title="{{ $icon }}" aria-label="Usar el icono {{ $icon }}">
                                <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
                            </button>
                        @endforeach
                    </div>
                    <p class="field-message" id="modulos-icon-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="modulos-slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="modulos-slug" name="slug"
                           maxlength="80" autocomplete="off" placeholder="ejemplo: mi-modulo">
                    <p class="field-message" id="modulos-slug-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label class="form-label">Ruta</label>
                    <p class="module-route" id="modulos-ruta">/</p>
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-module" class="btn btn-primary" id="modulos-save">Guardar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-module-view" title="Detalle del módulo">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>ID</dt>
                    <dd id="view-module-id"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Nombre</dt>
                    <dd id="view-module-name"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Categoría</dt>
                    <dd id="view-module-category"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Icono</dt>
                    <dd class="module-icon-view">
                        <i class="fa-solid fa-cube" id="view-module-icon" aria-hidden="true"></i>
                        <code id="view-module-icon-class"></code>
                    </dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Slug</dt>
                    <dd id="view-module-slug"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Ruta</dt>
                    <dd><a id="view-module-url" href="#"></a></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Permisos</dt>
                    <dd id="view-module-permissions"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Accesos</dt>
                    <dd id="view-module-access"></dd>
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
    <script src="{{ asset('js/modulos.js') }}?v={{ filemtime(public_path('js/modulos.js')) }}"></script>
@endpush
