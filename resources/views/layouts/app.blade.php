@extends('layouts.base')

@section('content')
<div class="dashboard-page" id="contenido">
    <header class="dashboard-header">
        <div class="dashboard-top">
            <button class="btn dashboard-menu-btn"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#main-menu"
                    aria-controls="main-menu"
                    aria-label="Abrir menú">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>

            <div class="dashboard-heading">
                <p class="dashboard-eyebrow">@yield('shell-eyebrow', $shell['event']?->name ?? 'Amigo Secreto Inclusivo')</p>
                <h1 class="dashboard-title">@yield('shell-title', 'Panel de control')</h1>
            </div>

            @if ($shell['showNotifications'])
                <button class="btn dashboard-notifications-btn"
                        type="button"
                        id="notifications-open"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#notifications-panel"
                        aria-controls="notifications-panel"
                        aria-label="Notificaciones: {{ $shell['notificationsCount'] }}">
                    <i class="fa-regular fa-bell" aria-hidden="true"></i>
                    <span class="dashboard-notifications-label">Notificaciones</span>
                    <span class="dashboard-notifications-count" id="notifications-count">{{ $shell['notificationsCount'] }}</span>
                </button>
            @endif
        </div>

        <div class="dashboard-meta">
            <div id="event-countdown"
                 class="dashboard-countdown @if (! $shell['event']?->exchange_at) dashboard-countdown--empty @endif"
                 data-exchange-at="{{ $shell['event']?->exchange_at?->toIso8601String() }}">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                <span>{{ $shell['event']?->exchange_at ? 'Calculando…' : 'Sin fecha de intercambio' }}</span>
            </div>

            <p class="dashboard-clock" id="dashboard-clock" aria-live="polite"></p>
        </div>

        <div class="dashboard-welcome">
            <p class="dashboard-greeting">Hola: {{ $shell['user']->name }}</p>

            <p class="dashboard-rate">
                US$/Bs (BCV):
                <strong>{{ format_rate($shell['dollarRate']) }}</strong>
            </p>
        </div>
    </header>

    @yield('page')

    @include('partials.chat-widget')

    <div class="offcanvas offcanvas-start" tabindex="-1" id="main-menu"
         data-bs-backdrop="true" data-bs-scroll="false"
         aria-labelledby="main-menu-label">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="main-menu-label">Menú</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            @include('partials.menu', ['groups' => $shell['menuGroups']])
        </div>
    </div>

    @if ($shell['showNotifications'])
        <div class="offcanvas offcanvas-end" tabindex="-1" id="notifications-panel"
             data-bs-backdrop="true" data-bs-scroll="false"
             aria-labelledby="notifications-title"
             data-url-contador="{{ route('notificaciones.contador') }}"
             data-url-leer-todas="{{ route('notificaciones.leer-todas') }}"
             data-url-leer="{{ route('notificaciones.leer', ['notification' => '__ID__']) }}"
             data-url-preferencias="{{ route('notificaciones.preferencias') }}"
             data-csrf="{{ csrf_token() }}"
             data-sound="{{ $shell['notificationPreferences']['sound'] ? '1' : '0' }}">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title" id="notifications-title">Notificaciones</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
            </div>
            <div class="offcanvas-body">
                <section class="alert-prefs" aria-label="Preferencias de aviso">
                    <p class="alert-prefs__title">Cómo quieres tus avisos</p>

                    <div class="alert-prefs__row">
                        <div class="form-check form-switch">
                            <input class="form-check-input alert-pref" type="checkbox" role="switch"
                                   id="pref-push" data-field="push_enabled"
                                   @checked($shell['notificationPreferences']['push'])>
                            <label class="form-check-label" for="pref-push">Alertas push</label>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input alert-pref" type="checkbox" role="switch"
                                   id="pref-sound" data-field="sound_enabled"
                                   @checked($shell['notificationPreferences']['sound'])>
                            <label class="form-check-label" for="pref-sound">Sonido</label>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input alert-pref" type="checkbox" role="switch"
                                   id="pref-in-app" data-field="in_app_enabled"
                                   @checked($shell['notificationPreferences']['in_app'])>
                            <label class="form-check-label" for="pref-in-app">Avisos visuales</label>
                        </div>
                    </div>

                    <p class="alert-prefs__hint">
                        Si desactivas el sonido, los avisos quedan solo a la vista para evitar
                        estímulos auditivos inesperados.
                        @if ($shell['canManageNotifications'])
                            <a href="{{ route('notificaciones.index') }}">Configuración global</a>
                        @endif
                    </p>

                    <p class="alert-prefs__status" id="alert-prefs-status" role="status" aria-live="polite"></p>
                </section>

                <ul class="alert-list" id="alert-list">
                    @forelse ($shell['notifications'] as $notification)
                        <li class="alert-item {{ $notification->read_at ? '' : 'is-unread' }}"
                            data-id="{{ $notification->id }}">
                            <button type="button" class="alert-item__button"
                                    data-id="{{ $notification->id }}"
                                    data-url="{{ $notification->url }}"
                                    data-read="{{ $notification->read_at ? '1' : '0' }}">
                                <span class="alert-item__dot" aria-hidden="true"></span>
                                <span class="alert-item__text">
                                    <span class="alert-item__title">{{ $notification->title() }}</span>
                                    @if ($notification->body() !== '')
                                        <span class="alert-item__body">{{ $notification->body() }}</span>
                                    @endif
                                    <span class="alert-item__date">{{ format_date($notification->created_at) }}</span>
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="alert-empty">No tienes notificaciones.</li>
                    @endforelse
                </ul>

                <div class="alert-list__actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="alert-mark-all"
                            @disabled($shell['notificationsCount'] === 0)>
                        <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                        <span>Marcar todas como leídas</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@if ($shell['showNotifications'])
    @push('scripts')
        <script src="{{ asset('js/alertas.js') }}?v={{ filemtime(public_path('js/alertas.js')) }}"></script>
    @endpush
@endif
