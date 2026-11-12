/**
 * Control de Notificaciones.
 *
 * Guarda la configuración global y las preferencias individuales por AJAX.
 * Un cambio de usuario se aplica al instante; «Heredar» recarga la página para
 * mostrar los valores globales resueltos.
 */
(function ($) {
    'use strict';

    $(function () {
        var $form = $('#form-notificaciones');
        var token = $('#notificaciones-csrf').val();

        function showError(xhr) {
            var response = xhr.responseJSON || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            message = message || 'No se pudo guardar la configuración.';

            if (window.bootbox) {
                window.bootbox.alert({ title: 'Aviso', message: message, centerVertical: true });
            } else {
                window.alert(message);
            }
        }

        function post(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, data)
            });
        }

        if ($form.length) {
            $form.on('submit', function (event) {
                event.preventDefault();

                var $button = $('#notificaciones-save');

                $button.prop('disabled', true);

                post(window.location.pathname, {
                    email_enabled: $('#email_enabled').is(':checked') ? 1 : 0,
                    push_enabled: $('#push_enabled').is(':checked') ? 1 : 0,
                    sound_enabled: $('#sound_enabled').is(':checked') ? 1 : 0,
                    in_app_enabled: $('#in_app_enabled').is(':checked') ? 1 : 0
                }).done(function (response) {
                    if (response.redirect) {
                        window.location.assign(response.redirect);
                    }
                }).fail(showError).always(function () {
                    $button.prop('disabled', false);
                });
            });
        }

        var fields = {
            push_enabled: 'push',
            sound_enabled: 'sound',
            chat_sound_enabled: 'chat_sound',
            in_app_enabled: 'in_app'
        };

        $('.pref-switch').on('change', function () {
            var $input = $(this);
            var $row = $input.closest('tr');
            var field = $input.data('field');
            var payload = {};

            payload[field] = $input.is(':checked') ? 1 : 0;
            $input.prop('disabled', true);

            post($row.data('url'), payload)
                .done(function (response) {
                    if (response.preferences) {
                        $row.find('.pref-switch').each(function () {
                            var key = fields[$(this).data('field')];

                            if (key) {
                                $(this).prop('checked', !!response.preferences[key]);
                            }
                        });
                    }

                    var $status = $row.find('.pref-status');

                    $status.text('Guardado');

                    window.setTimeout(function () {
                        $status.text('');
                    }, 2000);
                })
                .fail(function (xhr) {
                    $input.prop('checked', !$input.is(':checked'));
                    showError(xhr);
                })
                .always(function () {
                    $input.prop('disabled', false);
                });
        });

        $('.pref-reset').on('click', function () {
            var $button = $(this);
            var $row = $button.closest('tr');

            $button.prop('disabled', true);

            post($row.data('url'), { reset: 1 })
                .done(function () {
                    window.location.reload();
                })
                .fail(showError)
                .always(function () {
                    $button.prop('disabled', false);
                });
        });
    });
})(window.jQuery);
