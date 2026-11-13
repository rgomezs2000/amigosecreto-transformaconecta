@extends('layouts.app')

@section('title', 'Usuarios asociados · Amigo Secreto Inclusivo')
@section('shell-title', 'Usuarios asociados')
@section('shell-eyebrow', 'Amigo secreto')

@section('page')
    <a class="module-back" href="{{ route('dashboard') }}">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        <span>Volver al panel</span>
    </a>

    <div class="participantes-page">
        @if ($events->isEmpty())
            <section class="matriz-card" aria-label="Usuarios asociados">
                <p class="participantes-empty participantes-empty--page">
                    El módulo de Usuarios asociados se activa cuando se crea un evento.
                    Crea uno en el módulo de <a href="{{ module_url('eventos') }}">Eventos</a>
                    para consultar las parejas.
                </p>
            </section>
        @else
            <section class="matriz-card participantes-toolbar" aria-label="Consulta de usuarios asociados">
                <div class="participantes-field participantes-field--event">
                    <label for="usuarios-asociados-evento" class="form-label">Evento</label>
                    <select id="usuarios-asociados-evento" class="form-select"
                            aria-label="Evento que se va a consultar">
                        @foreach ($events as $item)
                            <option value="{{ $item->id }}" @selected($event?->id === $item->id)>
                                {{ $item->name }} · {{ $item->year }} — {{ $item->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="participantes-field participantes-field--search">
                    <label for="usuarios-asociados-search" class="form-label">Buscar usuario</label>
                    <input type="search" class="form-control" id="usuarios-asociados-search"
                           placeholder="Busca por usuario o amigo secreto..." autocomplete="off">
                </div>

                <div class="participantes-field participantes-field--action">
                    <button type="button" class="btn btn-outline-secondary" id="usuarios-asociados-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </section>

            <div id="usuarios-asociados-aviso" aria-live="polite">
                @include('usuarios-asociados.partials.aviso', ['event' => $event])
            </div>

            <section class="roles-table-card" aria-label="Listado de usuarios asociados">
                <table id="usuarios-asociados-table"
                       class="table table-striped align-middle w-100 roles-table"
                       style="width:100% !important;">
                    <thead>
                        <tr>
                            <th scope="col" class="roles-id">Id</th>
                            <th scope="col" class="roles-name">Nombre</th>
                            <th scope="col" class="roles-name">Usuario</th>
                            <th scope="col" class="roles-name">Amigo secreto</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <th scope="col" class="roles-id">Id</th>
                            <th scope="col" class="roles-name">Nombre</th>
                            <th scope="col" class="roles-name">Usuario</th>
                            <th scope="col" class="roles-name">Amigo secreto</th>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <div id="usuarios-asociados-config"
                 data-url-datos="{{ route('usuarios-asociados.datos') }}"
                 data-evento-actual="{{ $event?->id }}"
                 hidden></div>
        @endif
    </div>
@endsection

@unless ($events->isEmpty())
    @push('head')
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    @endpush

    @push('vendor')
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    @endpush

    @push('scripts')
        <script src="{{ asset('js/usuarios-asociados.js') }}?v={{ filemtime(public_path('js/usuarios-asociados.js')) }}"></script>
    @endpush
@endunless
