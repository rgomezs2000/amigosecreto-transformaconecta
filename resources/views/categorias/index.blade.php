@extends('layouts.app')

@section('title', 'Categorías · Amigo Secreto Inclusivo')
@section('shell-title', 'Categorías')
@section('shell-eyebrow', 'Maestros')

@section('page')
    @if (session('categorias_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('categorias_status') }}</span>
        </div>
    @endif

    <div class="roles-page">
        <section class="matriz-card roles-search-card" aria-label="Búsqueda de categorías">
            <div class="roles-search">
                <label for="categorias-search" class="form-label">Buscar categoría</label>
                <input type="search" class="form-control" id="categorias-search"
                       placeholder="Escribe para filtrar..." autocomplete="off">
            </div>

            <div class="roles-actions">
                <button type="button" class="btn btn-outline-secondary" id="categorias-clear">
                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </section>

        <section class="roles-table-card" aria-label="Listado de categorías">
            <div class="roles-table-actions">
                <button type="button" class="btn btn-primary" id="categorias-new">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    <span>Nueva categoría</span>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="categorias-delete-selected" disabled>
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    <span>Eliminar categorías</span>
                </button>
            </div>
            <table id="categorias-table" class="table table-striped align-middle w-100 roles-table">
                <thead>
                    <tr>
                        <th scope="col" class="roles-check">
                            <input type="checkbox" id="categorias-check-all" class="form-check-input"
                                   aria-label="Seleccionar todas las categorías">
                        </th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Categoría</th>
                        <th scope="col" class="roles-name">Slug</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="roles-check"></th>
                        <th scope="col" class="roles-id">Id</th>
                        <th scope="col" class="roles-name">Categoría</th>
                        <th scope="col" class="roles-name">Slug</th>
                        <th scope="col" class="roles-actions">Acciones</th>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>

    <input type="hidden" id="categorias-csrf" value="{{ csrf_token() }}">

    <div id="categorias-config"
         data-url-datos="{{ route('categorias.datos') }}"
         data-url-crear="{{ route('categorias.crear') }}"
         data-url-categoria="{{ url('/categorias') }}"
         data-url-eliminar-varios="{{ route('categorias.eliminar-varios') }}"
         data-url-index="{{ route('categorias.index') }}"
         hidden></div>

    <x-app-modal id="modal-category-form" title="Nueva categoría">
        <x-slot:body>
            <form id="form-category" action="{{ route('categorias.crear') }}" method="post" novalidate>
                @csrf
                <input type="hidden" id="categorias-form-id" value="">

                <div class="field">
                    <label for="categorias-name" class="form-label">Categoría</label>
                    <input type="text" class="form-control" id="categorias-name" name="name"
                           maxlength="60" autocomplete="off">
                    <p class="field-message" id="categorias-name-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="categorias-slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="categorias-slug" name="slug"
                           maxlength="80" autocomplete="off" placeholder="ejemplo: maestros">
                    <p class="field-message" id="categorias-slug-error" role="status" aria-live="polite"></p>
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-category" class="btn btn-primary" id="categorias-save">Guardar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-category-view" title="Detalle de la categoría">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>ID</dt>
                    <dd id="view-category-id"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Categoría</dt>
                    <dd id="view-category-name"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Slug</dt>
                    <dd id="view-category-slug"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Módulos</dt>
                    <dd id="view-category-modules"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Módulos asociados</dt>
                    <dd>
                        <ul id="view-category-modules-list" class="category-modules"></ul>
                    </dd>
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
    <script src="{{ asset('js/categorias.js') }}?v={{ filemtime(public_path('js/categorias.js')) }}"></script>
@endpush
