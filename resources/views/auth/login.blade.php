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
                    <button type="submit" class="btn btn-primary btn-lg" id="login-submit" data-processing-manual="true">
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

                <div class="login-secondary">
                    <a href="#" id="login-register-open">
                        ¿Deseas registrarte y participar? Puedes solicitarlo acá.
                    </a>
                    <a href="#" id="login-reset-open">
                        ¿Olvidaste tu contraseña? Solicita tu restablecimiento acá.
                    </a>
                </div>
            </form>
        </section>

        {{-- Prerregistro: datos básicos para que la administración evalúe la solicitud. --}}
        <div class="modal fade" id="modal-solicitud-registro" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="modal-solicitud-registro-title">Solicitar registro</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="form-text mb-3">
                            Completa tus datos. La administración evaluará la solicitud y, si se aprueba,
                            recibirás tus credenciales por correo. Tu usuario es personal e intransferible.
                        </p>

                        <form id="solicitud-registro-form" novalidate>
                            <div class="field">
                                <label class="form-label" for="solicitud-nombre">Nombre</label>
                                <input class="form-control" id="solicitud-nombre" maxlength="255" autocomplete="name">
                            </div>

                            <div class="field">
                                <label class="form-label" for="solicitud-email">Correo electrónico</label>
                                <input class="form-control" id="solicitud-email" type="email" maxlength="255" autocomplete="email">
                            </div>

                            <div class="field">
                                <label class="form-label" for="solicitud-usuario">Usuario</label>
                                <input class="form-control" id="solicitud-usuario" maxlength="30" autocomplete="username">
                                <p class="form-text" id="solicitud-usuario-estado"></p>
                            </div>

                            <p class="field-message" id="solicitud-registro-estado" role="status" aria-live="polite"></p>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" id="solicitud-registro-limpiar">Limpiar</button>
                        <button type="submit" form="solicitud-registro-form" class="btn btn-primary" id="solicitud-registro-enviar">Solicitar registro</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recuperación de contraseña: crea un caso de tipo Cuenta. --}}
        <div class="modal fade" id="modal-solicitud-recuperacion" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5">Solicitar restablecimiento</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="form-text mb-3">
                            Escribe tu usuario o correo. La administración procesará el restablecimiento
                            y te enviará las nuevas credenciales por correo.
                        </p>

                        <div class="field">
                            <label class="form-label" for="solicitud-recuperacion-login">Usuario o correo</label>
                            <input class="form-control" id="solicitud-recuperacion-login" maxlength="255">
                        </div>

                        <p class="field-message" id="solicitud-recuperacion-estado" role="status" aria-live="polite"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="solicitud-recuperacion-enviar">Solicitar restablecimiento</button>
                    </div>
                </div>
            </div>
        </div>

        <footer class="login-footer">
            <p>Amigo Secreto Inclusivo · {{ now()->year }}</p>
            <p><a href="{{ route('compatibilidad') }}">Compatibilidad de navegadores</a></p>
        </footer>
    </div>
