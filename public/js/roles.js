/**
 * Roles: DataTable server-side, búsqueda dinámica, CRUD, selección múltiple
 * y confirmaciones con Bootbox.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#roles-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var crearUrl = $config.attr('data-url-crear');
        var rolBase = $config.attr('data-url-rol');
        var eliminarVariosUrl = $config.attr('data-url-eliminar-varios');
        var indexUrl = $config.attr('data-url-index');
        var token = $('#roles-csrf').val();

        var formModalElement = document.getElementById('modal-role-form');
        var formModal = formModalElement ? window.bootstrap.Modal.getOrCreateInstance(formModalElement) : null;
        var $name = $('#roles-name');
        var $nameError = $('#roles-name-error');

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
                window.bootbox.alert({
                    title: 'Error',
                    message: message,
                    centerVertical: true
                });
            } else {
                window.alert(message);
            }
        }

        var table = $('#roles-table').DataTable({
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
                lengthMenu: 'Mostrar _MENU_ roles',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ roles',
                infoEmpty: 'Sin roles',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron roles',
                emptyTable: 'No hay roles registrados',
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

        $('#roles-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#roles-clear').on('click', function () {
            $('#roles-search').val('');
            table.search('').draw();
            $('#roles-search').trigger('focus');
        });

        function openForm(role) {
            $('#roles-form-id').val(role ? role.id : '');
            $('#modal-role-form-title').text(role ? 'Modificar rol' : 'Nuevo rol');
            $name.val(role ? role.name : '');
            $nameError.text('').removeAttr('data-kind');

            if (formModal) {
                formModal.show();
            }
        }

        $('#roles-new').on('click', function () {
            openForm(null);
        });

        function showFieldError(message) {
            $nameError.attr('data-kind', 'error').text(message);
            $name.trigger('focus');
        }

        function saveRole() {
            var id = $('#roles-form-id').val();
            var name = $.trim($name.val());

            if (!name) {
                showFieldError('Este campo es obligatorio.');
                return;
            }

            $nameError.text('');

            var editing = !!id;

            confirmDialog(editing ? '¿Desea modificar el rol?' : '¿Desea crear el rol?', function () {
                $.ajax({
                    url: editing ? rolBase + '/' + encodeURIComponent(id) : crearUrl,
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

        $('#form-role').on('submit', function (event) {
            event.preventDefault();
            saveRole();
        });

        $('#roles-table').on('click', '.btn-role-edit', function () {
            var id = $(this).data('id');

            $.getJSON(rolBase + '/' + encodeURIComponent(id))
                .done(function (response) {
                    openForm(response.role);
                })
                .fail(showError);
        });

        $('#roles-table').on('click', '.btn-role-view', function () {
            var id = $(this).data('id');

            $.getJSON(rolBase + '/' + encodeURIComponent(id) + '/ver')
                .done(function (response) {
                    $('#view-role-id').text(response.role.id);
                    $('#view-role-name').text(response.role.name);
                    $('#view-role-users').text(response.role.users_count);
                    $('#view-role-permissions').text(response.role.permissions_count);

                    window.bootstrap.Modal
                        .getOrCreateInstance(document.getElementById('modal-role-view'))
                        .show();
                })
                .fail(showError);
        });

        $('#roles-table').on('click', '.btn-role-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desea eliminar el rol «' + name + '»?', function () {
                $.ajax({
                    url: rolBase + '/' + encodeURIComponent(id),
                    method: 'DELETE',
                    dataType: 'json',
                    data: {
                        _token: token
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        function syncSelectAll() {
            var $all = $('#roles-check-all');
            var $boxes = $('#roles-table tbody .role-checkbox');
            var checked = $boxes.filter(':checked').length;

            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        function updateDeleteSelected() {
            var count = $('#roles-table tbody .role-checkbox:checked').length;

            $('#roles-delete-selected')
                .prop('disabled', count === 0)
                .text(count > 0 ? 'Eliminar roles (' + count + ')' : 'Eliminar roles');
        }

        $('#roles-check-all').on('change', function () {
            var checked = $(this).is(':checked');

            $('#roles-table tbody .role-checkbox').prop('checked', checked);
            updateDeleteSelected();
            syncSelectAll();
        });

        $('#roles-table').on('change', '.role-checkbox', function () {
            syncSelectAll();
            updateDeleteSelected();
        });

        $('#roles-delete-selected').on('click', function () {
            var ids = $('#roles-table tbody .role-checkbox:checked')
                .map(function () {
                    return parseInt(this.value, 10);
                })
                .get();

            if (!ids.length) {
                return;
            }

            confirmDialog('¿Desea eliminar ' + ids.length + ' rol(es) seleccionado(s)?', function () {
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
