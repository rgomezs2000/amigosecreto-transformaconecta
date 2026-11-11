/**
 * Categorías: DataTable server-side, búsqueda dinámica, CRUD, selección
 * múltiple y confirmaciones con Bootbox.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#categorias-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var crearUrl = $config.attr('data-url-crear');
        var categoriaBase = $config.attr('data-url-categoria');
        var eliminarVariosUrl = $config.attr('data-url-eliminar-varios');
        var indexUrl = $config.attr('data-url-index');
        var token = $('#categorias-csrf').val();

        var formModalElement = document.getElementById('modal-category-form');
        var formModal = formModalElement ? window.bootstrap.Modal.getOrCreateInstance(formModalElement) : null;
        var $name = $('#categorias-name');
        var $nameError = $('#categorias-name-error');
        var $slug = $('#categorias-slug');
        var $slugError = $('#categorias-slug-error');

        if (formModalElement) {
            formModalElement.addEventListener('shown.bs.modal', function () {
                $name.trigger('focus');
            });
        }

        function slugify(value) {
            return value
                .toString()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');
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

        var table = $('#categorias-table').DataTable({
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
                { data: 'slug', className: 'roles-name' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 1, 4] }
            ],
            order: [[2, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ categorías',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ categorías',
                infoEmpty: 'Sin categorías',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron categorías',
                emptyTable: 'No hay categorías registradas',
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

        $('#categorias-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#categorias-clear').on('click', function () {
            $('#categorias-search').val('');
            table.search('').draw();
            $('#categorias-search').trigger('focus');
        });

        function openForm(category) {
            $('#categorias-form-id').val(category ? category.id : '');
            $('#modal-category-form-title').text(category ? 'Modificar categoría' : 'Nueva categoría');
            $name.val(category ? category.name : '');
            $slug.val(category ? (category.slug || '') : '');
            $nameError.text('').removeAttr('data-kind');
            $slugError.text('').removeAttr('data-kind');

            if (formModal) {
                formModal.show();
            }
        }

        $('#categorias-new').on('click', function () {
            openForm(null);
        });

        function showFieldError($field, $error, message) {
            $error.attr('data-kind', 'error').text(message);
            $field.trigger('focus');
        }

        function saveCategory() {
            var id = $('#categorias-form-id').val();
            var name = $.trim($name.val());
            var slug = $.trim($slug.val());
            var isSystem = ['General', 'Sesión'].indexOf(name) !== -1;

            $nameError.text('');
            $slugError.text('');

            if (!name) {
                showFieldError($name, $nameError, 'Este campo es obligatorio.');
                return;
            }

            if (!slug && !isSystem) {
                showFieldError($slug, $slugError, 'Este campo es obligatorio.');
                return;
            }

            var editing = !!id;

            confirmDialog(editing ? '¿Desea modificar la categoría?' : '¿Desea crear la categoría?', function () {
                $.ajax({
                    url: editing ? categoriaBase + '/' + encodeURIComponent(id) : crearUrl,
                    method: editing ? 'PUT' : 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        name: name,
                        slug: slug
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};

                    if (response.errors) {
                        if (response.errors.name) {
                            showFieldError($name, $nameError, response.errors.name[0]);
                        }
                        if (response.errors.slug) {
                            showFieldError($slug, $slugError, response.errors.slug[0]);
                        }
                    }

                    showError(xhr);
                });
            });
        }

        $slug.on('input', function () {
            var clean = slugify(this.value);

            if (clean !== this.value) {
                this.value = clean;
            }

            $slugError.text('');
        });

        $('#form-category').on('submit', function (event) {
            event.preventDefault();
            saveCategory();
        });

        $('#categorias-table').on('click', '.btn-category-edit', function () {
            var id = $(this).data('id');

            $.getJSON(categoriaBase + '/' + encodeURIComponent(id))
                .done(function (response) {
                    openForm(response.category);
                })
                .fail(showError);
        });

        $('#categorias-table').on('click', '.btn-category-view', function () {
            var id = $(this).data('id');

            $.getJSON(categoriaBase + '/' + encodeURIComponent(id) + '/ver')
                .done(function (response) {
                    var category = response.category;

                    $('#view-category-id').text(category.id);
                    $('#view-category-name').text(category.name);
                    $('#view-category-slug').text(category.slug || '—');
                    $('#view-category-modules').text(category.modules_count);

                    var $list = $('#view-category-modules-list').empty();

                    if (!category.modules || !category.modules.length) {
                        $('<li>').text('Sin módulos asociados.').appendTo($list);
                    } else {
                        $.each(category.modules, function (_, moduleName) {
                            $('<li>').text(moduleName).appendTo($list);
                        });
                    }

                    window.bootstrap.Modal
                        .getOrCreateInstance(document.getElementById('modal-category-view'))
                        .show();
                })
                .fail(showError);
        });

        $('#categorias-table').on('click', '.btn-category-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desea eliminar la categoría «' + name + '»?', function () {
                $.ajax({
                    url: categoriaBase + '/' + encodeURIComponent(id),
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
            var $all = $('#categorias-check-all');
            var $boxes = $('#categorias-table tbody .category-checkbox');
            var checked = $boxes.filter(':checked').length;

            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        function updateDeleteSelected() {
            var count = $('#categorias-table tbody .category-checkbox:checked').length;

            $('#categorias-delete-selected')
                .prop('disabled', count === 0)
                .text(count > 0 ? 'Eliminar categorías (' + count + ')' : 'Eliminar categorías');
        }

        $('#categorias-check-all').on('change', function () {
            var checked = $(this).is(':checked');

            $('#categorias-table tbody .category-checkbox').prop('checked', checked);
            updateDeleteSelected();
            syncSelectAll();
        });

        $('#categorias-table').on('change', '.category-checkbox', function () {
            syncSelectAll();
            updateDeleteSelected();
        });

        $('#categorias-delete-selected').on('click', function () {
            var ids = $('#categorias-table tbody .category-checkbox:checked')
                .map(function () {
                    return parseInt(this.value, 10);
                })
                .get();

            if (!ids.length) {
                return;
            }

            confirmDialog('¿Desea eliminar ' + ids.length + ' categoría(s) seleccionada(s)?', function () {
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
