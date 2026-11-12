@extends('layouts.app')

@section('title', 'Control de Notificaciones · Amigo Secreto Inclusivo')
@section('shell-title', 'Control de Notificaciones')
@section('shell-eyebrow', 'Administración')

@section('page')
    @if (session('notificaciones_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('notificaciones_status') }}</span>
        </div>
    @endif

    <section class="notificaciones-page" aria-label="Control de Notificaciones">
        <p class="eventos-subtitle">
            Configura cómo se avisa a todos los usuarios. Los cambios globales se aplican al sistema;
            cada persona puede ajustar su sonido en la campana o en el chat.
        </p>

        @php
            $trafficCards = [
                ['key' => 'push', 'title' => 'Notificaciones push', 'icon' => 'fa-bell'],
                ['key' => 'emails', 'title' => 'Correos', 'icon' => 'fa-envelope'],
                ['key' => 'messages', 'title' => 'Mensajes de chat', 'icon' => 'fa-comments'],
                ['key' => 'reports', 'title' => 'Reportes', 'icon' => 'fa-flag'],
            ];
        @endphp

        <section class="matriz-card" aria-label="Tráfico de notificaciones">
            <h2 class="eventos-title">Tráfico</h2>
            <p class="notification-row__hint">
                Cuánto se ha enviado por cada canal. Las cifras salen de los registros SQL.
            </p>

            <div class="traffic-grid">
                @foreach ($trafficCards as $card)
                    <article class="traffic-card">
                        <p class="traffic-card__head">
                            <i class="fa-solid {{ $card['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $card['title'] }}</span>
                        </p>
                        <p class="traffic-card__today">{{ format_count($traffic[$card['key']]['today']) }}</p>
                        <p class="traffic-card__label">Hoy</p>
                        <dl class="traffic-card__stats">
                            <div>
                                <dt>Últimos 7 días</dt>
                                <dd>{{ format_count($traffic[$card['key']]['week']) }}</dd>
                            </div>
                            <div>
                                <dt>Total</dt>
                                <dd>{{ format_count($traffic[$card['key']]['total']) }}</dd>
                            </div>
                        </dl>

                        @if ($card['key'] === 'emails')
                            <p class="traffic-card__extra">
                                {{ format_count($traffic['emails']['failed']) }} fallidos ·
                                {{ format_count($traffic['emails']['skipped']) }} omitidos
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>

            <p class="traffic-note">
                Conversaciones: {{ format_count($traffic['conversations']['direct']) }} directas ·
                {{ format_count($traffic['conversations']['group']) }} grupos ·
                {{ format_count($traffic['conversations']['reports']) }} canal de reportes.
            </p>
        </section>

        <section class="matriz-card" aria-label="Estado del espejo NoSQL">
            <h2 class="eventos-title">Firestore (NoSQL)</h2>

            <p class="traffic-note">
                @if ($firestore['enabled'])
                    <span class="badge text-bg-success">Conectado</span>
                    Transporte <strong>{{ $firestore['driver'] }}</strong>
                    @if ($firestore['project'])
                        · Proyecto <strong>{{ $firestore['project'] }}</strong>
                    @endif
                @else
                    <span class="badge text-bg-secondary">Desactivado</span>
                    Revisa <code>FIREBASE_CREDENTIALS</code> y <code>FIREBASE_FIRESTORE_DRIVER</code>.
                @endif
            </p>

            @if ($firestore['last_error'])
                <p class="field-message" role="status">{{ $firestore['last_error'] }}</p>
            @endif

            <p class="notification-row__hint">
                Se guardan en Firestore: notificaciones push, correos, conversaciones
                (directas, grupos y moderación), mensajes del chat y reportes.
            </p>
        </section>

        <form id="form-notificaciones" class="roles-form" novalidate>
            @csrf

            <h2 class="eventos-title">Configuración global</h2>

            <div class="notification-row">
                <div>
                    <p class="notification-row__title">Mensajes por correo</p>
                    <p class="notification-row__hint">Enviar correos de eventos, cuentas y notificaciones.</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="email_enabled"
                           name="email_enabled" @checked($settings->email_enabled)>
                    <label class="form-check-label" for="email_enabled">Activado</label>
                </div>
            </div>

            <div class="notification-row">
                <div>
                    <p class="notification-row__title">Notificaciones push</p>
                    <p class="notification-row__hint">Crear avisos cuando haya novedades para la persona.</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="push_enabled"
                           name="push_enabled" @checked($settings->push_enabled)>
                    <label class="form-check-label" for="push_enabled">Activado</label>
                </div>
            </div>

            <div class="notification-row">
                <div>
                    <p class="notification-row__title">Sonidos</p>
                    <p class="notification-row__hint">
                        Reproducir un sonido al recibir avisos. Si se desactiva, quedan solo
                        las notificaciones visuales dentro de la app (menos estímulos auditivos).
                    </p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="sound_enabled"
                           name="sound_enabled" @checked($settings->sound_enabled)>
                    <label class="form-check-label" for="sound_enabled">Activado</label>
                </div>
            </div>

            <div class="notification-row">
                <div>
                    <p class="notification-row__title">Notificaciones visuales dentro de la app</p>
                    <p class="notification-row__hint">Mostrar avisos y contadores sin sonido.</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="in_app_enabled"
                           name="in_app_enabled" @checked($settings->in_app_enabled)>
                    <label class="form-check-label" for="in_app_enabled">Activado</label>
                </div>
            </div>

            <div class="roles-form__actions">
                <button type="submit" class="btn btn-primary" id="notificaciones-save">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <span>Guardar configuración</span>
                </button>
            </div>
        </form>

        <section class="matriz-card" aria-label="Preferencias individuales">
            <h2 class="eventos-title">Preferencias por usuario</h2>
            <p class="notification-row__hint">
                Cada persona puede silenciar el sonido y seguir viendo sus avisos. Aquí el
                administrador puede ajustarlo también. «Heredar» vuelve a la configuración global.
            </p>

            <div class="table-responsive">
                <table class="table table-sm align-middle preferences-table">
                    <thead>
                        <tr>
                            <th scope="col">Usuario</th>
                            <th scope="col">Rol</th>
                            <th scope="col">Push</th>
                            <th scope="col">Sonido</th>
                            <th scope="col">Sonido chat</th>
                            <th scope="col">Visual</th>
                            <th scope="col"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr data-user-id="{{ $user['id'] }}"
                                data-url="{{ route('notificaciones.usuarios.preferences', $user['id']) }}">
                                <td>
                                    <span class="preferences-table__name">{{ $user['name'] }}</span>
                                    <span class="preferences-table__email">{{ $user['email'] }}</span>
                                </td>
                                <td>{{ $user['role'] }}</td>

                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input pref-switch" type="checkbox" role="switch"
                                               data-field="push_enabled"
                                               aria-label="Push de {{ $user['name'] }}"
                                               @checked($user['preferences']['push'])>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input pref-switch" type="checkbox" role="switch"
                                               data-field="sound_enabled"
                                               aria-label="Sonido de {{ $user['name'] }}"
                                               @checked($user['preferences']['sound'])>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input pref-switch" type="checkbox" role="switch"
                                               data-field="chat_sound_enabled"
                                               aria-label="Sonido del chat de {{ $user['name'] }}"
                                               @checked($user['preferences']['chat_sound'])>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input pref-switch" type="checkbox" role="switch"
                                               data-field="in_app_enabled"
                                               aria-label="Avisos visuales de {{ $user['name'] }}"
                                               @checked($user['preferences']['in_app'])>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary pref-reset"
                                            data-url="{{ route('notificaciones.usuarios.preferences', $user['id']) }}">
                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                        <span>Heredar</span>
                                    </button>
                                    <p class="pref-status" role="status" aria-live="polite"></p>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <input type="hidden" id="notificaciones-csrf" value="{{ csrf_token() }}">
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/notificaciones.js') }}?v={{ filemtime(public_path('js/notificaciones.js')) }}"></script>
@endpush
