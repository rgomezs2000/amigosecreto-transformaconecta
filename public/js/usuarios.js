/**
 * Usuarios: DataTable server-side, CRUD, validación AJAX de usuario,
 * restablecer contraseña, bloquear/desbloquear y selección múltiple.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#usuarios-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var crearUrl = $config.attr('data-url-crear');
        var usuarioBase = $config.attr('data-url-usuario');
        var eliminarVariosUrl = $config.attr('data-url-eliminar-varios');
        var indexUrl = $config.attr('data-url-index');
        var loginExisteUrl = $config.attr('data-url-login-existe');
        var token = $('#usuarios-csrf').val();

        var formModalElement = document.getElementById('modal-user-form');
        var formModal = formModalElement ? window.bootstrap.Modal.getOrCreateInstance(formModalElement) : null;
        var $name = $('#usuarios-name');
        var $email = $('#usuarios-email');
        var $username = $('#usuarios-username');
        var $role = $('#usuarios-role');
        var $nameError = $('#usuarios-name-error');
        var $emailError = $('#usuarios-email-error');
        var $usernameError = $('#usuarios-username-error');
        var $roleError = $('#usuarios-role-error');
        var usernameAvailable = true;
        var usernameTimer = null;

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

        var table = $('#usuarios-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET',
                data: function (d) {
                    d.filter_type = $('#usuarios-filter-type').val();
                    d.filter_role = $('#usuarios-filter-role').val();
                    d.filter_status = $('#usuarios-filter-status').val();
                }
            },
            columns: [
                { data: 'check', orderable: false, searchable: false, className: 'roles-check' },
                { data: 'id', className: 'roles-id' },
                { data: 'name', className: 'roles-name' },
                { data: 'email', className: 'roles-name' },
                { data: 'username', className: 'roles-name' },
                { data: 'role', orderable: false, className: 'roles-name' },
                { data: 'status', orderable: false, className: 'roles-name' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 1, 5, 6, 7] }
            ],
            order: [[2, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ usuarios',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ usuarios',
                infoEmpty: 'Sin usuarios',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron usuarios',
                emptyTable: 'No hay usuarios registrados',
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

        $('#usuarios-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        function toggleFilters() {
            var type = $('#usuarios-filter-type').val();

            $('#usuarios-filter-role-wrap').toggleClass('d-none', type !== 'role');
            $('#usuarios-filter-status-wrap').toggleClass('d-none', type !== 'status');
        }

        $('#usuarios-filter-type').on('change', function () {
            toggleFilters();
            table.ajax.reload();
        });

        $('#usuarios-filter-role, #usuarios-filter-status').on('change', function () {
            table.ajax.reload();
        });

        $('#usuarios-clear').on('click', function () {
            $('#usuarios-search').val('');
            $('#usuarios-filter-type').val('all');
            $('#usuarios-filter-role').prop('selectedIndex', 0);
            $('#usuarios-filter-status').val('active');
            toggleFilters();
            table.search('').draw();
            $('#usuarios-search').trigger('focus');
        });

        function checkUsername() {
            var login = $.trim($username.val());
            var ignore = $('#usuarios-form-id').val();

            $usernameError.text('').removeAttr('data-kind');

            if (!login) {
                usernameAvailable = true;
                return;
            }

            $.getJSON(loginExisteUrl, {
                login: login,
                ignore: ignore || undefined,
                admin: 1
            }).done(function (response) {
                usernameAvailable = !!response.available;
                $usernameError
                    .attr('data-kind', response.available ? 'ok' : 'error')
                    .text(response.message);
            }).fail(function (xhr) {
                usernameAvailable = false;
                var response = xhr.responseJSON || {};

                $usernameError
                    .attr('data-kind', 'error')
                    .text(response.message || 'No se pudo verificar el usuario.');
            });
        }

        $username.on('blur', checkUsername);
        $username.on('input', function () {
            window.clearTimeout(usernameTimer);
            $usernameError.text('');
            usernameTimer = window.setTimeout(checkUsername, 450);
        });

        function clearErrors() {
            $nameError.text('').removeAttr('data-kind');
            $emailError.text('').removeAttr('data-kind');
            $usernameError.text('').removeAttr('data-kind');
            $roleError.text('').removeAttr('data-kind');
        }

        function openForm(user) {
            $('#usuarios-form-id').val(user ? user.id : '');
            $('#modal-user-form-title').text(user ? 'Modificar usuario' : 'Nuevo usuario');
            $name.val(user ? user.name : '');
            $email.val(user ? user.email : '');
            $username.val(user ? user.username : '');
            $role.val(user ? user.profile_id : '');
            usernameAvailable = true;
            clearErrors();

            if (formModal) {
                formModal.show();
            }
        }

        $('#usuarios-new').on('click', function () {
            openForm(null);
        });

        function showFieldError($field, $error, message) {
            $error.attr('data-kind', 'error').text(message);
            $field.trigger('focus');
        }

        function validEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }

        function saveUser() {
            var id = $('#usuarios-form-id').val();
            var name = $.trim($name.val());
            var email = $.trim($email.val());
            var username = $.trim($username.val());
            var profileId = $role.val();
            var editing = !!id;

            clearErrors();

            if (!name) {
                showFieldError($name, $nameError, 'Este campo es obligatorio.');
                return;
            }

            if (!email) {
                showFieldError($email, $emailError, 'Este campo es obligatorio.');
                return;
            }

            if (!validEmail(email)) {
                showFieldError($email, $emailError, 'Escribe un correo válido.');
                return;
            }

            if (!username) {
                showFieldError($username, $usernameError, 'Este campo es obligatorio.');
                return;
            }

            if (!profileId) {
                showFieldError($role, $roleError, 'Selecciona un rol.');
                return;
            }

            if (!usernameAvailable) {
                showFieldError($username, $usernameError, 'Usuario no disponible.');
                return;
            }

            confirmDialog(editing ? '¿Desea modificar el usuario?' : '¿Desea crear el usuario?', function () {
                $.ajax({
                    url: editing ? usuarioBase + '/' + encodeURIComponent(id) : crearUrl,
                    method: editing ? 'PUT' : 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        name: name,
                        email: email,
                        username: username,
                        profile_id: profileId
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};

                    if (response.errors) {
                        if (response.errors.name) {
                            showFieldError($name, $nameError, response.errors.name[0]);
                        }
                        if (response.errors.email) {
                            showFieldError($email, $emailError, response.errors.email[0]);
                        }
                        if (response.errors.username) {
                            showFieldError($username, $usernameError, response.errors.username[0]);
                        }
                        if (response.errors.profile_id) {
                            showFieldError($role, $roleError, response.errors.profile_id[0]);
                        }
                    }

                    showError(xhr);
                });
            });
        }

        $('#form-user').on('submit', function (event) {
            event.preventDefault();
            saveUser();
        });

        $('#usuarios-table').on('click', '.btn-user-edit', function () {
            var id = $(this).data('id');

            $.getJSON(usuarioBase + '/' + encodeURIComponent(id))
                .done(function (response) {
                    openForm(response.user);
                })
                .fail(showError);
        });

        $('#usuarios-table').on('click', '.btn-user-view', function () {
            var id = $(this).data('id');

            $.getJSON(usuarioBase + '/' + encodeURIComponent(id) + '/ver')
                .done(function (response) {
                    var user = response.user;

                    $('#view-user-id').text(user.id);
                    $('#view-user-name').text(user.name);
                    $('#view-user-email').text(user.email);
                    $('#view-user-username').text(user.username);
                    $('#view-user-role').text(user.role || '—');
                    $('#view-user-status').text(user.status || '—');
                    $('#view-user-created').text(user.created_at || '—');

                    window.bootstrap.Modal
                        .getOrCreateInstance(document.getElementById('modal-user-view'))
                        .show();
                })
                .fail(showError);
        });

        $('#usuarios-table').on('click', '.btn-user-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desea eliminar el usuario «' + name + '»?', function () {
                $.ajax({
                    url: usuarioBase + '/' + encodeURIComponent(id),
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

        $('#usuarios-table').on('click', '.btn-user-reset', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Restablecer la contraseña de «' + name + '»? Se enviará una nueva clave por correo.', function () {
                $.ajax({
                    url: usuarioBase + '/' + encodeURIComponent(id) + '/restablecer',
                    method: 'POST',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        $('#usuarios-table').on('click', '.btn-user-block', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Bloquear el usuario «' + name + '»?', function () {
                $.ajax({
                    url: usuarioBase + '/' + encodeURIComponent(id) + '/bloquear',
                    method: 'POST',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        $('#usuarios-table').on('click', '.btn-user-unlock', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desbloquear el usuario «' + name + '»?', function () {
                $.ajax({
                    url: usuarioBase + '/' + encodeURIComponent(id) + '/desbloquear',
                    method: 'POST',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        function syncSelectAll() {
            var $all = $('#usuarios-check-all');
            var $boxes = $('#usuarios-table tbody .user-checkbox');
            var checked = $boxes.filter(':checked').length;

            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        function updateDeleteSelected() {
            var count = $('#usuarios-table tbody .user-checkbox:checked').length;

            $('#usuarios-delete-selected')
                .prop('disabled', count === 0)
                .text(count > 0 ? 'Eliminar usuarios (' + count + ')' : 'Eliminar usuarios');
        }

        $('#usuarios-check-all').on('change', function () {
            var checked = $(this).is(':checked');

            $('#usuarios-table tbody .user-checkbox').prop('checked', checked);
            updateDeleteSelected();
            syncSelectAll();
        });

        $('#usuarios-table').on('change', '.user-checkbox', function () {
            syncSelectAll();
            updateDeleteSelected();
        });

        $('#usuarios-delete-selected').on('click', function () {
            var ids = $('#usuarios-table tbody .user-checkbox:checked')
                .map(function () {
                    return parseInt(this.value, 10);
                })
                .get();

            if (!ids.length) {
                return;
            }

            confirmDialog('¿Desea eliminar ' + ids.length + ' usuario(s) seleccionado(s)?', function () {
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
