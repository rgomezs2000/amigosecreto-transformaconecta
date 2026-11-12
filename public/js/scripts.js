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
        initEventCountdown();
        initOffcanvasDismiss();
        initNotificationModalEscape();
        initProfilePasswordToggles();
        initPerfilUsernameCheck();
        initPerfilPasswordForm();
        showLockedResult();
    });

    /**
     * Cierra el menú o las notificaciones al hacer clic fuera de ellos.
     */
    function initOffcanvasDismiss() {
        if (!window.bootstrap || !window.bootstrap.Offcanvas) {
            return;
        }

        document.addEventListener('click', function (event) {
            var target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            var panels = document.querySelectorAll('.offcanvas.show');

            if (!panels.length) {
                return;
            }

            // Un modal (o un diálogo de Bootbox) está por encima del panel:
            // sus clics no deben cerrar el offcanvas de abajo. Se comprueba
            // también el destino porque Bootstrap quita ".show" al ocultarlo.
            if (document.querySelector('.modal.show') || target.closest('.modal, .modal-backdrop, .bootbox')) {
                return;
            }

            panels.forEach(function (panel) {
                if (panel.contains(target)) {
                    return;
                }

                if (target.closest('[data-bs-toggle="offcanvas"]')) {
                    return;
                }

                window.bootstrap.Offcanvas.getOrCreateInstance(panel).hide();
            });
        });
    }

    /**
     * Con el modal de una notificación abierto, Escape cierra solo el modal:
     * el panel de notificaciones que está detrás debe seguir abierto.
     */
    function initNotificationModalEscape() {
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !window.bootstrap || !window.bootstrap.Modal) {
                return;
            }

            var modal = document.getElementById('modal-notification-detail');

            if (!modal || !modal.classList.contains('show')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        }, true);
    }

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
     * Confirmación con Bootbox; si se cancela no se ejecuta la acción.
     */
    function confirmAction(message, title, onConfirm) {
        if (window.bootbox) {
            window.bootbox.confirm({
                title: title || 'Confirmar',
                message: message,
                centerVertical: true,
                buttons: {
                    cancel: { label: 'Cancelar', className: 'btn-outline-secondary' },
                    confirm: { label: 'Aceptar', className: 'btn-primary' }
                },
                callback: function (ok) {
                    if (ok) {
                        onConfirm();
                    }
                }
            });
            return;
        }

        if (window.confirm(message)) {
            onConfirm();
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
     * Cuenta regresiva hasta el intercambio, actualizada en vivo.
     *
     * Un solo temporizador de un segundo escribe el texto únicamente cuando
     * cambia; se pausa si la pestaña queda en segundo plano y se detiene al
     * llegar a la fecha. No consulta al servidor: no compite con el sondeo del
     * chat ni con el de los avisos, y no toca nada más de la página.
     */
    function initEventCountdown() {
        var countdown = document.getElementById('event-countdown');

        if (!countdown) {
            return;
        }

        var label = countdown.querySelector('span');
        var end = Date.parse(countdown.getAttribute('data-exchange-at') || '');

        if (!label) {
            return;
        }

        if (isNaN(end)) {
            countdown.classList.add('dashboard-countdown--empty');
            label.textContent = 'Sin fecha de intercambio';
            return;
        }

        // La hora del servidor corrige el desfase si el reloj del equipo está mal.
        var servedAt = Date.parse(countdown.getAttribute('data-server-now') || '');
        var skew = isNaN(servedAt) ? 0 : Date.now() - servedAt;
        var dayFormat = null;
        var timer = null;
        var lastText = '';
        var finished = false;

        function now() {
            return Date.now() - skew;
        }

        function dayKey(timestamp) {
            if (!dayFormat) {
                dayFormat = new Intl.DateTimeFormat('es-VE', {
                    timeZone: 'America/Caracas',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
            }

            var values = {};

            dayFormat.formatToParts(timestamp).forEach(function (part) {
                values[part.type] = part.value;
            });

            return values.year + '-' + values.month + '-' + values.day;
        }

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function caption(diff) {
            if (diff <= 0) {
                return dayKey(end) === dayKey(now())
                    ? 'El intercambio es hoy'
                    : 'El intercambio ya pasó';
            }

            var days = Math.floor(diff / 86400000);
            var hours = Math.floor((diff % 86400000) / 3600000);
            var minutes = Math.floor((diff % 3600000) / 60000);
            var seconds = Math.floor((diff % 60000) / 1000);
            var head = '';
            var clock = [];

            if (days > 0) {
                head = days + (days === 1 ? ' día, ' : ' días, ');
            }

            if (hours > 0) {
                clock.push(hours + ' h');
            }

            if (minutes > 0 || hours > 0 || days > 0) {
                clock.push(pad(minutes) + ' min');
            }

            clock.push(pad(seconds) + ' s');

            return 'Faltan ' + head + clock.join(' ') + ' para el intercambio';
        }

        function stop() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function paint() {
            var diff = end - now();
            var text = caption(diff);

            if (text !== lastText) {
                lastText = text;
                label.textContent = text;
            }

            if (diff <= 0) {
                finished = true;
                stop();
            }
        }

        function start() {
            stop();

            if (!finished) {
                timer = window.setInterval(paint, 1000);
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
                return;
            }

            paint();
            start();
        });

        paint();

        if (!document.hidden) {
            start();
        }
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

    /**
     * Aviso al llegar al login con ?locked=1 (sesión cerrada por bloqueo).
     */
    function showLockedResult() {
        var params = new URLSearchParams(window.location.search);

        if (params.get('locked') !== '1') {
            return;
        }

        dialog(
            'Tu usuario se encuentra bloqueado por demasiados intentos. Contacta al administrador.',
            'Cuenta bloqueada'
        );

        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }

    /**
     * Mostrar/ocultar las claves del perfil mientras se mantiene pulsado.
     */
    function initProfilePasswordToggles() {
        $('[data-toggle-password]').each(function () {
            var $toggle = $(this);
            var $input = $($toggle.attr('data-target'));

            if (!$input.length) {
                return;
            }

            var $icon = $toggle.find('i');

            function show() {
                $input.attr('type', 'text');
                $icon.removeClass('fa-eye').addClass('fa-eye-slash');
            }

            function hide() {
                $input.attr('type', 'password');
                $icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }

            $toggle.on('pointerdown', function (event) {
                event.preventDefault();
                show();
            });

            $toggle.on('pointerup pointercancel pointerleave', hide);

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
        });
    }

    /**
     * Perfil: consulta el login al salir del campo y guarda por AJAX.
     */
    function initPerfilUsernameCheck() {
        var $form = $('#form-perfil');

        if (!$form.length) {
            return;
        }

        var $username = $('#perfil-username');
        var $status = $('#perfil-username-status');
        var $submit = $('#perfil-submit');
        var current = String($username.attr('data-current') || '').toLowerCase();
        var checkUrl = $form.attr('data-login-url');
        var available = true;

        function setAvailability(ok, message) {
            available = ok;
            $status.attr('data-kind', ok ? 'ok' : 'error').text(message || '');
            $submit.prop('disabled', !ok);
        }

        function checkLogin() {
            var value = $.trim($username.val());

            if (!value) {
                setAvailability(false, 'El usuario es obligatorio.');
                return;
            }

            if (value.toLowerCase() === current) {
                setAvailability(true, 'Usuario disponible.');
                return;
            }

            $.ajax({
                url: checkUrl,
                method: 'GET',
                dataType: 'json',
                data: { login: value }
            }).done(function (response) {
                setAvailability(!!response.available, response.message || '');
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                setAvailability(false, response.message || 'No se pudo verificar el usuario.');
            });
        }

        $username.on('blur', checkLogin);

        $form.on('submit', function (event) {
            event.preventDefault();

            var firstInvalid = null;

            $form.find('input[name="name"], input[name="email"], input[name="username"]').each(function () {
                if (!$.trim($(this).val()) && firstInvalid === null) {
                    firstInvalid = $(this);
                }
            });

            if (firstInvalid !== null) {
                firstInvalid.trigger('focus');
                dialog('Completa todos los campos del perfil.', 'Faltan datos', function () {
                    firstInvalid.trigger('focus');
                });
                return;
            }

            if (!available) {
                $username.trigger('focus');
                dialog('El usuario no está disponible. Elige otro.', 'Usuario no disponible');
                return;
            }

            confirmAction('¿Desea cambiar el perfil?', 'Confirmar cambio de perfil', function () {
                $submit.prop('disabled', true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    dataType: 'json',
                    data: $form.serialize()
                }).done(function (response) {
                    window.location.assign(response.redirect || window.location.href);
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    var errors = response.errors || {};
                    var firstField = Object.keys(errors)[0];

                    if (firstField) {
                        $form.find('[name="' + firstField + '"]').trigger('focus');
                    }

                    dialog(
                        response.message || (firstField ? errors[firstField][0] : 'No se pudo actualizar el perfil.'),
                        'No se pudo guardar'
                    );
                }).always(function () {
                    $submit.prop('disabled', false);
                });
            });
        });
    }

    /**
     * Perfil: cambiar la clave con validación local y AJAX.
     */
    function initPerfilPasswordForm() {
        var $form = $('#form-clave');

        if (!$form.length) {
            return;
        }

        var $current = $('#perfil-current');
        var $password = $('#perfil-password');
        var $confirm = $('#perfil-password-confirm');
        var $submit = $('#clave-submit');

        function fail($field, message) {
            $field.trigger('focus');
            dialog(message, 'Revisa la clave', function () {
                $field.trigger('focus');
            });
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            if (!$.trim($current.val())) {
                fail($current, 'Escribe tu clave actual.');
                return;
            }

            if (!$.trim($password.val())) {
                fail($password, 'Escribe la nueva clave.');
                return;
            }

            if ($password.val().length < 10) {
                fail($password, 'La nueva clave debe tener al menos 10 caracteres.');
                return;
            }

            if (!$.trim($confirm.val())) {
                fail($confirm, 'Confirma la nueva clave.');
                return;
            }

            if ($password.val() !== $confirm.val()) {
                fail($confirm, 'Las claves no coinciden.');
                return;
            }

            confirmAction('¿Desea cambiar la contraseña?', 'Confirmar cambio de contraseña', function () {
                $submit.prop('disabled', true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    dataType: 'json',
                    data: $form.serialize()
                }).done(function (response) {
                    window.location.assign(response.redirect || window.location.href);
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    var errors = response.errors || {};
                    var firstField = Object.keys(errors)[0];

                    if (firstField) {
                        $form.find('[name="' + firstField + '"]').trigger('focus');
                    }

                    if (response.locked && response.redirect) {
                        dialog(
                            response.message || 'Tu usuario se encuentra bloqueado.',
                            'Cuenta bloqueada',
                            function () {
                                window.location.assign(response.redirect);
                            }
                        );
                        return;
                    }

                    dialog(
                        response.message || (firstField ? errors[firstField][0] : 'No se pudo cambiar la contraseña.'),
                        'No se pudo cambiar la contraseña'
                    );
                }).always(function () {
                    $submit.prop('disabled', false);
                });
            });
        });
    }
})(window.jQuery, window.App);
