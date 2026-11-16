/**
 * Contacto y soporte del usuario: seguimiento de casos y chat del ticket.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#contacto-config');

        if (!$config.length) {
            return;
        }

        var csrf = $config.attr('data-csrf') || '';
        var detalleUrl = $config.attr('data-url-detalle') || '';
        var mensajesUrl = $config.attr('data-url-mensajes') || '';
        var currentReportId = null;

        function escapeText(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        /* Pestañas */
        function activateTab(name) {
            $('.moderacion-tab').each(function () {
                var active = $(this).attr('data-tab') === name;

                $(this).toggleClass('is-active', active)
                    .attr('aria-selected', active ? 'true' : 'false');
            });

            $('.contacto-panel').each(function () {
                $(this).prop('hidden', $(this).attr('data-panel') !== name);
            });

            if (name === 'seguimiento' && seguimientoTable) {
                seguimientoTable.columns.adjust();
            }
        }

        $('.moderacion-tab').on('click', function () {
            activateTab($(this).attr('data-tab'));
        });

        /* Filtros del seguimiento */
        function filterData() {
            return {
                filter_type: $('#seguimiento-filter-type').val() || 'all',
                date_from: $('#seguimiento-filter-from').val() || '',
                date_to: $('#seguimiento-filter-to').val() || '',
                case_type: $('#seguimiento-filter-case-type').val() || '',
                status: $('#seguimiento-filter-status').val() || ''
            };
        }

        function toggleFilter() {
            var type = $('#seguimiento-filter-type').val() || 'all';

            $('#seguimiento-filter-date-wrap, #seguimiento-filter-to-wrap')
                .toggleClass('d-none', type !== 'date');
            $('#seguimiento-filter-case-type-wrap').toggleClass('d-none', type !== 'case_type');
            $('#seguimiento-filter-status-wrap').toggleClass('d-none', type !== 'status');
        }

        var seguimientoTable = $('#seguimiento-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: $config.attr('data-url-datos'),
                type: 'GET',
                data: function (data) {
                    $.extend(data, filterData());
                }
            },
            columns: [
                { data: 'ticket', className: 'roles-id' },
                { data: 'type_html' },
                { data: 'reason' },
                { data: 'status_html' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [4] }
            ],
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                emptyTable: 'Todavía no has creado casos.',
                info: 'Mostrando _START_ a _END_ de _TOTAL_',
                infoEmpty: 'Sin casos',
                infoFiltered: '(filtrado de _MAX_)',
                lengthMenu: 'Mostrar _MENU_',
                loadingRecords: 'Cargando casos...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron casos',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            }
        });

        $('#seguimiento-search').on('input', function () {
            seguimientoTable.search(this.value).draw();
        });

        $('#seguimiento-filter-type').on('change', function () {
            toggleFilter();
            seguimientoTable.ajax.reload(null, true);
        });

        $('#seguimiento-filter-from, #seguimiento-filter-to, #seguimiento-filter-case-type, #seguimiento-filter-status')
            .on('change', function () {
                seguimientoTable.ajax.reload(null, true);
            });

        $('#seguimiento-clear').on('click', function () {
            $('#seguimiento-search').val('');
            $('#seguimiento-filter-type').val('all');
            $('#seguimiento-filter-from').val('');
            $('#seguimiento-filter-to').val('');
            $('#seguimiento-filter-case-type').val('');
            $('#seguimiento-filter-status').val('pendiente');
            toggleFilter();
            seguimientoTable.search('').ajax.reload(null, true);
        });

        toggleFilter();

        /* Modal de seguimiento */
        var modalElement = document.getElementById('modal-seguimiento');
        var modal = modalElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(modalElement)
            : null;

        function resetDetail() {
            currentReportId = null;
            $('#seguimiento-ticket').text('—');
            $('#seguimiento-type').text('—');
            $('#seguimiento-status').text('—');
            $('#seguimiento-date').text('—');
            $('#seguimiento-reason').text('—');
            $('#seguimiento-message').text('—');
            $('#seguimiento-chat-thread').empty();
            $('#seguimiento-chat-form').prop('hidden', true);
            $('#seguimiento-chat-readonly').prop('hidden', true);
            $('#seguimiento-chat-body').val('');
            $('#seguimiento-chat-error').text('').removeAttr('data-kind');
            $('#seguimiento-media, #seguimiento-audios, #seguimiento-documentos, #seguimiento-links').empty();
            $('#seguimiento-sugerencia-text').text('');
            $('#seguimiento-ratings').empty();
            $('#seguimiento-tab-chat-wrap').prop('hidden', true);
            $('#seguimiento-tab-sugerencia-wrap').prop('hidden', true);

            var first = document.getElementById('seguimiento-tab-detalles');

            if (first && window.bootstrap && window.bootstrap.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(first).show();
            }
        }

        function renderChatMessages(messages) {
            var $thread = $('#seguimiento-chat-thread').empty();

            if (!messages.length) {
                $thread.html('<p class="seguimiento-chat__empty">Todavía no hay mensajes en este caso.</p>');
                return;
            }

            messages.forEach(function (message) {
                $thread.append(
                    '<article class="seguimiento-chat__message' + (message.is_admin ? ' is-admin' : '') + '">' +
                    '<p class="seguimiento-chat__meta">' + escapeText(message.author) + ' · ' + escapeText(message.date) + '</p>' +
                    '<p class="seguimiento-chat__body">' + escapeText(message.body) + '</p>' +
                    '</article>'
                );
            });
        }

        function renderAttachmentMedia(items) {
            var $box = $('#seguimiento-media').empty();

            if (!items.length) {
                $box.html('<p class="seguimiento-attachments__empty">Sin fotos ni videos.</p>');
                return;
            }

            items.forEach(function (item) {
                var isVideo = item.kind === 'video';
                var badge = item.is_evidence ? '<span class="moderacion-attachment-evidence">Evidencia</span>' : '';
                var media = isVideo
                    ? '<video src="' + escapeText(item.url) + '" muted preload="metadata"></video>'
                    : '<img src="' + escapeText(item.url) + '" alt="' + escapeText(item.name) + '">';

                $box.append(
                    '<a class="seguimiento-attachment-media" href="' + escapeText(item.url) + '" target="_blank" rel="noopener noreferrer">' +
                    media + badge +
                    '<span class="seguimiento-attachment-media__name">' + escapeText(item.name) + '</span>' +
                    '</a>'
                );
            });
        }

        function renderAttachmentAudios(items) {
            var $box = $('#seguimiento-audios').empty();

            if (!items.length) {
                $box.html('<p class="seguimiento-attachments__empty">Sin audios.</p>');
                return;
            }

            items.forEach(function (item) {
                $box.append(
                    '<div class="seguimiento-attachment">' +
                    '<i class="fa-solid fa-headphones" aria-hidden="true"></i>' +
                    '<span>' + escapeText(item.name) + '</span>' +
                    '<audio controls preload="none" src="' + escapeText(item.url) + '"></audio>' +
                    '</div>'
                );
            });
        }

        function renderAttachmentDocuments(items) {
            var $box = $('#seguimiento-documentos').empty();

            if (!items.length) {
                $box.html('<p class="seguimiento-attachments__empty">Sin documentos.</p>');
                return;
            }

            items.forEach(function (item) {
                $box.append(
                    '<a class="seguimiento-attachment" href="' + escapeText(item.url) + '" target="_blank" rel="noopener noreferrer">' +
                    '<i class="fa-regular fa-file-lines" aria-hidden="true"></i>' +
                    '<span>' + escapeText(item.name) + '</span>' +
                    '<small>' + escapeText(item.size_label) + '</small>' +
                    '</a>'
                );
            });
        }

        function renderAttachmentLinks(items) {
            var $box = $('#seguimiento-links').empty();

            if (!items.length) {
                $box.html('<p class="seguimiento-attachments__empty">Sin enlaces.</p>');
                return;
            }

            items.forEach(function (item) {
                $box.append(
                    '<a class="seguimiento-attachment" href="' + escapeText(item.url) + '" target="_blank" rel="noopener noreferrer">' +
                    '<i class="fa-solid fa-link" aria-hidden="true"></i>' +
                    '<span>' + escapeText(item.url) + '</span>' +
                    '</a>'
                );
            });
        }

        var FACE_ICONS = {
            1: 'fa-face-frown',
            2: 'fa-face-meh',
            3: 'fa-face-meh-blank',
            4: 'fa-face-smile',
            5: 'fa-face-grin-stars'
        };
        var LABEL_SCORES = {
            'Deficiente': 1,
            'Aceptable': 2,
            'Regular': 3,
            'Bueno': 4,
            'Excelente': 5
        };

        function renderRatings(ratings) {
            var $box = $('#seguimiento-ratings').empty();

            ratings.forEach(function (rating) {
                var score = LABEL_SCORES[rating.qualitative] || 0;
                var icon = FACE_ICONS[score] || 'fa-face-meh-blank';

                $box.append(
                    '<div class="seguimiento-rating">' +
                    '<span class="seguimiento-rating__aspect">' + escapeText(rating.aspect_label) + '</span>' +
                    '<span class="seguimiento-rating__face reporte-cara--' + score + '">' +
                    '<i class="fa-solid ' + icon + '" aria-hidden="true"></i> ' +
                    escapeText(rating.qualitative) + '</span>' +
                    '</div>'
                );
            });
        }

        function renderDetail(data) {
            var report = data.report || {};
            var chat = data.chat || {};
            var attachments = data.attachments || {};
            var links = data.links || [];

            currentReportId = report.id;

            $('#modal-seguimiento-title').text('Seguimiento del caso Nº: ' + (report.ticket || report.id));
            $('#seguimiento-ticket').text(report.ticket || report.id || '—');
            $('#seguimiento-type').text(report.type_label || '—');
            $('#seguimiento-status').html(report.status_label
                ? '<span class="moderacion-status moderacion-status--' + report.status + '">' + escapeText(report.status_label) + '</span>'
                : '—');
            $('#seguimiento-date').text(report.date || '—');
            $('#seguimiento-reason').text(report.reason || '—');
            $('#seguimiento-message').text(report.message || '—');

            renderAttachmentMedia(attachments.media || []);
            renderAttachmentAudios(attachments.audios || []);
            renderAttachmentDocuments(attachments.documents || []);
            renderAttachmentLinks(links);

            var isSuggestion = report.type === 'sugerencia';

            $('#seguimiento-tab-chat-wrap').prop('hidden', isSuggestion || !chat.conversation_id);
            $('#seguimiento-tab-sugerencia-wrap').prop('hidden', !isSuggestion);

            if (isSuggestion) {
                $('#seguimiento-sugerencia-text').text(report.suggestion || '—');
                renderRatings(data.ratings || []);
            }

            if (!isSuggestion && chat.conversation_id) {
                renderChatMessages(chat.messages || []);
                $('#seguimiento-chat-form').prop('hidden', Boolean(chat.read_only));
                $('#seguimiento-chat-readonly').prop('hidden', !chat.read_only);
            }
        }

        $('#seguimiento-table').on('click', '.contacto-ver', function () {
            var $row = $(this).closest('tr');
            var row = seguimientoTable.row($row).data();

            if (!row) {
                return;
            }

            resetDetail();
            currentReportId = row.id;

            if (modal) {
                modal.show();
            }

            $.getJSON(detalleUrl.replace('__ID__', encodeURIComponent(row.id)))
                .done(function (response) {
                    renderDetail(response || {});
                })
                .fail(function (xhr) {
                    $('#seguimiento-reason').text((xhr.responseJSON || {}).message || 'No se pudo cargar el caso.');
                });
        });

        $('#seguimiento-chat-send').on('click', function () {
            if (!currentReportId) {
                return;
            }

            var body = $.trim($('#seguimiento-chat-body').val() || '');
            var $error = $('#seguimiento-chat-error').text('').removeAttr('data-kind');

            if (body === '') {
                $error.text('Escribe un mensaje.').attr('data-kind', 'error');
                return;
            }

            $.ajax({
                url: mensajesUrl.replace('__ID__', encodeURIComponent(currentReportId)),
                method: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                data: { body: body }
            }).done(function (response) {
                var message = (response && response.message) || null;

                if (message) {
                    $('#seguimiento-chat-thread .seguimiento-chat__empty').remove();
                    $('#seguimiento-chat-thread').append(
                        '<article class="seguimiento-chat__message">' +
                        '<p class="seguimiento-chat__meta">' + escapeText(message.author) + ' · ' + escapeText(message.date) + '</p>' +
                        '<p class="seguimiento-chat__body">' + escapeText(message.body) + '</p>' +
                        '</article>'
                    );
                }

                $('#seguimiento-chat-body').val('');
                $error.text('Mensaje enviado.').attr('data-kind', 'success');
            }).fail(function (xhr) {
                $error.text((xhr.responseJSON || {}).message || 'No se pudo enviar el mensaje.').attr('data-kind', 'error');
            });
        });

        document.addEventListener('reporte:creado', function () {
            seguimientoTable.ajax.reload(null, true);
        });
    });
})(window.jQuery);
