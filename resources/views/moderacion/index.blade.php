@extends('layouts.app')

@section('title', 'Moderación · Amigo Secreto Inclusivo')
@section('shell-title', 'Moderación')
@section('shell-eyebrow', 'Administración')

@section('page')
    <div class="roles-page moderacion-page">
        <div class="moderacion-tabs" role="tablist" aria-label="Secciones de Moderación">
            <button type="button" class="moderacion-tab is-active" id="moderacion-tab-reportes"
                    role="tab" aria-selected="true" aria-controls="moderacion-panel-reportes"
                    data-tab="reportes">
                <i class="fa-solid fa-flag" aria-hidden="true"></i>
                <span>Reportes</span>
            </button>
            <button type="button" class="moderacion-tab" id="moderacion-tab-bloqueos"
                    role="tab" aria-selected="false" aria-controls="moderacion-panel-bloqueos"
                    data-tab="bloqueos">
                <i class="fa-solid fa-user-lock" aria-hidden="true"></i>
                <span>Bloqueos entre usuarios</span>
            </button>
            <button type="button" class="moderacion-tab" id="moderacion-tab-vetados"
                    role="tab" aria-selected="false" aria-controls="moderacion-panel-vetados"
                    data-tab="vetados">
                <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                <span>Vetados de grupo</span>
            </button>
        </div>

        <section id="moderacion-panel-reportes" role="tabpanel" aria-labelledby="moderacion-tab-reportes"
                 data-panel="reportes" class="moderacion-panel">
            <section class="matriz-card moderacion-summary" aria-label="Resumen de reportes">
                <div>
                    <p class="dashboard-eyebrow">Reportes del chat</p>
                    <p class="moderacion-summary__total" data-moderation-count="reports">{{ format_count($total) }}</p>
                    <div class="moderacion-summary__counts" aria-label="Reportes por estado">
                        <span class="moderacion-summary__count">
                            <strong data-moderation-count="pending">{{ format_count($pendientes) }}</strong>
                            Por atender
                        </span>
                        <span class="moderacion-summary__count">
                            <strong data-moderation-count="attending">{{ format_count($atendiendo) }}</strong>
                            Atendiendo
                        </span>
                        <span class="moderacion-summary__count">
                            <strong data-moderation-count="closed">{{ format_count($cerrados) }}</strong>
                            Cerrados
                        </span>
                    </div>
                    <p class="notification-row__hint">
                        Los reportes pasan por «Por atender», «Atendiendo» y «Cerrado». Abre el detalle
                        para tomar el caso; solo quien lo toma puede cerrarlo, con observación y confirmación.
                    </p>
                </div>
            </section>

            <section class="matriz-card roles-search-card" aria-label="Búsqueda de reportes">
                <div class="roles-search">
                    <label for="moderacion-search" class="form-label">Buscar reporte</label>
                    <input type="search" class="form-control" id="moderacion-search"
                           placeholder="Motivo, persona o mensaje..." autocomplete="off">
                </div>

                <div class="moderacion-filter" id="moderacion-report-filter">
                    <label for="moderacion-filter-type" class="form-label">Filtrar por</label>
                    <select class="form-select" id="moderacion-filter-type">
                        <option value="all">Todos los reportes</option>
                        <option value="date">Por fecha</option>
                        <option value="status">Estado</option>
                        <option value="manager">Gestionado por</option>
                        <option value="referred">Remitido a</option>
                    </select>

                    <div class="moderacion-filter__panel" id="moderacion-filter-date" hidden>
                        <label class="moderacion-filter__field">
                            <span>Desde</span>
                            <input type="datetime-local" class="form-control" id="moderacion-filter-from">
                        </label>
                        <label class="moderacion-filter__field">
                            <span>Hasta</span>
                            <input type="datetime-local" class="form-control" id="moderacion-filter-to">
                        </label>
                    </div>

                    <div class="moderacion-filter__panel" id="moderacion-filter-status-panel" hidden>
                        <label class="moderacion-filter__field">
                            <span>Estado</span>
                            <select class="form-select" id="moderacion-filter-status">
                                <option value="pendiente">Por atender</option>
                                <option value="atendiendo">Atendiendo</option>
                                <option value="cerrado">Cerrado</option>
                            </select>
                        </label>
                    </div>

                    <div class="moderacion-filter__panel" id="moderacion-filter-manager-panel" hidden>
                        <label class="moderacion-filter__field">
                            <span>Gestionado por</span>
                            <select class="form-select" id="moderacion-filter-manager">
                                <option value="">Todos los administradores</option>
                                @foreach ($admins as $admin)
                                    <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="moderacion-filter__panel" id="moderacion-filter-referred-panel" hidden>
                        <label class="moderacion-filter__field">
                            <span>Remitido a</span>
                            <select class="form-select" id="moderacion-filter-referred">
                                <option value="">Todos los administradores</option>
                                @foreach ($admins as $admin)
                                    <option value="{{ $admin->id }}">{{ $admin->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>

                <div class="roles-actions">
                    <button type="button" class="btn btn-outline-secondary" id="moderacion-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </section>

            <section class="roles-table-card" aria-label="Listado de reportes">
                <table id="moderacion-table" class="table table-striped align-middle w-100 roles-table">
                    <thead>
                        <tr>
                            <th scope="col" class="roles-id">Nº Ticket</th>
                            <th scope="col" class="roles-id">Fecha</th>
                            <th scope="col" class="roles-name">Estado</th>
                            <th scope="col" class="roles-name">Gestionado por</th>
                            <th scope="col" class="roles-name">Remitido a</th>
                            <th scope="col" class="roles-name">Reportado por</th>
                            <th scope="col" class="roles-name">Autor del mensaje</th>
                            <th scope="col" class="roles-name">Motivo</th>
                            <th scope="col" class="roles-name">Conversación</th>
                            <th scope="col" class="roles-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </section>
        </section>

        <section id="moderacion-panel-bloqueos" role="tabpanel" aria-labelledby="moderacion-tab-bloqueos"
                 data-panel="bloqueos" class="moderacion-panel" hidden>
            <section class="matriz-card moderacion-summary" aria-label="Resumen de bloqueos">
                <div>
                    <p class="dashboard-eyebrow">Bloqueos entre usuarios</p>
                    <p class="moderacion-summary__total" data-moderation-count="blocks">{{ format_count($usuarios) }}</p>
                    <p class="notification-row__hint">
                        Total de bloqueos activos entre usuarios. Con el botón de bloqueos de cada
                        persona eliges a quién tiene bloqueado: al pasar a «Bloqueados» se crea el
                        bloqueo y al volver a «Desbloqueados» se elimina.
                    </p>
                </div>
            </section>

            <section class="matriz-card roles-search-card" aria-label="Búsqueda de usuarios">
                <div class="roles-search">
                    <label for="moderacion-usuarios-search" class="form-label">Buscar usuario</label>
                    <input type="search" class="form-control" id="moderacion-usuarios-search"
                           placeholder="Nombre, usuario o correo..." autocomplete="off">
                </div>

                <div class="moderacion-filter" id="moderacion-usuarios-filter">
                    <label for="moderacion-usuarios-filter-type" class="form-label">Filtrar por</label>
                    <select class="form-select" id="moderacion-usuarios-filter-type">
                        <option value="all">Todos los usuarios</option>
                        <option value="role">Por rol</option>
                    </select>

                    <div class="moderacion-filter__panel" id="moderacion-usuarios-filter-role" hidden>
                        <label class="moderacion-filter__field">
                            <span>Rol</span>
                            <select class="form-select" id="moderacion-usuarios-role">
                                <option value="admin">Administrador</option>
                                <option value="participant">Participante</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="roles-actions">
                    <button type="button" class="btn btn-outline-secondary" id="moderacion-usuarios-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </section>

            <section class="roles-table-card" aria-label="Listado de usuarios con sus bloqueos">
                <table id="moderacion-usuarios-table" class="table table-striped align-middle w-100 roles-table">
                    <thead>
                        <tr>
                            <th scope="col" class="roles-name">Usuario</th>
                            <th scope="col" class="roles-name">Inicio de sesión</th>
                            <th scope="col" class="roles-name">Rol</th>
                            <th scope="col" class="roles-id">Bloqueados</th>
                            <th scope="col" class="roles-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </section>
        </section>

        <section id="moderacion-panel-vetados" role="tabpanel" aria-labelledby="moderacion-tab-vetados"
                 data-panel="vetados" class="moderacion-panel" hidden>
            <section class="matriz-card moderacion-summary" aria-label="Resumen de grupos">
                <div>
                    <p class="dashboard-eyebrow">Vetados de grupo</p>
                    <p class="moderacion-summary__total" data-moderation-count="bans">{{ format_count($grupos) }}</p>
                    <p class="notification-row__hint">
                        Total de vetos activos en grupos. Con el botón de vetados de cada grupo eliges
                        quién está vetado: al pasar a «Vetados» se aplica el veto y, si la persona está
                        dentro del grupo, se le saca. Al volver a «Disponibles» se retira el veto.
                    </p>
                </div>
            </section>

            <section class="matriz-card roles-search-card" aria-label="Búsqueda de grupos">
                <div class="roles-search">
                    <label for="moderacion-grupos-search" class="form-label">Buscar grupo</label>
                    <input type="search" class="form-control" id="moderacion-grupos-search"
                           placeholder="Nombre del grupo..." autocomplete="off">
                </div>

                <div class="roles-actions">
                    <button type="button" class="btn btn-outline-secondary" id="moderacion-grupos-clear">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </section>

            <section class="roles-table-card" aria-label="Listado de grupos con sus vetados">
                <table id="moderacion-grupos-table" class="table table-striped align-middle w-100 roles-table">
                    <thead>
                        <tr>
                            <th scope="col" class="roles-name">Grupo</th>
                            <th scope="col" class="roles-name">Dueño</th>
                            <th scope="col" class="roles-id">Miembros</th>
                            <th scope="col" class="roles-id">Vetados</th>
                            <th scope="col" class="roles-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </section>
        </section>
    </div>

    <div id="moderacion-config"
         data-url-datos="{{ route('moderacion.datos') }}"
         data-url-usuarios="{{ route('moderacion.bloqueos.usuarios') }}"
         data-url-grupos="{{ route('moderacion.grupos') }}"
         data-url-contadores="{{ route('moderacion.contadores') }}"
         data-url-tomar="{{ route('moderacion.reportes.tomar', ['report' => '__ID__']) }}"
         data-url-cerrar="{{ route('moderacion.reportes.cerrar', ['report' => '__ID__']) }}"
         data-url-remitir="{{ route('moderacion.reportes.remitir', ['report' => '__ID__']) }}"
         data-url-admins="{{ route('moderacion.administradores') }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>

    <x-app-modal id="modal-moderacion-detail" title="Detalle del reporte">
        <x-slot:body>
            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>Ticket Nº:</dt>
                    <dd id="moderacion-detail-ticket"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Fecha</dt>
                    <dd id="moderacion-detail-date"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Estado</dt>
                    <dd id="moderacion-detail-status"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Asignado a</dt>
                    <dd id="moderacion-detail-assigned"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Gestionado por</dt>
                    <dd id="moderacion-detail-manager"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Remitido a</dt>
                    <dd id="moderacion-detail-referred"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Tomado el</dt>
                    <dd id="moderacion-detail-taken"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Observación al tomar</dt>
                    <dd id="moderacion-detail-attention"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Reportado por</dt>
                    <dd id="moderacion-detail-reporter"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Autor del mensaje</dt>
                    <dd id="moderacion-detail-author"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Conversación</dt>
                    <dd id="moderacion-detail-conversation"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Motivo</dt>
                    <dd id="moderacion-detail-reason"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Mensaje reportado</dt>
                    <dd id="moderacion-detail-message"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Cerrado el</dt>
                    <dd id="moderacion-detail-closed-at"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Cerrado por</dt>
                    <dd id="moderacion-detail-closed-by"></dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Observación de cierre</dt>
                    <dd id="moderacion-detail-close-observation"></dd>
                </div>
                <div class="perfil-data__item perfil-data__item--wide">
                    <dt>Remisiones</dt>
                    <dd>
                        <div class="moderacion-referrals" id="moderacion-detail-referrals"></div>
                    </dd>
                </div>
            </dl>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-primary" id="moderacion-detail-take" hidden>
                <i class="fa-solid fa-hand" aria-hidden="true"></i>
                <span>Tomar caso</span>
            </button>
            <button type="button" class="btn btn-success" id="moderacion-detail-close" hidden>
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Cerrar caso</span>
            </button>
            {{-- Remitir el caso a otro administrador (solo el gestor actual). --}}
            <button type="button" class="btn btn-outline-primary" id="moderacion-detail-refer" hidden>
                <i class="fa-solid fa-share" aria-hidden="true"></i>
                <span>Remitir caso</span>
            </button>
            <a class="btn btn-primary" id="moderacion-detail-chat" href="#" hidden>
                <i class="fa-solid fa-comments" aria-hidden="true"></i>
                <span>Ir al chat</span>
            </a>
            {{-- Chat del ticket: se abre al tomar el caso y sigue disponible,
                 en solo lectura, después de cerrarlo. --}}
            <a class="btn btn-outline-secondary" id="moderacion-detail-ticket-chat" href="#" hidden>
                <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                <span>Chat del ticket</span>
            </a>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-moderacion-refer" title="Remitir caso">
        <x-slot:body>
            <p class="chat-hint">
                El caso pasa al administrador que elijas. Dejarás de gestionarlo y
                de tener acceso a su chat; podrás volver a gestionarlo solo si el
                nuevo gestor te lo remite de vuelta.
            </p>

            <label class="form-label" for="moderacion-refer-search">Administrador</label>
            <div class="moderacion-combobox" id="moderacion-refer-combobox">
                <input type="text" class="form-control" id="moderacion-refer-search"
                       placeholder="Buscar administrador..." autocomplete="off"
                       role="combobox" aria-expanded="false" aria-controls="moderacion-refer-list"
                       aria-autocomplete="list">
                <input type="hidden" id="moderacion-refer-admin-id">
                <div class="moderacion-combobox__list" id="moderacion-refer-list" role="listbox" hidden></div>
            </div>
            <p class="field-message" id="moderacion-refer-selected" role="status"></p>

            <label class="form-label" for="moderacion-refer-observation">Observación (motivo de la remisión)</label>
            <textarea class="form-control" id="moderacion-refer-observation" rows="3" maxlength="1000"></textarea>
            <p class="field-message" id="moderacion-refer-error" role="status"></p>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-primary" id="moderacion-refer-confirm">Remitir caso</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-moderacion-take" title="Tomar caso">
        <x-slot:body>
            <p class="chat-hint">
                Al tomar el caso queda asociado a tu usuario y pasa a «Atendiendo».
                Solo tú podrás cerrarlo.
            </p>
            <label class="form-label" for="moderacion-take-observation">Observación al tomar (opcional)</label>
            <textarea class="form-control" id="moderacion-take-observation" rows="3" maxlength="1000"></textarea>
            <p class="field-message" id="moderacion-take-error" role="status"></p>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-primary" id="moderacion-take-confirm">Tomar caso</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-moderacion-close" title="Cerrar caso">
        <x-slot:body>
            <p class="chat-hint">
                Solo el administrador que tomó el caso puede cerrarlo. La observación
                de cierre es obligatoria.
            </p>
            <label class="form-label" for="moderacion-close-observation">Observación de cierre</label>
            <textarea class="form-control" id="moderacion-close-observation" rows="4" maxlength="2000"
                      required></textarea>
            <p class="field-message" id="moderacion-close-error" role="status"></p>

            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="moderacion-close-confirmed">
                <label class="form-check-label" for="moderacion-close-confirmed">
                    Confirmo que el reporte quedó cerrado.
                </label>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-success" id="moderacion-close-confirm">Sí, cerrar caso</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No cerrar</button>
        </x-slot:footer>
    </x-app-modal>

    @include('moderacion.partials.transfer-modal', [
        'modalId' => 'modal-moderacion-bloqueos',
        'modalTitle' => 'Gestionar bloqueos',
        'kind' => 'bloqueos',
        'subjectPrefix' => 'Bloqueos de',
        'sideLabels' => ['Desbloqueados', 'Bloqueados'],
        'sideHelp' => [
            'Personas que este usuario no tiene bloqueadas.',
            'Personas bloqueadas: no pueden escribirle.',
        ],
        'moveLabels' => [
            'Bloquear a todas las personas',
            'Bloquear a la persona seleccionada',
            'Desbloquear a la persona seleccionada',
            'Desbloquear a todas las personas',
        ],
        'urlDetalle' => route('moderacion.bloqueos.detalle', ['user' => '__ID__']),
        'urlSincronizar' => route('moderacion.bloqueos.sincronizar', ['user' => '__ID__']),
        'showMemberHint' => false,
    ])

    @include('moderacion.partials.transfer-modal', [
        'modalId' => 'modal-moderacion-baneados',
        'modalTitle' => 'Ver baneados',
        'kind' => 'vetados',
        'subjectPrefix' => 'Vetados de',
        'sideLabels' => ['Disponibles', 'Vetados'],
        'sideHelp' => [
            'Personas que pueden entrar al grupo.',
            'Personas vetadas: no pueden entrar y salen si estaban dentro.',
        ],
        'moveLabels' => [
            'Vetar a todas las personas',
            'Vetar a la persona seleccionada',
            'Quitar el veto a la persona seleccionada',
            'Quitar el veto a todas las personas',
        ],
        'urlDetalle' => route('moderacion.grupos.vetados', ['conversation' => '__ID__']),
        'urlSincronizar' => route('moderacion.grupos.vetados.sincronizar', ['conversation' => '__ID__']),
        'showMemberHint' => true,
    ])
@endsection

@push('head')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
@endpush

@push('vendor')
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
@endpush

@push('scripts')
    <script src="{{ asset('js/moderacion.js') }}?v={{ filemtime(public_path('js/moderacion.js')) }}"></script>
@endpush