</main>
@push('scripts')
<script>
(function () {
    var csrf = @json(csrf_token());
    var registroModal = document.getElementById('modal-solicitud-registro');
    var recuperacionModal = document.getElementById('modal-solicitud-recuperacion');
    var $usuario = document.getElementById('solicitud-usuario');
    var $estadoUsuario = document.getElementById('solicitud-usuario-estado');
    var $registroEstado = document.getElementById('solicitud-registro-estado');
    var $recuperacionEstado = document.getElementById('solicitud-recuperacion-estado');
    var $registroBoton = document.getElementById('solicitud-registro-enviar');
    var disponible = null;
    var enviandoRegistro = false;
    var timer = null;

    function abrir(modal) {
        if (modal && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    }

    function mensaje(elemento, texto, tipo) {
        elemento.textContent = texto || '';
        elemento.setAttribute('data-kind', tipo || '');
    }

    function consultarUsuario() {
        var username = $usuario.value.trim();
        disponible = null;

        if (username === '') {
            mensaje($estadoUsuario, '', '');
            return;
        }

        fetch('{{ route('solicitudes.usuario') }}?username=' + encodeURIComponent(username), {
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            disponible = data.available === true;
            mensaje($estadoUsuario, data.message || '', disponible ? 'success' : 'error');
        }).catch(function () {
            disponible = null;
        });
    }

    if ($usuario) {
        $usuario.addEventListener('input', function () {
            clearTimeout(timer);
            mensaje($estadoUsuario, 'Comprobando disponibilidad...', 'info');
            timer = setTimeout(consultarUsuario, 350);
        });
    }

    var enlaceRegistro = document.getElementById('login-register-open');
    var enlaceReset = document.getElementById('login-reset-open');

    if (enlaceRegistro) {
        enlaceRegistro.addEventListener('click', function (event) {
            event.preventDefault();
            abrir(registroModal);
        });
    }

    if (enlaceReset) {
        enlaceReset.addEventListener('click', function (event) {
            event.preventDefault();
            mensaje($recuperacionEstado, '', '');
            abrir(recuperacionModal);
        });
    }

    document.getElementById('solicitud-registro-limpiar').addEventListener('click', function () {
        document.getElementById('solicitud-nombre').value = '';
        document.getElementById('solicitud-email').value = '';
        $usuario.value = '';
        disponible = null;
        mensaje($estadoUsuario, '', '');
        mensaje($registroEstado, '', '');
    });

    function enviarSolicitudRegistro() {
        var nombre = document.getElementById('solicitud-nombre').value.trim();
        var email = document.getElementById('solicitud-email').value.trim();
        var username = $usuario.value.trim();

        if (nombre === '' || email === '' || username === '') {
            mensaje($registroEstado, 'Completa tu nombre, correo y usuario.', 'error');
            return;
        }

        if (disponible !== true) {
            mensaje($registroEstado, 'Comprueba que el usuario esté disponible.', 'error');
            consultarUsuario();
            return;
        }

        confirmDialog('¿Deseas registrarte y participar? Tu solicitud será evaluada por la administración.', function () {
            if (enviandoRegistro) {
                return;
            }

            enviandoRegistro = true;

            if ($registroBoton) {
                $registroBoton.disabled = true;
            }

            fetch('{{ route('solicitudes.registro') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify({ name: nombre, email: email, username: username })
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                if (!result.ok) {
                    mensaje($registroEstado, result.data.message || 'No se pudo enviar la solicitud.', 'error');
                    return;
                }

                var mensajeExito = result.data.message || 'Solicitud registrada.';

                if (registroModal && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(registroModal).hide();
                }

                document.getElementById('solicitud-registro-form').reset();
                $usuario.value = '';
                disponible = null;
                mensaje($estadoUsuario, '', '');
                mensaje($registroEstado, '', '');

                window.AppDialog.success(mensajeExito, { title: 'Solicitud registrada' });
            }).catch(function () {
                mensaje($registroEstado, 'No se pudo enviar la solicitud. Intenta de nuevo.', 'error');
            }).finally(function () {
                enviandoRegistro = false;

                if ($registroBoton) {
                    $registroBoton.disabled = false;
                }
            });
        });
    }

    var registroForm = document.getElementById('solicitud-registro-form');

    if (registroForm) {
        registroForm.addEventListener('submit', function (event) {
            event.preventDefault();
            enviarSolicitudRegistro();
        });

        // Enter en cualquiera de los campos levanta la misma confirmación.
        registroForm.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.shiftKey) {
                return;
            }

            if (event.target && event.target.tagName === 'INPUT') {
                event.preventDefault();
                enviarSolicitudRegistro();
            }
        });
    }

    document.getElementById('solicitud-recuperacion-enviar').addEventListener('click', function () {
        var login = document.getElementById('solicitud-recuperacion-login').value.trim();

        if (login === '') {
            mensaje($recuperacionEstado, 'Escribe tu usuario o correo.', 'error');
            return;
        }

        confirmDialog('¿Desea solicitar recuperación de tu contraseña? Una vez aprobada se realiza tu cambio de contraseña por correo.', function () {
            fetch('{{ route('solicitudes.recuperacion') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify({ login: login })
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                if (!result.ok) {
                    mensaje(
                        $recuperacionEstado,
                        result.data.message || 'No se pudo enviar la solicitud.',
                        'error'
                    );

                    return;
                }

                var mensajeSolicitud = result.data.message
                    || 'Si la cuenta existe, la administración procesará el restablecimiento y te enviará las credenciales por correo.';

                if (recuperacionModal && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(recuperacionModal).hide();
                }

                document.getElementById('solicitud-recuperacion-login').value = '';
                mensaje($recuperacionEstado, '', '');

                window.AppDialog.success(mensajeSolicitud, {
                    title: 'Solicitud de restablecimiento'
                });
            }).catch(function () {
                mensaje($recuperacionEstado, 'No se pudo enviar la solicitud. Intenta de nuevo.', 'error');
            });
        });
    });
})();
</script>
@endpush
@endsection
