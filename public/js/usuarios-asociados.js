/**
 * Usuarios asociados: consulta de solo lectura de las parejas del evento.
 *
 * Igual que Participantes: la tabla se pagina en el servidor, el evento por
 * defecto es el actual y en curso, la búsqueda filtra por usuario o amigo
 * secreto y Limpiar restablece todo (búsqueda vacía y evento actual).
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#usuarios-asociados-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var defaultEvent = String($config.attr('data-evento-actual') || '');

        var $tabla = $('#usuarios-asociados-table');

        if (!$tabla.length) {
            return;
        }

        function eventoActual() {
            return $('#usuarios-asociados-evento').val() || '';
        }

        var table = $tabla.DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET',
                data: function (d) {
                    d.evento = eventoActual();
                }
            },
            columns: [
                { data: 'id', className: 'roles-id' },
                { data: 'nombre', className: 'roles-name' },
                { data: 'usuario', className: 'roles-name' },
                { data: 'amigo', className: 'roles-name' }
            ],
            columnDefs: [
                { orderable: false, targets: [0] }
            ],
            order: [[1, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ usuarios asociados',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ usuarios asociados',
                infoEmpty: 'Sin usuarios asociados',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron usuarios asociados',
                emptyTable: 'No hay usuarios asociados en este evento. Las parejas aparecen cuando se realiza el sorteo.',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            },
            drawCallback: function (settings) {
                var json = (settings && settings.json) || {};

                if (typeof json.aviso_html === 'string') {
                    $('#usuarios-asociados-aviso').html(json.aviso_html);
                }
            }
        });

        var searchTimer = null;

        $('#usuarios-asociados-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#usuarios-asociados-evento').on('change', function () {
            table.ajax.reload(null, true);
        });

        $('#usuarios-asociados-clear').on('click', function () {
            $('#usuarios-asociados-search').val('');

            if (defaultEvent) {
                $('#usuarios-asociados-evento').val(defaultEvent);
            }

            table.search('').ajax.reload(null, true);
            $('#usuarios-asociados-search').trigger('focus');
        });
    });
})(window.jQuery);
