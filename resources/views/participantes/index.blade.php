@extends('layouts.app')

@section('title', 'Participantes · Amigo Secreto Inclusivo')
@section('shell-title', 'Participantes')
@section('shell-eyebrow', 'Amigo secreto')

@section('page')
    <a class="module-back" href="{{ route('dashboard') }}">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        <span>Volver al panel</span>
    </a>

    <div class="participantes-page">
        @if ($events->isEmpty())
            <section class="matriz-card" aria-label="Participantes">
                <p class="participantes-empty participantes-empty--page">
                    Todavía no hay eventos registrados. Crea un evento en el módulo de
                    <a href="{{ module_url('eventos') }}">Eventos</a> para consultar a sus participantes.
                </p>
            </section>
        @else
            <section class="matriz-card participantes-toolbar" aria-label="Consulta de participantes">
                <div class="participantes-field participantes-field--event">
                    <label for="participantes-evento" class="form-label">Evento</label>
                    <select id="participantes-evento" class="form-select"
                            aria-label="Evento que se va a consultar">
                        @foreach ($events as $item)
                            <option value="{{ $item->id }}" @selected($event?->id === $item->id)>
                                {{ $item->name }} · {{ $item->year }} — {{ $item->status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="participantes-field participantes-field--search">
                    <label for="participantes-search" class="form-label">Buscar participante</label>
                    <input type="search" class="form-control" id="participantes-search"
                           placeholder="Busca por nombre, correo o usuario..." autocomplete="off">
                </div>

                <div class="participantes-field participantes-field--action">
                    <button type="button" class="btn btn-outline-secondary" id="participantes-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </section>

            <div id="participantes-estado">
                @include('participantes.partials.estado', ['estado' => $estado])
            </div>

            <section class="roles-table-card" aria-label="Listado de participantes">
                <table id="participantes-table"
                       class="table table-striped align-middle w-100 roles-table"
                       style="width:100% !important;">
                    <thead>
                        <tr>
                            <th scope="col" class="roles-id">Id</th>
                            <th scope="col" class="roles-name">Nombre</th>
                            <th scope="col" class="roles-name">Correo</th>
                            <th scope="col" class="roles-name">Usuario</th>
                            <th scope="col" class="roles-name">Estado</th>
                            <th scope="col" class="roles-name">Amigo secreto</th>
                            <th scope="col" class="roles-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <th scope="col" class="roles-id">Id</th>
                            <th scope="col" class="roles-name">Nombre</th>
                            <th scope="col" class="roles-name">Correo</th>
                            <th scope="col" class="roles-name">Usuario</th>
                            <th scope="col" class="roles-name">Estado</th>
                            <th scope="col" class="roles-name">Amigo secreto</th>
                            <th scope="col" class="roles-actions">Acciones</th>
                        </tr>
                    </tfoot>
                </table>
            </section>
        @endif
    </div>

    <div id="participantes-config"
         data-url-datos="{{ route('participantes.datos') }}"
         data-url-ficha="{{ route('participantes.ficha', ['participant' => '__ID__']) }}"
         data-url-lista-deseos="{{ route('participantes.lista-deseos', ['participant' => '__ID__']) }}"
         data-url-pistas="{{ route('participantes.pistas', ['participant' => '__ID__']) }}"
         data-url-obsequios="{{ route('participantes.obsequios', ['participant' => '__ID__']) }}"
         data-url-consumibles="{{ route('participantes.consumibles', ['participant' => '__ID__']) }}"
         data-evento-actual="{{ $event?->id }}"
         hidden></div>

    @unless ($events->isEmpty())
        @include('participantes.partials.modal-ficha')
        @include('participantes.partials.modal-lista-deseos')
        @include('participantes.partials.modal-pistas')
        @include('participantes.partials.modal-obsequios')
    @endunless
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
@endpush

@push('vendor')
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
@endpush

@push('scripts')
    <script src="{{ asset('js/participantes.js') }}?v={{ filemtime(public_path('js/participantes.js')) }}"></script>
@endpush
