/**
 * Formulario de casos de ayuda y soporte.
 *
 * Se usa en la página del usuario y en el modal «Crear ticket» de la
 * administración. Los campos, la encuesta y los archivos son los mismos; el
 * destino y el comportamiento posterior cambian con data-endpoint y data-mode.
 */
(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    $(function () {
        var $form = $('#reporte-form');

        if (!$form.length) {
            return;
        }

        var endpoint = $form.attr('data-endpoint') || '';
        var mode = $form.attr('data-mode') || 'user';
        var csrf = $form.find('input[name="_token"]').val() || '';
        var files = [];
        var confirmationElement = document.getElementById('modal-confirmar-reporte');
        var ticketElement = document.getElementById('modal-ticket-creado');
        var confirmationModal = confirmationElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(confirmationElement)
            : null;
        var ticketModal = ticketElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(ticketElement)
            : null;

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        function formatSize(bytes) {
            var size = parseInt(bytes, 10) || 0;

            if (size >= 1048576) {
                return (size / 1048576).toFixed(1).replace('.', ',') + ' MB';
            }

            if (size >= 1024) {
                return Math.round(size / 1024) + ' KB';
            }

            return size + ' B';
        }

        function setStatus(message, kind) {
            var $status = $('#reporte-status').text(message || '');

            if (kind) {
                $status.attr('data-kind', kind);
            } else {
                $status.removeAttr('data-kind');
            }
        }

        function toggleSuggestion() {
            $('#reporte-sugerencia-wrap').prop('hidden', $('#reporte-tipo').val() !== 'sugerencia');
        }

        function renderFiles() {
            var $list = $('#reporte-file-list').empty();

            files.forEach(function (file, index) {
                var icon = file.type.indexOf('image/') === 0
                    ? 'fa-image'
                    : (file.type.indexOf('video/') === 0
                        ? 'fa-video'
                        : (file.type.indexOf('audio/') === 0 ? 'fa-headphones' : 'fa-file-lines'));

                $list.append(
                    '<li class="chat-report-file">' +
                    '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>' +
                    '<span class="chat-report-file__name">' + escapeHtml(file.name) + '</span>' +
                    '<span class="chat-report-file__size">' + formatSize(file.size) + '</span>' +
                    '<button type="button" class="chat-report-file__remove" data-index="' + index +
                    '" aria-label="Quitar ' + escapeHtml(file.name) + '">' +
                    '<i class="fa-solid fa-xmark" aria-hidden="true"></i></button>' +
                    '</li>'
                );
            });
        }

        function addFiles(fileList) {
            var incoming = Array.prototype.slice.call(fileList || []);
            var error = '';

            incoming.forEach(function (file) {
                if (files.length >= 10) {
                    error = 'Puedes adjuntar hasta 10 archivos.';
                    return;
                }

                if (file.size > 52428800) {
                    error = 'Cada archivo puede pesar hasta 50 MB.';
                    return;
                }

                var duplicate = files.some(function (current) {
                    return current.name === file.name
                        && current.size === file.size
                        && current.lastModified === file.lastModified;
                });

                if (!duplicate) {
                    files.push(file);
                }
            });

            if (error) {
                setStatus(error, 'error');
            }

            renderFiles();
        }

        function clearRatings() {
            $('#reporte-form [data-reporte-cara]')
                .removeClass('is-selected')
                .attr('aria-pressed', 'false');
            $('#reporte-form input[name^="ratings["]').val('');
            $('.reporte-encuesta__cualitativa').text('Sin valorar');
        }

        function resetForm() {
            $form[0].reset();
            files = [];
            renderFiles();
            clearRatings();
            toggleSuggestion();
            setStatus('');
        }

        function selectedRatings() {
            var ratings = {};

            $form.find('input[name^="ratings["]').each(function () {
                var name = $(this).attr('name') || '';
                var aspect = name.replace('ratings[', '').replace(']', '');
                var score = parseInt($(this).val(), 10) || 0;

                if (aspect && score >= 1 && score <= 5) {
                    ratings[aspect] = score;
                }
            });

            return ratings;
        }

        function validateForm() {
            if (!$('#reporte-tipo').val()) {
                setStatus('Selecciona el tipo de reporte.', 'error');
                return false;
            }

            if (!$.trim($('#reporte-motivo').val())) {
                setStatus('Escribe el motivo del reporte.', 'error');
                return false;
            }

            if (!$.trim($('#reporte-mensaje').val())) {
                setStatus('Escribe el mensaje detallado del reporte.', 'error');
                return false;
            }

            if ($('#reporte-tipo').val() === 'sugerencia') {
                if (!$.trim($('#reporte-sugerencia').val())) {
                    setStatus('Escribe el texto de la sugerencia.', 'error');
                    return false;
                }

                var ratings = selectedRatings();

                if (Object.keys(ratings).length < 6) {
                    setStatus('Valora los seis aspectos de la sugerencia.', 'error');
                    return false;
                }
            }

            setStatus('');
            return true;
        }

        function buildFormData() {
            var data = new FormData();
            data.append('_token', csrf);
            data.append('type', $('#reporte-tipo').val());
            data.append('reason', $.trim($('#reporte-motivo').val()));
            data.append('message', $.trim($('#reporte-mensaje').val()));

            if ($('#reporte-tipo').val() === 'sugerencia') {
                data.append('suggestion', $.trim($('#reporte-sugerencia').val()));

                var ratings = selectedRatings();

                Object.keys(ratings).forEach(function (aspect) {
                    data.append('ratings[' + aspect + ']', ratings[aspect]);
                });
            }

            ($('#reporte-links').val() || '').split(/\r?\n/).forEach(function (line) {
                var link = $.trim(line);

                if (link !== '') {
                    data.append('links[]', link);
                }
            });

            files.forEach(function (file) {
                data.append('files[]', file);
            });

            if ($('#reporte-context-url').val()) {
                data.append('context_url', $('#reporte-context-url').val());
            }

            if ($('#reporte-context-label').val()) {
                data.append('context_label', $('#reporte-context-label').val());
            }

            return data;
        }

        function sendForm() {
            if (!endpoint) {
                return;
            }

            var $button = $('#reporte-confirmar');
            window.App.processing($button, true, 'Procesando...');
            setStatus('Enviando...', 'info');

            $.ajax({
                url: endpoint,
                method: 'POST',
                data: buildFormData(),
                processData: false,
                contentType: false,
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                }
            }).done(function (response) {
                window.App.processing($button, false);

                if (confirmationModal) {
                    confirmationModal.hide();
                }

                if (mode === 'admin') {
                    var createModal = document.getElementById('modal-moderacion-nuevo-ticket');

                    if (createModal) {
                        window.bootstrap.Modal.getOrCreateInstance(createModal).hide();
                    }
                }

                var ticket = (response && response.ticket) || '—';
                var report = (response && response.report) || {};

                $('#reporte-ticket-numero').text(ticket);
                $('#reporte-ticket-texto').text(
                    mode === 'admin'
                        ? 'Tipo: ' + (report.type_label || 'Caso') + '. El caso quedó registrado en la bandeja.'
                        : 'Tipo: ' + (report.type_label || 'Caso') + '. Puedes seguirlo desde la pestaña Seguimiento.'
                );
                $('#reporte-ticket-seguimiento').prop('hidden', mode === 'admin');

                resetForm();

                if (ticketModal) {
                    ticketModal.show();
                }

                document.dispatchEvent(new CustomEvent('reporte:creado', {
                    detail: response || {}
                }));
            }).fail(function (xhr) {
                window.App.processing($button, false);

                if (confirmationModal) {
                    confirmationModal.hide();
                }

                var message = (xhr.responseJSON || {}).message || 'No se pudo crear el ticket.';
                setStatus(message, 'error');
            });
        }

        $('#reporte-tipo').on('change', toggleSuggestion);
        toggleSuggestion();

        $form.on('click', '[data-reporte-cara]', function () {
            var aspect = $(this).attr('data-aspecto');
            var score = $(this).attr('data-score');

            $form.find('[data-reporte-cara][data-aspecto="' + aspect + '"]')
                .removeClass('is-selected')
                .attr('aria-pressed', 'false');
            $(this).addClass('is-selected').attr('aria-pressed', 'true');
            $('#reporte-rating-' + aspect).val(score);
            $('#reporte-rating-label-' + aspect).text($(this).find('span').text());
        });

        $('#reporte-dropzone').on('click', function () {
            $('#reporte-files').trigger('click');
        }).on('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                $('#reporte-files').trigger('click');
            }
        }).on('dragover dragenter', function (event) {
            event.preventDefault();
            $(this).addClass('is-dragging');
        }).on('dragleave', function () {
            $(this).removeClass('is-dragging');
        }).on('drop', function (event) {
            event.preventDefault();
            $(this).removeClass('is-dragging');
            addFiles(event.originalEvent.dataTransfer.files);
        });

        $('#reporte-files').on('change', function () {
            addFiles(this.files);
            this.value = '';
        });

        $('#reporte-file-list').on('click', '.chat-report-file__remove', function () {
            files.splice(parseInt($(this).attr('data-index'), 10), 1);
            renderFiles();
        });

        $('#reporte-limpiar').on('click', function () {
            resetForm();
        });

        $('#reporte-crear').on('click', function () {
            if (!validateForm()) {
                return;
            }

            if (confirmationModal) {
                confirmationModal.show();
            }
        });

        $('#reporte-confirmar').on('click', function () {
            sendForm();
        });

        $('#reporte-ticket-seguimiento').on('click', function () {
            if (ticketModal) {
                ticketModal.hide();
            }

            $('#contacto-tab-seguimiento').trigger('click');
        });

        $('#modal-moderacion-nuevo-ticket').on('show.bs.modal', function () {
            resetForm();
        });

        try {
            if (mode === 'user' && document.referrer) {
                var referrer = new URL(document.referrer);

                if (referrer.origin === window.location.origin
                    && referrer.pathname !== window.location.pathname) {
                    $('#reporte-context-url').val(referrer.href);
                    $('#reporte-context-label').val(referrer.pathname);
                }
            }
        } catch (error) {
            // Sin referrer válido: el caso queda sin módulo asociado.
        }
    });
})(window.jQuery);
