/**
 * Scripts del sistema (frontend).
 *
 * Dependen de jQuery y de functions.js. El login valida por AJAX y responde
 * con JSON; los mensajes se muestran con Bootbox. El regreso de Google llega
 * con ?google=... y aquí se convierte en un diálogo.
 */
(function ($, App) {
    'use strict';

    if (!$ || !App) {
        return;
    }

    $(function () {
        initPasswordToggle();
        initLoginForm();
        initExitButton();
        showGoogleResult();
        initDashboardClock();
    });

    /**
     * Muestra un aviso con Bootbox (o window.alert si no cargó).
     */
    function dialog(message, title, callback) {
        if (window.bootbox) {
            window.bootbox.alert({
                title: title || 'Aviso',
                message: message,
                centerVertical: true,
                callback: typeof callback === 'function' ? callback : $.noop
            });
            return;
        }

        window.alert(message);

        if (typeof callback === 'function') {
            callback();
        }
    }

    /**
     * Ver la clave solo mientras se mantiene pulsado el botón.
     */
    function initPasswordToggle() {
        var $toggle = $('#toggle-password');
        var $password = $('#password');

        if (!$toggle.length || !$password.length) {
            return;
        }

        var $icon = $toggle.find('i');

        function show() {
            $password.attr('type', 'text');
            $icon.removeClass('fa-eye').addClass('fa-eye-slash');
        }

        function hide() {
            $password.attr('type', 'password');
            $icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }

        // Ratón y pantalla táctil.
        $toggle.on('pointerdown', function (event) {
            event.preventDefault();
            show();
        });

        $toggle.on('pointerup pointercancel pointerleave', hide);

        // Teclado: mantener Espacio o Enter.
        $toggle.on('keydown', function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                show();
            }
        });

        $toggle.on('keyup', function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
                hide();
            }
        });

        $toggle.on('blur', hide);
        $(window).on('blur', hide);
    }

    /**
     * Validación local, foco en el campo vacío y envío por AJAX.
     */
    function initLoginForm() {
        var $form = $('#login-form');

        if (!$form.length) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var $login = $('#login');
            var $password = $('#password');
            var missing = null;

            if (!$.trim($login.val())) {
                missing = $login;
            } else if (!$.trim($password.val())) {
                missing = $password;
            }

            if (missing) {
                missing.trigger('focus');

                dialog('Completa el usuario y la clave para continuar.', 'Faltan datos', function () {
                    missing.trigger('focus');
                });

                return;
            }

            var $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                dataType: 'json',
                data: {
                    _token: $form.find('input[name="_token"]').val(),
                    login: $.trim($login.val()),
                    password: $password.val()
                }
            }).done(function (response) {
                // La sesión ya quedó iniciada en el servidor: al panel.
                window.location.assign(response.redirect || '/dashboard');
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                var message = response.message;

                if (!message && response.errors) {
                    var first = Object.keys(response.errors)[0];
                    message = response.errors[first][0];
                }

                dialog(
                    message || 'No se pudo completar el acceso.',
                    xhr.status === 423 ? 'Cuenta bloqueada' : 'No se pudo ingresar'
                );
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });
    }

    /**
     * Botón Salir: confirma, limpia el formulario y devuelve el foco.
     */
    function initExitButton() {
        var $exit = $('#btn-exit');
        var $form = $('#login-form');

        if (!$exit.length || !$form.length) {
            return;
        }

        $exit.on('click', function () {
            var message = 'Se borrarán los datos que escribiste. ¿Deseas salir?';

            function doExit(confirmed) {
                if (!confirmed) {
                    return;
                }

                $form[0].reset();
                App.setStatus($('#login-status'), 'Formulario limpio. Puedes volver a ingresar cuando quieras.', 'info');
                $('#login').trigger('focus');
            }

            if (window.bootbox) {
                window.bootbox.confirm({
                    title: 'Salir del formulario',
                    message: message,
                    centerVertical: true,
                    buttons: {
                        cancel: { label: 'Quedarme', className: 'btn-outline-secondary' },
                        confirm: { label: 'Sí, salir', className: 'btn-primary' }
                    },
                    callback: doExit
                });
            } else {
                doExit(window.confirm(message));
            }
        });
    }

    /**
     * Fecha y hora del panel, siempre en hora de Venezuela.
     */
    function initDashboardClock() {
        var $clock = $('#dashboard-clock');

        if (!$clock.length) {
            return;
        }

        var formatter = new Intl.DateTimeFormat('es-VE', {
            timeZone: 'America/Caracas',
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });

        function update() {
            $clock.text(formatter.format(new Date()));
        }

        update();
        window.setInterval(update, 1000);
    }

    /**
     * Lee ?google=ok|not_found|... que deja el callback y lo muestra con Bootbox.
     */
    function showGoogleResult() {
        var params = new URLSearchParams(window.location.search);
        var result = params.get('google');

        if (!result) {
            return;
        }

        var name = params.get('name');
        var messages = {
            ok: 'Acceso correcto con Google' + (name ? ', ' + name : '') + '.',
            not_found: 'No existe una cuenta con ese correo. Pide al administrador que te cree una.',
            inactive: 'La cuenta está inactiva. Contacta al administrador.',
            locked: 'La cuenta está bloqueada. Contacta al administrador.',
            cancelled: 'Cancelaste el acceso con Google.',
            error: 'No se pudo completar el acceso con Google. Intenta de nuevo.',
            not_configured: 'Google no está configurado en este entorno.'
        };

        dialog(
            messages[result] || messages.error,
            result === 'ok' ? 'Acceso correcto' : 'Acceso con Google'
        );

        // Quita los parámetros para que el aviso no se repita al recargar.
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
})(window.jQuery, window.App);
