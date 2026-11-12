/**
 * Campana de notificaciones.
 *
 * - Cuenta las notificaciones nuevas y actualiza el número de la cabecera.
 * - Marca como leída la que se abre (y navega si tiene enlace).
 * - Permite silenciar el sonido y dejar solo avisos visuales.
 */
(function ($) {
    'use strict';

    $(function () {
        var $panel = $('#notifications-panel');

        if (!$panel.length) {
            return;
        }

        var contadorUrl = $panel.data('url-contador');
        var leerTodasUrl = $panel.data('url-leer-todas');
        var leerUrl = String($panel.data('url-leer') || '');
        var preferenciasUrl = $panel.data('url-preferencias');
        var token = $panel.data('csrf');
        var sound = String($panel.data('sound')) === '1';

        var $count = $('#notifications-count');
        var $open = $('#notifications-open');
        var $markAll = $('#alert-mark-all');
        var lastCount = parseInt($count.text(), 10) || 0;
        var audioContext = null;

        function beep() {
            if (!sound) {
                return;
            }

            try {
                audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
                var oscillator = audioContext.createOscillator();
                var gain = audioContext.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.value = 0.05;
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.12);
            } catch (error) {
                // Sin audio disponible.
            }
        }

        function updateCount(count) {
            count = Math.max(0, parseInt(count, 10) || 0);
            lastCount = count;
            $count.text(count);
            $open.attr('aria-label', 'Notificaciones: ' + count);
            $markAll.prop('disabled', count === 0);
        }

        function prefValue(name) {
            var $input = $panel.find('.alert-pref[data-field="' + name + '"]');

            return $input.length && $input.is(':checked') ? 1 : 0;
        }

        function post(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, data)
            });
        }

        function status(message) {
            $('#alert-prefs-status').text(message);
        }

        function chatIsOpen() {
            var $chat = $('#chat-panel');

            return $chat.length > 0 && !$chat.prop('hidden');
        }

        function poll() {
            $.getJSON(contadorUrl)
                .done(function (response) {
                    var count = parseInt(response.count, 10) || 0;

                    // El chat ya avisa con su propio sonido cuando está abierto.
                    if (count > lastCount && lastCount > 0 && !chatIsOpen()) {
                        beep();
                    }

                    sound = !!response.sound;
                    updateCount(count);
                });
        }

        $('#alert-list').on('click', '.alert-item__button', function () {
            var $button = $(this);
            var $item = $button.closest('.alert-item');
            var id = $button.data('id');
            var url = $button.data('url');
            var alreadyRead = String($button.attr('data-read')) === '1';

            function go() {
                if (url) {
                    window.location.assign(url);
                }
            }

            if (alreadyRead) {
                go();
                return;
            }

            var target = leerUrl.replace('__ID__', encodeURIComponent(id));

            post(target, {})
                .done(function (response) {
                    $item.removeClass('is-unread');
                    $button.attr('data-read', '1');
                    updateCount(response.count);
                    go();
                })
                .fail(function () {
                    go();
                });
        });

        $markAll.on('click', function () {
            $markAll.prop('disabled', true);

            post(leerTodasUrl, {})
                .done(function () {
                    $('#alert-list .alert-item').removeClass('is-unread');
                    $('#alert-list .alert-item__button').attr('data-read', '1');
                    updateCount(0);
                })
                .fail(function () {
                    $markAll.prop('disabled', false);
                });
        });

        $panel.find('.alert-pref').on('change', function () {
            var $input = $(this);
            var field = $input.data('field');
            var payload = {};

            payload[field] = $input.is(':checked') ? 1 : 0;
            $input.prop('disabled', true);
            status('Guardando...');

            post(preferenciasUrl, payload)
                .done(function (response) {
                    if (response.preferences) {
                        sound = !!response.preferences.sound;
                        $panel.attr('data-sound', sound ? '1' : '0');
                    }

                    status('Preferencias guardadas.');
                })
                .fail(function () {
                    $input.prop('checked', !$input.is(':checked'));
                    status('No se pudieron guardar las preferencias.');
                })
                .always(function () {
                    $input.prop('disabled', false);
                });
        });

        $('#notifications-panel').on('shown.bs.offcanvas', function () {
            poll();
        });

        window.setInterval(poll, 30000);
    });
})(window.jQuery);
