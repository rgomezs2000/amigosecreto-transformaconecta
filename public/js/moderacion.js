/**
 * Moderación: listado de reportes del chat en modo solo lectura.
 *
 * No crea, edita ni elimina nada: únicamente muestra el detalle y enlaza al
 * chat para que el administrador revise la conversación.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#moderacion-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var modalElement = document.getElementById('modal-moderacion-detail');
        var modal = modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;

        var table = $('#moderacion-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET'
            },
            columns: [
                { data: 'date' },
                { data: 'reporter' },
                { data: 'author' },
                { data: 'reason' },
                { data: 'conversation' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [4, 5] }
            ],
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                emptyTable: 'No hay reportes registrados.',
                info: 'Mostrando _START_ a _END_ de _TOTAL_',
                infoEmpty: 'Sin reportes',
                infoFiltered: '(filtrado de _MAX_)',
                lengthMenu: 'Mostrar _MENU_',
                loadingRecords: 'Cargando reportes...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron reportes',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            }
        });

        $('#moderacion-search').on('input', function () {
            table.search(this.value).draw();
        });

        $('#moderacion-clear').on('click', function () {
            $('#moderacion-search').val('');
            table.search('').draw();
        });

        $('#moderacion-table').on('click', '.moderacion-detail', function () {
            var row = table.row($(this).closest('tr')).data();

            if (!row) {
                return;
            }

            $('#moderacion-detail-date').text(row.date || '—');
            $('#moderacion-detail-reporter').text(row.reporter_raw || row.reporter || '—');
            $('#moderacion-detail-author').text(row.author_raw || row.author || '—');
            $('#moderacion-detail-conversation').text(row.conversation_raw || row.conversation || '—');
            $('#moderacion-detail-reason').text(row.reason_full || row.reason || '—');
            $('#moderacion-detail-message').text(row.message_full || row.message || '—');

            var $chat = $('#moderacion-detail-chat');

            if (row.conversation_url) {
                $chat.attr('href', row.conversation_url).prop('hidden', false);
            } else {
                $chat.prop('hidden', true);
            }

            if (modal) {
                modal.show();
            }
        });
    });
})(window.jQuery);
