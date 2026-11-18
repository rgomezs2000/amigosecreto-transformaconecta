@extends('layouts.app')

@section('title', 'Lista de deseos · Amigo Secreto Inclusivo')
@section('shell-title', 'Lista de deseos')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    @php($activo = $participacion?->status->isParticipating() ?? false)

    <section class="lista-deseos-page" aria-label="Lista de deseos">
        @if ($event === null)
            <article class="sorteo-card">
                <p class="sorteo-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <span>Todavía no hay un evento activo.</span>
                </p>
            </article>
        @else
            <article class="sorteo-card amigo-resumen">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">{{ $event->name }}</h2>
                        <p class="sorteo-card__text">
                            Fase {{ \App\Support\FaseEvento::paso($event) }} ·
                            {{ \App\Support\FaseEvento::etiquetaDe($event) }}
                            · Intercambio: {{ $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha' }}
                        </p>
                    </div>

                    <span class="status-badge {{ $participacion?->status->badgeClass() ?? 'status-badge--inactive' }}">
                        {{ $participacion?->status->label() ?? 'Sin invitación' }}
                    </span>
                </header>

                @unless ($activo)
                    <p class="sorteo-note">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <span>La lista de deseos se activa cuando aceptas la invitación al evento.</span>
                    </p>

                    <div class="sorteo-actions">
                        <a class="btn btn-primary" href="{{ module_url('ver-tu-amigo') }}">
                            <i class="fa-solid fa-handshake" aria-hidden="true"></i>
                            <span>Ir a mi invitación</span>
                        </a>
                    </div>
                @else
                    @unless ($editable)
                        <p class="sorteo-note">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            <span>El sorteo ya se realizó: tu lista de deseos quedó en solo lectura.</span>
                        </p>
                    @endunless

                    <div class="lista-deseos-toolbar">
                        <div class="lista-deseos-toolbar__search">
                            <label for="deseos-search" class="form-label">Buscar deseo</label>
                            <input type="search" class="form-control" id="deseos-search"
                                   placeholder="Escribe la primera letra…" autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="deseos-clear">
                                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                                <span>Limpiar</span>
                            </button>
                        </div>

                        <div class="lista-deseos-toolbar__actions">
                            @if ($editable)
                                <button type="button" class="btn btn-primary" id="deseo-nuevo">
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    <span>Agregar un deseo</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    <p class="amigo-form-status" id="deseos-status" role="status" aria-live="polite"></p>

                    <div class="table-responsive">
                        <table id="deseos-table"
                               class="table table-striped align-middle w-100 roles-table"
                               style="width:100% !important;">
                            <thead>
                                <tr>
                                    <th scope="col" class="roles-id">Id</th>
                                    <th scope="col" class="roles-name">Deseo</th>
                                    <th scope="col" class="roles-name">Descripción</th>
                                    <th scope="col" class="roles-name">Registrado</th>
                                    <th scope="col" class="roles-actions">Acciones</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr>
                                    <th scope="col" class="roles-id">Id</th>
                                    <th scope="col" class="roles-name">Deseo</th>
                                    <th scope="col" class="roles-name">Descripción</th>
                                    <th scope="col" class="roles-name">Registrado</th>
                                    <th scope="col" class="roles-actions">Acciones</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endunless
            </article>
        @endif
    </section>

    <div id="deseos-config"
         data-url-datos="{{ route('lista-deseos.datos') }}"
         data-url-crear="{{ route('lista-deseos.crear') }}"
         data-url-item="{{ route('lista-deseos.ver', ['item' => '__ID__']) }}"
         data-editable="{{ $editable ? '1' : '0' }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>

    <x-app-modal id="modal-deseo" title="Agregar un deseo">
        <x-slot:body>
            <form id="form-deseo" novalidate>
                <input type="hidden" id="deseo-id" value="">

                <div class="field">
                    <label for="deseo-title" class="form-label">Deseo</label>
                    <input type="text" class="form-control" id="deseo-title" maxlength="150"
                           autocomplete="off" placeholder="Ejemplo: un libro de aventuras">
                    <p class="field-message" id="deseo-title-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="deseo-description" class="form-label">Descripción (opcional)</label>
                    <textarea class="form-control" id="deseo-description" rows="3" maxlength="500"
                              placeholder="Detalles, talla, color, marca…"></textarea>
                    <p class="field-message" id="deseo-description-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="deseo-url" class="form-label">Enlace (opcional)</label>
                    <input type="url" class="form-control" id="deseo-url" maxlength="255"
                           autocomplete="off" placeholder="https://…">
                    <p class="field-message" id="deseo-url-error" role="status" aria-live="polite"></p>
                </div>

                <p class="field-message" id="deseo-form-error" role="status" aria-live="polite"></p>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" id="deseo-limpiar">
                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                <span>Limpiar</span>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-primary" id="deseo-guardar">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <span>Guardar</span>
            </button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-deseo-ver" title="Deseo">
        <x-slot:body>
            <div id="deseo-ver-body"></div>
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
    <script src="{{ asset('js/lista-deseos.js') }}?v={{ filemtime(public_path('js/lista-deseos.js')) }}"></script>
@endpush
