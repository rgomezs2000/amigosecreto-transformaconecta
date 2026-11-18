/**
 * Gran revelación: refresco de la revelación sin recargar la página.
 */
(function ($) {
    'use strict';

    $(function () {
        var $config = $('#revelacion-config');

        if (!$config.length) {
            return;
        }

        var url = $config.attr('data-url-datos');
        var $contenido = $('#revelacion-contenido');

        $('#revelacion-refrescar').on('click', function () {
            var $button = $(this);

            $button.prop('disabled', true);

            $.getJSON(url)
                .done(function (response) {
                    if (response.html) {
                        $contenido.html(response.html);
                    }
                })
                .fail(function (xhr) {
                    var data = (xhr && xhr.responseJSON) || {};

                    window.AppDialog.error(data.message || 'No se pudo actualizar la revelación.');
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });
    });
})(window.jQuery);
