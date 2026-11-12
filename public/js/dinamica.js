/**
 * Restablecer dinámica: un botón, un modal y el avance del proceso batch.
 *
 * Al confirmar, el servidor lanza `dinamica:reiniciar` y devuelve un
 * identificador de ejecución. Aquí se consulta el progreso hasta que el
 * proceso termina bien o falla, mostrando la bitácora en el mismo modal.
 */
(function ($) {
    'use strict';

    $(function () {
        var $config = $('#dinamica-config');

        if (!$config.length) {
            return;
        }

        var startUrl = $config.attr('data-url-iniciar');
        var progressUrl = $config.attr('data-url-progreso');
        var token = $config.attr('data-csrf');

        var $modalElement = $('#modal-dinamica');
        var $confirm = $('#dinamica-confirm');
        var $running = $('#dinamica-running');
        var $status = $('#dinamica-status');
        var $bar = $('#dinamica-progressbar');
        var $progress = $('#dinamica-progress');
        var $step = $('#dinamica-step');
        var $log = $('#dinamica-log');
        var $finished = $('#dinamica-finished');
        var $start = $('#dinamica-start');

        var timer = null;
        var run = null;
        var lines = 0;
        var polling = false;
        var startedAt = 0;

        // Si el proceso no da señales de vida en este tiempo, se avisa en vez
        // de dejar el modal bloqueado para siempre.
        var STALL_MS = 45000;

        function modal() {
            var element = document.getElementById('modal-dinamica');

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        function setProgress(percent) {
            var value = Math.max(0, Math.min(100, percent));

            $progress.css('width', value + '%');
            $bar.attr('aria-valuenow', value);
        }

        function appendLine(text) {
            var current = $log.text();

            $log.text(current ? current + '\n' + text : text);
            $log.scrollTop($log[0].scrollHeight);
        }

        function renderLines(newLines) {
            if (!newLines || !newLines.length) {
                return;
            }

            for (var i = lines; i < newLines.length; i++) {
                appendLine('[' + (newLines[i].at || '') + '] ' + (newLines[i].text || ''));
            }

            lines = newLines.length;
        }

        function resetModal() {
            run = null;
            lines = 0;
            startedAt = 0;

            $confirm.removeAttr('hidden');
            $running.attr('hidden', true);
            $finished.attr('hidden', true);
            $start.prop('disabled', false);
            $status.text('Preparando el proceso…');
            $step.text('');
            $log.text('');
            $progress.removeClass('bg-danger bg-success');
            setProgress(0);
        }

        function stopPolling() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }

            polling = false;
        }

        function finish(state, message) {
            stopPolling();

            $finished.removeAttr('hidden');

            if (state === 'done') {
                setProgress(100);
                $progress.addClass('bg-success');
                $status.text('Restablecimiento completado. El sistema quedó en cero.');
                $step.text('Ya puedes crear un evento nuevo desde Eventos.');
                return;
            }

            $progress.addClass('bg-danger');
            $status.text(message || 'El restablecimiento falló. No se eliminó nada.');
            $step.text('Revisa la bitácora o el archivo de log.');
            $start.prop('disabled', false);
        }

        function poll() {
            if (!run || polling) {
                return;
            }

            polling = true;

            $.ajax({
                url: progressUrl,
                method: 'GET',
                dataType: 'json',
                data: { run: run }
            }).done(function (response) {
                var state = response && response.estado ? response.estado : null;

                if (!state) {
                    return;
                }

                setProgress(state.percent || 0);

                if (state.message) {
                    $status.text(state.message);
                }

                if (state.total) {
                    $step.text('Paso ' + (state.step || 0) + ' de ' + state.total);
                }

                renderLines(state.lines);

                if (state.status === 'done' || state.status === 'failed') {
                    finish(state.status);
                    return;
                }

                // Proceso sin señales de vida: se libera el modal para que se
                // pueda cerrar y volver a intentar.
                if (!state.step && startedAt && (Date.now() - startedAt) > STALL_MS) {
                    finish('failed', 'El proceso no arrancó. Vuelve a intentarlo.');
                }
            }).fail(function () {
                // El proceso sigue escribiendo: se reintenta en el próximo ciclo.
            }).always(function () {
                polling = false;
            });
        }

        $('#dinamica-open').on('click', function () {
            resetModal();
            modal().show();
        });

        $start.on('click', function () {
            var $button = $(this);

            $button.prop('disabled', true);
            $confirm.attr('hidden', true);
            $running.removeAttr('hidden');
            $status.text('Iniciando el proceso…');

            $.ajax({
                url: startUrl,
                method: 'POST',
                dataType: 'json',
                data: { _token: token }
            }).done(function (response) {
                run = response.run;
                lines = 0;
                startedAt = Date.now();

                if (!run) {
                    $status.text('No se pudo iniciar el proceso.');
                    finish('failed');
                    return;
                }

                timer = window.setInterval(poll, 700);
                poll();
            }).fail(function (xhr) {
                var data = xhr.responseJSON || {};

                $status.text(data.message || 'No se pudo iniciar el proceso.');
                finish('failed');
            });
        });

        // El modal no se cierra mientras el proceso está en marcha: cerrarlo
        // dejaría el borrado sin supervisión.
        $modalElement.on('hide.bs.modal', function (event) {
            if (timer) {
                event.preventDefault();
            }
        });

        $modalElement.on('hidden.bs.modal', function () {
            if (!timer) {
                resetModal();
            }
        });
    });
})(jQuery);
