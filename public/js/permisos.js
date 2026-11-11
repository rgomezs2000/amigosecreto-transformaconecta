/**
 * Permisos: mismo patrón que Roles (DataTable server-side, búsqueda,
 * modales, AJAX, confirmaciones y selección múltiple).
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#permisos-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var crearUrl = $config.attr('data-url-crear');
        var permisoBase = $config.attr('data-url-permiso');
        var eliminarVariosUrl = $config.attr('data-url-eliminar-varios');
        var indexUrl = $config.attr('data-url-index');
        var token = $('#permisos-csrf').val();

        var formModalElement = document.getElementById('modal-permission-form');
        var formModal = formModalElement ? window.bootstrap.Modal.getOrCreateInstance(formModalElement) : null;
        var $name = $('#permissions-name');
        var $nameError = $('#permissions-name-error');

        if (formModalElement) {
            formModalElement.addEventListener('shown.bs.modal', function () {
                $name.trigger('focus');
            });
        }

        function confirmDialog(message, onConfirm) {
            if (window.bootbox) {
                window.bootbox.confirm({
                    title: 'Confirmar',
                    message: message,
                    centerVertical: true,
                    buttons: {
                        cancel: { label: 'Cancelar', className: 'btn-outline-secondary' },
                        confirm: { label: 'Aceptar', className: 'btn-primary' }
                    },
                    callback: function (ok) {
                        if (ok) {
                            onConfirm();
                        }
                    }
                });
                return;
            }

            if (window.confirm(message)) {
                onConfirm();
            }
        }

        function showError(xhr) {
            var response = xhr.responseJSON || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            message = message || 'No se pudo completar la operación.';

            if (window.bootbox) {
                window.bootbox.alert({ title: 'Error', message: message, centerVertical: true });
            } else {
                window.alert(message);
            }
        }

        var table = $('#permisos-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET'
            },
            columns: [
                { data: 'check', orderable: false, searchable: false, className: 'roles-check' },
                { data: 'id', className: 'roles-id' },
                { data: 'name', className: 'roles-name' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 1, 3] }
            ],
            order: [[2, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ permisos',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ permisos',
                infoEmpty: 'Sin permisos',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron permisos',
                emptyTable: 'No hay permisos registrados',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            },
            drawCallback: function () {
                syncSelectAll();
                updateDeleteSelected();
            }
        });

        var searchTimer = null;

        $('#permisos-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#permisos-clear').on('click', function () {
            $('#permisos-search').val('');
            table.search('').draw();
            $('#permisos-search').trigger('focus');
        });

        function openForm(permission) {
            $('#permissions-form-id').val(permission ? permission.id : '');
            $('#modal-permission-form-title').text(permission ? 'Modificar permiso' : 'Nuevo permiso');
            $name.val(permission ? permission.name : '');
            $nameError.text('').removeAttr('data-kind');

            if (formModal) {
                formModal.show();
            }
        }

        $('#permisos-new').on('click', function () {
            openForm(null);
        });

        function showFieldError(message) {
            $nameError.attr('data-kind', 'error').text(message);
            $name.trigger('focus');
        }

        function savePermission() {
            var id = $('#permissions-form-id').val();
            var name = $.trim($name.val());

            if (!name) {
                showFieldError('Este campo es obligatorio.');
                return;
            }

            $nameError.text('');

            var editing = !!id;

            confirmDialog(editing ? '¿Desea modificar el permiso?' : '¿Desea crear el permiso?', function () {
                $.ajax({
                    url: editing ? permisoBase + '/' + encodeURIComponent(id) : crearUrl,
                    method: editing ? 'PUT' : 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        name: name
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};

                    if (response.errors && response.errors.name) {
                        showFieldError(response.errors.name[0]);
                    }

                    showError(xhr);
                });
            });
        }

        $('#form-permission').on('submit', function (event) {
            event.preventDefault();
            savePermission();
        });

        $('#permisos-table').on('click', '.btn-permission-edit', function () {
            var id = $(this).data('id');

            $.getJSON(permisoBase + '/' + encodeURIComponent(id))
                .done(function (response) {
                    openForm(response.permission);
                })
                .fail(showError);
        });

        $('#permisos-table').on('click', '.btn-permission-view', function () {
            var id = $(this).data('id');

            $.getJSON(permisoBase + '/' + encodeURIComponent(id) + '/ver')
                .done(function (response) {
                    $('#view-permission-id').text(response.permission.id);
                    $('#view-permission-name').text(response.permission.name);
                    $('#view-permission-modules').text(response.permission.modules_count);
                    $('#view-permission-roles').text(response.permission.roles_count);

                    window.bootstrap.Modal
                        .getOrCreateInstance(document.getElementById('modal-permission-view'))
                        .show();
                })
                .fail(showError);
        });

        $('#permisos-table').on('click', '.btn-permission-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desea eliminar el permiso «' + name + '»?', function () {
                $.ajax({
                    url: permisoBase + '/' + encodeURIComponent(id),
                    method: 'DELETE',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        function syncSelectAll() {
            var $all = $('#permisos-check-all');
            var $boxes = $('#permisos-table tbody .permission-checkbox');
            var checked = $boxes.filter(':checked').length;

            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        function updateDeleteSelected() {
            var count = $('#permisos-table tbody .permission-checkbox:checked').length;

            $('#permisos-delete-selected')
                .prop('disabled', count === 0)
                .text(count > 0 ? 'Eliminar permisos (' + count + ')' : 'Eliminar permisos');
        }

        $('#permisos-check-all').on('change', function () {
            var checked = $(this).is(':checked');

            $('#permisos-table tbody .permission-checkbox').prop('checked', checked);
            updateDeleteSelected();
            syncSelectAll();
        });

        $('#permisos-table').on('change', '.permission-checkbox', function () {
            syncSelectAll();
            updateDeleteSelected();
        });

        $('#permisos-delete-selected').on('click', function () {
            var ids = $('#permisos-table tbody .permission-checkbox:checked')
                .map(function () {
                    return parseInt(this.value, 10);
                })
                .get();

            if (!ids.length) {
                return;
            }

            confirmDialog('¿Desea eliminar ' + ids.length + ' permiso(s) seleccionado(s)?', function () {
                $.ajax({
                    url: eliminarVariosUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        ids: ids
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });
    });
})(window.jQuery);
