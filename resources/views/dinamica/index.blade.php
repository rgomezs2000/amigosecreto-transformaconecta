@extends('layouts.app')

@section('title', 'Restablecer dinámica · Amigo Secreto Inclusivo')
@section('shell-title', 'Restablecer dinámica')
@section('shell-eyebrow', 'Administración')

@section('page')
    @php
        $lastStatus = $latest['status'] ?? null;
        $lastDate = $latest['finished_at'] ?? $latest['started_at'] ?? null;
        $lastLabel = match ($lastStatus) {
            'done' => 'Completado',
            'failed' => 'Fallido',
            'running' => 'En curso',
            'cancelled' => 'Cancelado',
            default => null,
        };
    @endphp

    <section class="dinamica-page" aria-label="Restablecer la dinámica del amigo secreto">
        <article class="dinamica-card">
            <header class="dinamica-card__head">
                <span class="dinamica-card__icon" aria-hidden="true">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </span>

                <div>
                    <h2 class="dinamica-card__title">Restablecer Amigo Secreto</h2>
                    <p class="dinamica-card__text">
                        Deja la dinámica en cero para comenzar de nuevo. Es un proceso distinto al
                        «Reiniciar» de Eventos: aquí se borra todo lo ocurrido y solo queda la base del sistema.
                    </p>
                </div>
            </header>

            <div class="dinamica-columns">
                <div class="dinamica-list dinamica-list--danger">
                    <p class="dinamica-list__title">
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                        <span>Se elimina</span>
                    </p>
                    <ul class="dinamica-list__items">
                        <li>Eventos y participaciones.</li>
                        <li>Listas de deseos, pistas, asignaciones y regalos.</li>
                        <li>Conversaciones, mensajes y adjuntos del chat.</li>
                        <li>Moderación, reportes, bloqueos e invitaciones de grupo.</li>
                        <li>Notificaciones, auditoría y correos registrados.</li>
                        <li>Las cuentas de todos los usuarios, menos el superadministrador.</li>
                    </ul>
                </div>

                <div class="dinamica-list">
                    <p class="dinamica-list__title">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        <span>Se conserva</span>
                    </p>
                    <ul class="dinamica-list__items">
                        <li>Los CRUD: roles, permisos, categorías y módulos.</li>
                        <li>La matriz de accesos de cada perfil.</li>
                        <li>La configuración de notificaciones.</li>
                        <li>La cuenta del superadministrador, que es intocable.</li>
                    </ul>
                </div>
            </div>

            <p class="dinamica-note">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span>
                    Esta acción no se puede deshacer. El detalle del proceso queda en
                    <code>{{ $logFile }}</code>.
                </span>
            </p>

            @if ($lastLabel !== null)
                <p class="dinamica-last">
                    Última ejecución: <strong>{{ $lastLabel }}</strong>
                    @if ($lastDate)
                        · {{ \Illuminate\Support\Carbon::parse($lastDate)->format('d/m/Y H:i') }}
                    @endif
                </p>
            @endif

            <div class="dinamica-actions">
                <button type="button" class="btn btn-dinamica" id="dinamica-open">
                    <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                    <span>Restablecer Amigo Secreto</span>
                </button>
            </div>
        </article>
    </section>

    <div id="dinamica-config"
         data-url-iniciar="{{ route('dinamica.iniciar') }}"
         data-url-progreso="{{ route('dinamica.progreso') }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>

    <x-app-modal id="modal-dinamica" title="Restablecer Amigo Secreto">
        <x-slot:body>
            {{-- Paso 1: confirmación. --}}
            <div id="dinamica-confirm">
                <p class="dinamica-modal__lead">
                    ¿Deseas restablecer la dinámica del amigo secreto?
                </p>

                <p class="dinamica-modal__text">
                    Se perderá todo lo registrado —eventos, listas, pistas, chats, adjuntos, moderación,
                    reportes y las cuentas de los participantes— y el sistema comenzará de cero.
                    Solo se mantienen los CRUD, los módulos, los roles y tu cuenta de superadministrador.
                </p>

                <div class="dinamica-modal__warn">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>Esta acción no se puede deshacer.</span>
                </div>

                <div class="dinamica-modal__actions">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-dinamica" id="dinamica-start">
                        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                        <span>Sí, restablecer todo</span>
                    </button>
                </div>
            </div>

            {{-- Paso 2: progreso y bitácora, en el mismo modal. --}}
            <div id="dinamica-running" hidden>
                <p class="dinamica-modal__lead" id="dinamica-status" role="status" aria-live="polite">
                    Preparando el proceso…
                </p>

                <div class="progress dinamica-progress" id="dinamica-progressbar"
                     role="progressbar" aria-label="Progreso del restablecimiento"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div class="progress-bar" id="dinamica-progress" style="width: 0%"></div>
                </div>

                <p class="dinamica-modal__meta" id="dinamica-step" aria-live="polite"></p>

                <pre class="dinamica-log" id="dinamica-log" aria-live="polite"></pre>

                <div class="dinamica-modal__actions" id="dinamica-finished" hidden>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <a class="btn btn-primary" id="dinamica-back" href="{{ route('dashboard') }}">
                        <i class="fa-solid fa-house" aria-hidden="true"></i>
                        <span>Ir al panel</span>
                    </a>
                </div>
            </div>
        </x-slot:body>
    </x-app-modal>
@endsection

@push('scripts')
    <script src="{{ asset('js/dinamica.js') }}?v={{ filemtime(public_path('js/dinamica.js')) }}"></script>
@endpush
