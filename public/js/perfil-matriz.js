/**
 * Matriz del perfil: DataTable de solo lectura (módulos, grupo y permisos)
 * con búsqueda y filtros: todos los módulos, con permisos o por permiso.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#perfil-matriz-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var $search = $('#perfil-matriz-search');
        var $filterType = $('#perfil-matriz-filter-type');
        var $filterPermission = $('#perfil-matriz-filter-permission');
        var $filterPermissionWrap = $('#perfil-matriz-filter-permission-wrap');
        var searchTimer = null;

        var table = $('#perfil-matriz-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET',
                data: function (params) {
                    params.filter_type = $filterType.val();
                    params.filter_permission = $filterPermission.val() || 0;
                }
            },
            columns: [
                { data: 'module' },
                { data: 'group' },
                { data: 'permissions', orderable: false }
            ],
            order: [[1, 'asc'], [0, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ módulos',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ módulos',
                infoEmpty: 'Sin módulos',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron módulos',
                emptyTable: 'No tienes módulos asignados',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            }
        });

        $search.on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        function togglePermissionFilter() {
            $filterPermissionWrap.toggleClass('d-none', $filterType.val() !== 'permission');
        }

        $filterType.on('change', function () {
            togglePermissionFilter();

            if ($filterType.val() !== 'permission') {
                $filterPermission.prop('selectedIndex', 0);
            }

            table.ajax.reload();
        });

        $filterPermission.on('change', function () {
            table.ajax.reload();
        });

        $('#perfil-matriz-clear').on('click', function () {
            $search.val('');
            $filterType.val('all');
            $filterPermission.prop('selectedIndex', 0);
            togglePermissionFilter();
            table.search('').draw();
            $search.trigger('focus');
        });
    });
})(window.jQuery);
