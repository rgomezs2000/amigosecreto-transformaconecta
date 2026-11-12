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
            Configura cómo se avisa a todos los usuarios. Los cambios se aplican a todo el sistema.
        </p>

        <form id="form-notificaciones" class="roles-form" novalidate>
            @csrf

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
                    <p class="notification-row__hint">Avisar dentro de la aplicación cuando haya novedades.</p>
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
                    <p class="notification-row__hint">Reproducir un sonido al recibir mensajes del chat.</p>
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
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/notificaciones.js') }}?v={{ filemtime(public_path('js/notificaciones.js')) }}"></script>
@endpush
