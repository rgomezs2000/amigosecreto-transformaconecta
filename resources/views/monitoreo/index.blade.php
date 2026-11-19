@extends('layouts.app')

@section('title', $titulo.' · Amigo Secreto Inclusivo')
@section('shell-title', $titulo)
@section('shell-eyebrow', 'Amigo secreto')

@section('page')
    <div class="participantes-page">
        @if ($events->isEmpty())
            <section class="matriz-card" aria-label="{{ $titulo }}">
                <p class="participantes-empty participantes-empty--page">
                    Todavía no hay eventos registrados. Crea un evento en el módulo de
                    <a href="{{ module_url('eventos') }}">Eventos</a> para usar {{ $titulo }}.
                </p>
            </section>
        @else
            <div id="monitoreo-estado-evento">
                @include('participantes.partials.estado', ['estado' => $estado])
            </div>

            <p class="participantes-ayuda">{{ $descripcion }}</p>

            <section class="matriz-card {{ $habilitado ? 'd-none' : '' }}" id="monitoreo-bloqueo" aria-live="polite">
                <p class="participantes-empty participantes-empty--page">
                    <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                    <span>{{ $aviso !== '' ? $aviso : 'Este módulo se activa en la fase correspondiente del evento.' }}</span>
                </p>
            </section>

            <section class="matriz-card participantes-toolbar" aria-label="Consulta de {{ $titulo }}">
                    <div class="participantes-field participantes-field--event">
                        <label for="monitoreo-evento" class="form-label">Evento</label>
                        <select id="monitoreo-evento" class="form-select" aria-label="Evento que se va a consultar">
                            @foreach ($events as $item)
                                <option value="{{ $item->id }}" @selected($event?->id === $item->id)>
                                    {{ $item->name }} · {{ $item->year }} — {{ $item->status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="participantes-field participantes-field--search">
                        <label for="monitoreo-search" class="form-label">Buscar usuario</label>
                        <input type="search" class="form-control" id="monitoreo-search"
                               placeholder="Busca por nombre, correo o usuario..." autocomplete="off">
                    </div>

                    <div class="participantes-field">
                        <label for="monitoreo-estado" class="form-label">Estatus de participación</label>
                        <select id="monitoreo-estado" class="form-select" aria-label="Filtrar por estatus de participación">
                            <option value="">Todos</option>
                            @foreach (\App\Enums\ParticipantStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="participantes-field participantes-field--action">
                        <button type="button" class="btn btn-outline-secondary" id="monitoreo-clear">
                            <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                            <span>Limpiar</span>
                        </button>
                    </div>
                </section>

            <div id="monitoreo-contenido" class="{{ $habilitado ? '' : 'd-none' }}">
                <section class="roles-table-card" aria-label="Listado de usuarios">
                    <table id="monitoreo-table"
                           class="table table-striped align-middle w-100 roles-table"
                           style="width:100% !important;">
                        <thead>
                            <tr>
                                <th scope="col" class="roles-id">Id</th>
                                <th scope="col" class="roles-name">Nombre</th>
                                <th scope="col" class="roles-name">Usuario</th>
                                <th scope="col" class="roles-name">Estatus de participación</th>
                                <th scope="col" class="roles-actions">Acción</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr>
                                <th scope="col" class="roles-id">Id</th>
                                <th scope="col" class="roles-name">Nombre</th>
                                <th scope="col" class="roles-name">Usuario</th>
                                <th scope="col" class="roles-name">Estatus de participación</th>
                                <th scope="col" class="roles-actions">Acción</th>
                            </tr>
                        </tfoot>
                    </table>
                </section>
            </div>
        @endif
    </div>

    @unless ($events->isEmpty())
        <div id="monitoreo-config"
             data-tipo="{{ $tipo }}"
             data-url-datos="{{ route('monitoreo.datos', ['tipo' => $tipo]) }}"
             data-url-ficha="{{ route('participantes.ficha', ['participant' => '__ID__']) }}"
             data-url-deseos="{{ route('participantes.lista-deseos', ['participant' => '__ID__']) }}"
             data-url-pistas="{{ route('participantes.pistas', ['participant' => '__ID__']) }}"
             data-url-obsequios="{{ route('participantes.obsequios', ['participant' => '__ID__']) }}"
             data-url-consumibles="{{ route('participantes.consumibles', ['participant' => '__ID__']) }}"
             data-evento-actual="{{ $event?->id }}"
             data-etiqueta-accion="{{ $etiquetaAccion }}"
             hidden></div>

        @include('monitoreo.partials.modal')
    @endunless
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
        <script src="{{ asset('js/monitoreo.js') }}?v={{ filemtime(public_path('js/monitoreo.js')) }}"></script>
    @endpush
@endunless
