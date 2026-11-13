@extends('layouts.base')

@section('title', 'Acceso al sistema · Amigo Secreto Inclusivo')

@section('content')
<main id="contenido" class="login-page">
    <div class="login-shell">

        {{-- Aviso que deja el restablecimiento de la dinámica al cerrar la sesión. --}}
        @if (session('restablecimiento_aviso'))
            <div class="alert alert-warning login-alert" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <span>{{ session('restablecimiento_aviso') }}</span>
            </div>
        @endif

        {{-- 1. Título y acceso al sistema --}}
        <header class="login-header">
            <p class="login-eyebrow">Amigo Secreto Inclusivo</p>
            <h1 class="login-title">Acceso al sistema</h1>
            <p class="login-subtitle">
                Ingresa con tu usuario y tu clave. Si tu cuenta está vinculada a Google,
                también puedes usar el botón de Google.
            </p>
        </header>

        {{-- 2. Formulario --}}
        <section class="login-card" aria-labelledby="form-title">
            <h2 id="form-title" class="section-title">Datos de acceso</h2>

            <form id="login-form" method="post" action="{{ url('/login') }}" novalidate>
                @csrf

                <div class="field">
                    <label for="login" class="form-label">Usuario o correo</label>
                    <input
                        type="text"
                        class="form-control form-control-lg"
                        id="login"
                        name="login"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                        autofocus
                        aria-describedby="login-help">
                    <p id="login-help" class="form-text">Escribe tu usuario o tu correo electrónico.</p>
                </div>

                <div class="field">
                    <label for="password" class="form-label">Clave</label>
                    <div class="input-group">
                        <input
                            type="password"
                            class="form-control form-control-lg"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            required>
                        <button
                            type="button"
                            class="btn btn-outline-secondary password-toggle"
                            id="toggle-password"
                            aria-controls="password"
                            aria-label="Mantén pulsado para ver la clave"
                            title="Mantén pulsado para ver la clave">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <p id="login-status" class="form-status" role="status" aria-live="polite"></p>

                <div class="login-actions">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                        <span>Ingresar</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg" id="btn-exit">
                        <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                        <span>Salir</span>
                    </button>
                </div>

                <div class="login-divider" role="separator" aria-hidden="true"><span>o</span></div>

                @if (config('services.google.client_id'))
                    <div class="login-google-panel">
                        <a href="{{ route('google.redirect') }}" class="btn btn-outline-secondary btn-lg w-100 google-button-link">
                            <i class="fa-brands fa-google" aria-hidden="true"></i>
                            <span>Continuar con Google</span>
                        </a>
                        <p class="login-google__help">Solo para cuentas creadas por el administrador.</p>
                    </div>
                @else
                    <p class="form-status" data-kind="info">
                        Google todavía no está configurado en este entorno.
                    </p>
                @endif
            </form>
        </section>

        <footer class="login-footer">
            <p>Amigo Secreto Inclusivo · {{ now()->year }}</p>
        </footer>
    </div>
</main>
@endsection
