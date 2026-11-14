/**
 * Módulos: DataTable server-side, CRUD, categoría, slug, ruta /categoria/modulo,
 * búsqueda, selección múltiple y confirmaciones con Bootbox.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#modulos-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var crearUrl = $config.attr('data-url-crear');
        var moduloBase = $config.attr('data-url-modulo');
        var eliminarVariosUrl = $config.attr('data-url-eliminar-varios');
        var indexUrl = $config.attr('data-url-index');
        var token = $('#modulos-csrf').val();

        var formModalElement = document.getElementById('modal-module-form');
        var formModal = formModalElement ? window.bootstrap.Modal.getOrCreateInstance(formModalElement) : null;
        var $name = $('#modulos-name');
        var $category = $('#modulos-category');
        var $icon = $('#modulos-icon');
        var $iconPreview = $('#modulos-icon-preview');
        var $slug = $('#modulos-slug');
        var $ruta = $('#modulos-ruta');
        var $nameError = $('#modulos-name-error');
        var $categoryError = $('#modulos-category-error');
        var $iconError = $('#modulos-icon-error');
        var $slugError = $('#modulos-slug-error');

        var DEFAULT_ICON = 'fa-cube';
        var ICON_PATTERN = /^fa-[a-z0-9]+(?:-[a-z0-9]+)*$/;

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

        function selectedCategorySlug() {
            return $category.find('option:selected').data('slug') || '';
        }

        // Vista previa del icono: muestra la clase escrita o la de por defecto.
        function updateIconPreview() {
            var icon = $.trim($icon.val());

            $iconPreview.attr('class', 'fa-solid ' + (icon || DEFAULT_ICON));
        }

        $icon.on('input', function () {
            var clean = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');

            if (clean !== this.value) {
                this.value = clean;
            }

            updateIconPreview();
            $iconError.text('').removeAttr('data-kind');
        });

        // Paleta de iconos sugeridos: rellena el campo con la clase elegida.
        $('#modal-module-form').on('click', '.icon-palette__item', function () {
            $icon.val($(this).data('icon'));
            updateIconPreview();
            $iconError.text('').removeAttr('data-kind');
            $icon.trigger('focus');
        });

        updateIconPreview();

        function updateRuta() {
            var categorySlug = selectedCategorySlug();
            var slug = $.trim($slug.val());
            var parts = [];

            if (categorySlug) {
                parts.push(categorySlug);
            }

            if (slug) {
                parts.push(slug);
            }

            $ruta.text('/' + parts.join('/'));
        }

        $slug.on('input', function () {
            var clean = slugify(this.value);

            if (clean !== this.value) {
                this.value = clean;
            }

            updateRuta();
            $slugError.text('');
        });

        $category.on('change', function () {
            updateRuta();
            $categoryError.text('');
        });

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

        var table = $('#modulos-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: datosUrl,
                type: 'GET',
                data: function (d) {
                    d.filter_type = $('#modulos-filter-type').val();
                    d.filter_category = $('#modulos-filter-category').val();
                }
            },
            columns: [
                { data: 'check', orderable: false, searchable: false, className: 'roles-check' },
                { data: 'id', className: 'roles-id' },
                { data: 'name', className: 'roles-name' },
                { data: 'category', orderable: false, className: 'roles-name' },
                { data: 'icon', orderable: false, searchable: false, className: 'roles-icon' },
                { data: 'slug', className: 'roles-id' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 1, 3, 4, 6] }
            ],
            order: [[2, 'asc']],
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
                emptyTable: 'No hay módulos registrados',
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

        $('#modulos-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        // «Filtrar por»: todos los módulos o por categoría.
        function syncFilter() {
            var type = $('#modulos-filter-type').val();

            $('#modulos-filter-category-wrap').toggleClass('d-none', type !== 'category');
        }

        $('#modulos-filter-type').on('change', function () {
            syncFilter();
            table.ajax.reload();
        });

        $('#modulos-filter-category').on('change', function () {
            table.ajax.reload();
        });

        // «Limpiar» deja el formulario como al entrar y recarga el listado.
        $('#modulos-clear').on('click', function () {
            window.clearTimeout(searchTimer);
            $('#modulos-search').val('');
            $('#modulos-filter-type').val('all');
            $('#modulos-filter-category').prop('selectedIndex', 0);
            syncFilter();
            table.search('').draw();
            $('#modulos-search').trigger('focus');
        });

        syncFilter();

        function clearErrors() {
            $nameError.text('').removeAttr('data-kind');
            $categoryError.text('').removeAttr('data-kind');
            $iconError.text('').removeAttr('data-kind');
            $slugError.text('').removeAttr('data-kind');
        }

        function openForm(module) {
            $('#modulos-form-id').val(module ? module.id : '');
            $('#modal-module-form-title').text(module ? 'Modificar módulo' : 'Nuevo módulo');
            $name.val(module ? module.name : '');
            $category.val(module ? module.category_id : '');
            $icon.val(module ? module.icon || DEFAULT_ICON : DEFAULT_ICON);
            $slug.val(module ? module.slug : '');
            clearErrors();
            updateIconPreview();
            updateRuta();

            if (formModal) {
                formModal.show();
            }
        }

        $('#modulos-new').on('click', function () {
            openForm(null);
        });

        function showFieldError($field, $error, message) {
            $error.attr('data-kind', 'error').text(message);
            $field.trigger('focus');
        }

        function saveModule() {
            var id = $('#modulos-form-id').val();
            var name = $.trim($name.val());
            var categoryId = $category.val();
            var icon = $.trim($icon.val());
            var slug = $.trim($slug.val());
            var editing = !!id;
            var isInicio = editing && slug === '';

            clearErrors();

            if (!name) {
                showFieldError($name, $nameError, 'Este campo es obligatorio.');
                return;
            }

            if (!categoryId) {
                showFieldError($category, $categoryError, 'Selecciona una categoría.');
                return;
            }

            if (icon && !ICON_PATTERN.test(icon)) {
                showFieldError($icon, $iconError, 'Usa una clase de Font Awesome, por ejemplo fa-house.');
                return;
            }

            if (!slug && !isInicio) {
                showFieldError($slug, $slugError, 'Este campo es obligatorio.');
                return;
            }

            confirmDialog(editing ? '¿Desea modificar el módulo?' : '¿Desea crear el módulo?', function () {
                $.ajax({
                    url: editing ? moduloBase + '/' + encodeURIComponent(id) : crearUrl,
                    method: editing ? 'PUT' : 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        name: name,
                        category_id: categoryId,
                        icon: icon,
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
                        if (response.errors.category_id) {
                            showFieldError($category, $categoryError, response.errors.category_id[0]);
                        }
                        if (response.errors.icon) {
                            showFieldError($icon, $iconError, response.errors.icon[0]);
                        }
                        if (response.errors.slug) {
                            showFieldError($slug, $slugError, response.errors.slug[0]);
                        }
                    }

                    showError(xhr);
                });
            });
        }

        $('#form-module').on('submit', function (event) {
            event.preventDefault();
            saveModule();
        });

        $('#modulos-table').on('click', '.btn-module-edit', function () {
            var id = $(this).data('id');

            $.getJSON(moduloBase + '/' + encodeURIComponent(id))
                .done(function (response) {
                    openForm(response.module);
                })
                .fail(showError);
        });

        $('#modulos-table').on('click', '.btn-module-view', function () {
            var id = $(this).data('id');

            $.getJSON(moduloBase + '/' + encodeURIComponent(id) + '/ver')
                .done(function (response) {
                    var module = response.module;

                    $('#view-module-id').text(module.id);
                    $('#view-module-name').text(module.name);
                    $('#view-module-category').text(module.category_name || '—');

                    var icon = module.icon || DEFAULT_ICON;
                    $('#view-module-icon').attr('class', 'fa-solid ' + icon);
                    $('#view-module-icon-class').text(icon);

                    $('#view-module-slug').text(module.slug || '—');
                    $('#view-module-permissions').text(module.permissions_count);
                    $('#view-module-access').text(module.access_count);

                    var $url = $('#view-module-url');
                    $url.text(module.url).attr('href', module.url);

                    window.bootstrap.Modal
                        .getOrCreateInstance(document.getElementById('modal-module-view'))
                        .show();
                })
                .fail(showError);
        });

        $('#modulos-table').on('click', '.btn-module-delete', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            confirmDialog('¿Desea eliminar el módulo «' + name + '»?', function () {
                $.ajax({
                    url: moduloBase + '/' + encodeURIComponent(id),
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
            var $all = $('#modulos-check-all');
            var $boxes = $('#modulos-table tbody .module-checkbox');
            var checked = $boxes.filter(':checked').length;

            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        function updateDeleteSelected() {
            var count = $('#modulos-table tbody .module-checkbox:checked').length;

            $('#modulos-delete-selected')
                .prop('disabled', count === 0)
                .text(count > 0 ? 'Eliminar módulos (' + count + ')' : 'Eliminar módulos');
        }

        $('#modulos-check-all').on('change', function () {
            var checked = $(this).is(':checked');

            $('#modulos-table tbody .module-checkbox').prop('checked', checked);
            updateDeleteSelected();
            syncSelectAll();
        });

        $('#modulos-table').on('change', '.module-checkbox', function () {
            syncSelectAll();
            updateDeleteSelected();
        });

        $('#modulos-delete-selected').on('click', function () {
            var ids = $('#modulos-table tbody .module-checkbox:checked')
                .map(function () {
                    return parseInt(this.value, 10);
                })
                .get();

            if (!ids.length) {
                return;
            }

            confirmDialog('¿Desea eliminar ' + ids.length + ' módulo(s) seleccionado(s)?', function () {
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
