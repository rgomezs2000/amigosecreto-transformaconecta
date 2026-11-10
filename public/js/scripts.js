/**
 * Scripts del sistema (frontend).
 *
 * Dependen de jQuery, de functions.js y, solo en el login, del cliente de
 * Google. Cada bloque comprueba que sus elementos existan, así el mismo
 * archivo sirve para todas las pantallas.
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
        initGoogleButton();
    });

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
     * Envío del formulario de acceso.
     */
    function initLoginForm() {
        var $form = $('#login-form');
        var $status = $('#login-status');

        if (!$form.length) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var form = this;

            if (!form.checkValidity()) {
                form.reportValidity();
                App.setStatus($status, 'Completa el usuario y la clave para continuar.', 'error');
                return;
            }

            // TEMPORAL: la validación contra el servidor se conecta en la
            // siguiente fase (LoginService + POST /login).
            App.setStatus($status, 'Datos completos. La validación se activa en la siguiente fase.', 'info');
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
     * Botón de Google (Google Identity Services).
     */
    function initGoogleButton() {
        var $button = $('#google-button');

        if (!$button.length) {
            return;
        }

        var clientId = $button.attr('data-client-id');
        var $status = $('#google-status');

        function render() {
            if (!window.google || !window.google.accounts || !window.google.accounts.id) {
                return;
            }

            var width = Math.min($button[0].clientWidth || 320, 400);

            $button.empty();
            window.google.accounts.id.renderButton($button[0], {
                type: 'standard',
                theme: 'outline',
                size: 'large',
                shape: 'rectangular',
                text: 'continue_with',
                logo_alignment: 'left',
                locale: 'es',
                width: width
            });
        }

        function onCredential(response) {
            if (!response || !response.credential) {
                App.setStatus($status, 'No se recibió la cuenta de Google. Intenta de nuevo.', 'error');
                return;
            }

            var account = App.decodeJwt(response.credential);

            if (!account || !account.email) {
                App.setStatus($status, 'No se pudo leer la cuenta de Google.', 'error');
                return;
            }

            App.setStatus(
                $status,
                'Cuenta de Google detectada: ' + account.name + '. La verificación se activa en la siguiente fase.',
                'info'
            );
        }

        function init() {
            if (!clientId || !window.google || !window.google.accounts || !window.google.accounts.id) {
                return;
            }

            window.google.accounts.id.initialize({
                client_id: clientId,
                callback: onCredential,
                auto_select: false,
                cancel_on_tap_outside: true
            });

            render();
            $(window).on('resize', render);
        }

        if (document.readyState === 'complete') {
            init();
        } else {
            $(window).on('load', init);
        }
    }
})(window.jQuery, window.App);
