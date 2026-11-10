@extends('layouts.base')

@section('title', 'Acceso al sistema · Amigo Secreto Inclusivo')

@push('head')
    @if (config('services.google.client_id'))
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    @endif
@endpush

@section('content')
<main id="contenido" class="login-page">
    <div class="login-shell">

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
            </form>
        </section>

        {{-- 3. Acceso con Google --}}
        <section class="login-google" aria-labelledby="google-title">
            <h2 id="google-title" class="section-title">Acceso con Google</h2>
            <p class="login-google__help">Solo para cuentas creadas por el administrador.</p>

            @if (config('services.google.client_id'))
                <div id="g_id_onload"
                     data-client_id="{{ config('services.google.client_id') }}"
                     data-callback="handleCredentialResponse"
                     data-auto_prompt="false"
                     data-locale="es">
                </div>

                <div class="g_id_signin"
                     data-type="standard"
                     data-size="large"
                     data-theme="outline"
                     data-text="signin_with"
                     data-shape="rectangular"
                     data-logo_alignment="left"
                     data-locale="es">
                </div>

                <p id="google-status" class="form-status" role="status" aria-live="polite"></p>

                <noscript>
                    <p class="login-google__help">Para usar Google necesitas activar JavaScript.</p>
                </noscript>
            @else
                <p class="form-status" data-kind="info">
                    Google todavía no está configurado en este entorno.
                </p>
            @endif
        </section>

        <footer class="login-footer">
            <p>Amigo Secreto Inclusivo · {{ now()->year }}</p>
        </footer>
    </div>
</main>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var form = document.getElementById('login-form');
    var statusBox = document.getElementById('login-status');
    var googleStatus = document.getElementById('google-status');
    var password = document.getElementById('password');
    var toggle = document.getElementById('toggle-password');
    var exitButton = document.getElementById('btn-exit');

    function setStatus(box, message, kind) {
        if (!box) {
            return;
        }

        box.textContent = message;
        box.dataset.kind = kind || 'info';
    }

    // Ver la clave solo mientras se mantiene pulsado el botón.
    if (toggle && password) {
        var icon = toggle.querySelector('i');

        function showPassword() {
            password.type = 'text';

            if (icon) {
                icon.className = 'fa-solid fa-eye-slash';
            }
        }

        function hidePassword() {
            password.type = 'password';

            if (icon) {
                icon.className = 'fa-solid fa-eye';
            }
        }

        // Ratón y pantalla táctil.
        toggle.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            showPassword();
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
            toggle.addEventListener(name, hidePassword);
        });

        // Teclado: mantener Espacio o Enter.
        toggle.addEventListener('keydown', function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                showPassword();
            }
        });

        toggle.addEventListener('keyup', function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
                hidePassword();
            }
        });

        toggle.addEventListener('blur', hidePassword);
        window.addEventListener('blur', hidePassword);
    }

    // Ingresar.
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                setStatus(statusBox, 'Completa el usuario y la clave para continuar.', 'error');
                return;
            }

            // TEMPORAL: la validación contra el servidor se conecta en la
            // siguiente fase (LoginService + POST /login).
            setStatus(statusBox, 'Datos completos. La validación se activa en la siguiente fase.', 'info');
        });
    }

    // Salir: limpia el formulario y devuelve el foco al inicio.
    if (exitButton && form) {
        exitButton.addEventListener('click', function () {
            var confirmMessage = 'Se borrarán los datos que escribiste. ¿Deseas salir?';

            function doExit(confirmed) {
                if (!confirmed) {
                    return;
                }

                form.reset();
                setStatus(statusBox, 'Formulario limpio. Puedes volver a ingresar cuando quieras.', 'info');
                document.getElementById('login').focus();
            }

            if (window.bootbox) {
                bootbox.confirm({
                    title: 'Salir del formulario',
                    message: confirmMessage,
                    centerVertical: true,
                    buttons: {
                        cancel: { label: 'Quedarme', className: 'btn-outline-secondary' },
                        confirm: { label: 'Sí, salir', className: 'btn-primary' }
                    },
                    callback: doExit
                });
            } else {
                doExit(window.confirm(confirmMessage));
            }
        });
    }

    function decodeJwt(token) {
        try {
            var part = token.split('.')[1];
            var base64 = part.replace(/-/g, '+').replace(/_/g, '/');
            var padded = base64 + '='.repeat((4 - base64.length % 4) % 4);

            var json = decodeURIComponent(Array.prototype.map.call(atob(padded), function (character) {
                return '%' + ('00' + character.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));

            return JSON.parse(json);
        } catch (error) {
            return null;
        }
    }

    // Google devuelve un ID token. Aquí solo se muestra que respondió: la
    // verificación de la firma y el inicio de sesión se hacen en el servidor.
    window.handleCredentialResponse = function (response) {
        if (!response || !response.credential) {
            setStatus(googleStatus, 'No se recibió la cuenta de Google. Intenta de nuevo.', 'error');
            return;
        }

        var account = decodeJwt(response.credential);

        if (!account || !account.email) {
            setStatus(googleStatus, 'No se pudo leer la cuenta de Google.', 'error');
            return;
        }

        setStatus(
            googleStatus,
            'Cuenta de Google detectada: ' + account.name + '. La verificación se activa en la siguiente fase.',
            'info'
        );
    };
})();
</script>
@endpush
