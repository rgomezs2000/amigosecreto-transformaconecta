/**
 * Lista de deseos del participante.
 *
 * Tabla paginada en el servidor, búsqueda desde la primera letra con su botón
 * de limpiar y formulario en modal para agregar, modificar, ver y eliminar.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#deseos-config');

        if (!$config.length) {
            return;
        }

        var urls = {
            datos: $config.attr('data-url-datos'),
            crear: $config.attr('data-url-crear'),
            item: $config.attr('data-url-item')
        };

        var token = $config.attr('data-csrf');
        var editable = $config.attr('data-editable') === '1';

        function urlFor(template, id) {
            return String(template || '').replace('__ID__', encodeURIComponent(id));
        }

        function modalFor(id) {
            var element = document.getElementById(id);

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        function showError(xhr, $target) {
            var response = (xhr && xhr.responseJSON) || {};

            if ($target && $target.length) {
                $target.attr('data-kind', 'error').text(
                    response.message || 'No se pudo completar la acción.'
                );
                return;
            }

            window.AppDialog.error(response.message || 'No se pudo completar la acción.');
        }

        var $table = $('#deseos-table');

        if (!$table.length) {
            return;
        }

        var table = $table.DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: urls.datos,
                type: 'GET'
            },
            columns: [
                { data: 'id', className: 'roles-id' },
                { data: 'deseo', className: 'roles-name' },
                { data: 'descripcion', className: 'roles-name' },
                { data: 'fecha', className: 'roles-name' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            order: [[1, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ deseos',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ deseos',
                infoEmpty: 'Sin deseos',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron deseos',
                emptyTable: 'Todavía no has agregado deseos',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            }
        });

        var searchTimer = null;

        $('#deseos-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 200);
        });

        $('#deseos-clear').on('click', function () {
            $('#deseos-search').val('').trigger('focus');
            table.search('').draw();
        });

        /* ------------------------------------------------------ Formulario */

        var $id = $('#deseo-id');
        var $title = $('#deseo-title');
        var $description = $('#deseo-description');
        var $url = $('#deseo-url');
        var $titleError = $('#deseo-title-error');
        var $descriptionError = $('#deseo-description-error');
        var $urlError = $('#deseo-url-error');
        var $formError = $('#deseo-form-error');
        var $status = $('#deseos-status');

        function limpiarFormulario() {
            $id.val('');
            $title.val('');
            $description.val('');
            $url.val('');
            $titleError.text('').removeAttr('data-kind');
            $descriptionError.text('').removeAttr('data-kind');
            $urlError.text('').removeAttr('data-kind');
            $formError.text('').removeAttr('data-kind');
        }

        function abrirFormulario(data) {
            limpiarFormulario();

            if (data) {
                $id.val(data.id || '');
                $title.val(data.title || '');
                $description.val(data.description || '');
                $url.val(data.url || '');
            }

            $('#modal-deseo-title').text(data ? 'Modificar deseo' : 'Agregar un deseo');

            var instance = modalFor('modal-deseo');

            if (instance) {
                instance.show();
            }
        }

        $('#deseo-nuevo').on('click', function () {
            if (!editable) {
                return;
            }

            abrirFormulario(null);
        });

        $('#deseo-limpiar').on('click', function () {
            limpiarFormulario();
            $title.trigger('focus');
        });

        $table.on('click', '.btn-deseo-editar', function () {
            if (!editable) {
                return;
            }

            var id = $(this).data('id');

            $.getJSON(urlFor(urls.item, id))
                .done(function (response) {
                    abrirFormulario(response.item || {});
                })
                .fail(function (xhr) {
                    showError(xhr, $status);
                });
        });

        $table.on('click', '.btn-deseo-ver', function () {
            var id = $(this).data('id');
            var $body = $('#deseo-ver-body');

            $body.html('<p class="participantes-empty">Cargando…</p>');

            var instance = modalFor('modal-deseo-ver');

            if (instance) {
                instance.show();
            }

            $.getJSON(urlFor(urls.item, id))
                .done(function (response) {
                    $body.html(response.html || '');
                })
                .fail(function (xhr) {
                    $body.html('');
                    showError(xhr, $status);
                });
        });

        $('#deseo-guardar').on('click', function () {
            if (!editable) {
                return;
            }

            $titleError.text('').removeAttr('data-kind');
            $urlError.text('').removeAttr('data-kind');
            $formError.text('').removeAttr('data-kind');

            var title = $.trim($title.val());
            var description = $.trim($description.val());
            var url = $.trim($url.val());

            if (!title) {
                $titleError.attr('data-kind', 'error').text('El deseo es obligatorio.');
                $title.trigger('focus');
                return;
            }

            if (url && !/^https?:\/\/.+/i.test(url)) {
                $urlError.attr('data-kind', 'error').text('Escribe un enlace que empiece por http:// o https://.');
                $url.trigger('focus');
                return;
            }

            var id = $id.val();
            var editando = !!id;
            var $button = $(this);

            window.AppDialog.confirm(
                editando ? '¿Desea modificar el deseo?' : '¿Desea agregar el deseo a su lista?',
                function () {
                    $button.prop('disabled', true);

                    $.ajax({
                        url: editando ? urlFor(urls.item, id) : urls.crear,
                        method: editando ? 'PUT' : 'POST',
                        dataType: 'json',
                        data: {
                            _token: token,
                            title: title,
                            description: description,
                            url: url
                        }
                    }).done(function (response) {
                        var instance = modalFor('modal-deseo');

                        if (instance) {
                            instance.hide();
                        }

                        window.AppDialog.success(response.message || 'Deseo guardado.', {
                            callback: function () {
                                table.ajax.reload(null, false);
                            }
                        });
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors) {
                            if (response.errors.title) {
                                $titleError.attr('data-kind', 'error').text(response.errors.title[0]);
                            }
                            if (response.errors.url) {
                                $urlError.attr('data-kind', 'error').text(response.errors.url[0]);
                            }
                        }

                        showError(xhr, $formError);
                    }).always(function () {
                        $button.prop('disabled', false);
                    });
                }
            );
        });

        $table.on('click', '.btn-deseo-eliminar', function () {
            if (!editable) {
                return;
            }

            var id = $(this).data('id');
            var nombre = $(this).data('nombre') || 'este deseo';

            window.AppDialog.confirm(
                '¿Desea eliminar «' + nombre + '» de su lista?',
                function () {
                    $.ajax({
                        url: urlFor(urls.item, id),
                        method: 'DELETE',
                        dataType: 'json',
                        data: { _token: token }
                    }).done(function (response) {
                        window.AppDialog.success(response.message || 'Deseo eliminado.', {
                            callback: function () {
                                table.ajax.reload(null, false);
                            }
                        });
                    }).fail(function (xhr) {
                        showError(xhr, $status);
                    });
                },
                { danger: true, confirmLabel: 'Eliminar' }
            );
        });
    });
})(window.jQuery);
