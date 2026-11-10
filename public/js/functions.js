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

    window.App = App;
})(window);
