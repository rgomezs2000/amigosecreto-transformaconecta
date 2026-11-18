/**
 * Helpers generales de la aplicación.
 *
 * Se cargan antes que scripts.js y no ejecutan nada por sí solos.
 * Dependen de jQuery, que ya está disponible en el layout.
 */
(function (window) {
    'use strict';

    var App = window.App || {};
    var $ = window.jQuery;

    if (!$) {
        return;
    }

    /**
     * Escribe un mensaje de estado y le asigna el tipo (info | error).
     *
     * @param {string|Element|jQuery} target Selector, elemento o jQuery.
     * @param {string} message Texto a mostrar.
     * @param {string} [kind] Tipo de mensaje.
     */
    App.setStatus = function (target, message, kind) {
        var $box = target && target.jquery ? target : $(target);

        if (!$box.length) {
            return;
        }

        $box.text(message || '');
        $box.attr('data-kind', kind || 'info');
    };

    /**
     * Decodifica el payload de un JWT sin validar la firma.
     * La validación real del token de Google se hace en el servidor.
     *
     * @param {string} token
     * @returns {object|null}
     */
    App.decodeJwt = function (token) {
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
    };

    /**
     * Bloquea un botón mientras se ejecuta una operación.
     *
     * @param {string|Element|jQuery} target
     * @param {boolean} active
     * @param {string} [label]
     */
    App.processing = function (target, active, label) {
        var $button = target && target.jquery ? target : $(target);

        if (!$button.length) {
            return;
        }

        var count = parseInt($button.data('processing-count') || 0, 10);

        if (active) {
            if (count === 0 || !$button.data('processing-original')) {
                $button.data('processing-original', $button.html());
            }

            count += 1;
            $button.data('processing-count', count);

            $button
                .prop('disabled', true)
                .attr('aria-busy', 'true')
                .html(
                    '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>' +
                    '<span>' + (label || 'Procesando...') + '</span>'
                );

            return;
        }

        count = Math.max(0, count - 1);
        $button.data('processing-count', count);

        if (count > 0) {
            return;
        }

        var original = $button.data('processing-original');

        if (original) {
            $button.html(original);
        }

        $button
            .prop('disabled', false)
            .removeAttr('aria-busy')
            .removeData('processing-original')
            .removeData('processing-count');
    };

    /**
     * Aplica automáticamente el estado de procesamiento al botón que originó
     * una petición AJAX. Los botones que ya lo manejan manualmente pueden
     * marcarse con data-processing-manual="true".
     */
    App.initAutoProcessing = function () {
        var active = null;
        var clearTimer = null;

        function eligible(element) {
            if (!element || !document.documentElement.contains(element)) {
                return false;
            }

            if (element.hasAttribute('data-processing-manual')
                || element.closest('[data-processing-manual]')) {
                return false;
            }

            return element.tagName === 'BUTTON';
        }

        function remember(element) {
            if (!eligible(element)) {
                return;
            }

            active = element;
            window.clearTimeout(clearTimer);
            clearTimer = window.setTimeout(function () {
                active = null;
            }, 1500);
        }

        $(document).on('click', 'button, input[type="submit"]', function () {
            remember(this);
        });

        $(document).on('submit', 'form', function () {
            var button = this.querySelector('button[type="submit"], input[type="submit"]');

            if (button) {
                remember(button);
            }
        });

        // Fase de captura: el botón queda registrado antes de que el handler
        // del módulo dispare la petición AJAX.
        document.addEventListener('click', function (event) {
            var button = event.target instanceof Element
                ? event.target.closest('button')
                : null;

            if (button) {
                remember(button);
            }
        }, true);

        document.addEventListener('submit', function (event) {
            var button = event.target.querySelector
                ? event.target.querySelector('button[type="submit"]')
                : null;

            if (button) {
                remember(button);
            }
        }, true);

        $(document).ajaxSend(function (event, xhr, settings) {
            if (settings.global === false || !eligible(active)) {
                return;
            }

            $(xhr).data('auto-processing-element', active);
            App.processing(active, true, 'Procesando...');
        });

        $(document).ajaxComplete(function (event, xhr) {
            var element = $(xhr).data('auto-processing-element');

            if (element) {
                App.processing(element, false);
                $(xhr).removeData('auto-processing-element');
            }

            active = null;
            window.clearTimeout(clearTimer);
        });
    };

    window.App = App;
})(window);
