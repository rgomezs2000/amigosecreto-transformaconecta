/**
 * Control de Notificaciones: guarda la configuración global por AJAX.
 */
(function ($) {
    'use strict';

    $(function () {
        var $form = $('#form-notificaciones');

        if (!$form.length) {
            return;
        }

        $form.on('submit', function (event) {
            event.preventDefault();

            var data = {
                _token: $form.find('input[name="_token"]').val(),
                email_enabled: $('#email_enabled').is(':checked') ? 1 : 0,
                push_enabled: $('#push_enabled').is(':checked') ? 1 : 0,
                sound_enabled: $('#sound_enabled').is(':checked') ? 1 : 0,
                in_app_enabled: $('#in_app_enabled').is(':checked') ? 1 : 0
            };

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                dataType: 'json',
                data: data
            }).done(function (response) {
                if (response.redirect) {
                    window.location.assign(response.redirect);
                }
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                var message = response.message || 'No se pudo guardar la configuración.';

                if (window.bootbox) {
                    window.bootbox.alert({ title: 'Aviso', message: message, centerVertical: true });
                } else {
                    window.alert(message);
                }
            });
        });
    });
})(window.jQuery);
