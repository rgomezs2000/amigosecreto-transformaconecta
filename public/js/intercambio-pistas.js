/**
 * Intercambio de pistas: pestañas de pistas enviadas y recibidas.
 *
 * Las enviadas se pueden crear, modificar, ver y eliminar. Las recibidas son
 * de solo lectura y su emisor nunca se revela.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#pistas-config');

        if (!$config.length) {
            return;
        }

        var urls = {
            datos: $config.attr('data-url-datos'),
            crear: $config.attr('data-url-crear'),
            pista: $config.attr('data-url-pista')
        };

        var token = $config.attr('data-csrf');
        var puedeEscribir = $config.attr('data-puede-escribir') === '1';

        var idioma = {
            processing: 'Cargando...',
            info: 'Mostrando _START_ a _END_ de _TOTAL_ pistas',
            infoEmpty: 'Sin pistas',
            infoFiltered: '(filtrado de _MAX_)',
            zeroRecords: 'No se encontraron pistas',
            emptyTable: 'Todavía no hay pistas',
            paginate: {
                first: 'Primera',
                last: 'Última',
                next: 'Siguiente',
                previous: 'Anterior'
            }
        };

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

        function tabla(selector) {
            var $table = $(selector);

            if (!$table.length) {
                return null;
            }

            return $table.DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: urls.datos,
                    type: 'GET',
                    data: function (d) {
                        d.tipo = selector.indexOf('enviadas') !== -1 ? 'enviadas' : 'recibidas';
                    }
                },
                columns: selector.indexOf('enviadas') !== -1
                    ? [
                        { data: 'fecha', className: 'col-fecha' },
                        { data: 'pista', className: 'col-texto' },
                        { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
                    ]
                    : [
                        { data: 'fecha', className: 'col-fecha' },
                        { data: 'pista', className: 'col-texto' },
                        { data: 'estado', orderable: false, className: 'col-estado' }
                    ],
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: $.extend({}, idioma, {
                    lengthMenu: 'Mostrar _MENU_ pistas'
                })
            });
        }

        var enviadasTable = tabla('#pistas-enviadas-table');
        var recibidasTable = tabla('#pistas-recibidas-table');

        function bindSearch($input, api) {
            if (!$input.length || !api) {
                return;
            }

            var timer = null;

            $input.on('input', function () {
                var value = this.value;

                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    api.search(value).draw();
                }, 200);
            });
        }

        bindSearch($('#pistas-enviadas-search'), enviadasTable);
        bindSearch($('#pistas-recibidas-search'), recibidasTable);

        $('#pistas-enviadas-clear').on('click', function () {
            $('#pistas-enviadas-search').val('').trigger('focus');

            if (enviadasTable) {
                enviadasTable.search('').draw();
            }
        });

        $('#pistas-recibidas-clear').on('click', function () {
            $('#pistas-recibidas-search').val('').trigger('focus');

            if (recibidasTable) {
                recibidasTable.search('').draw();
            }
        });

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tabButton) {
            tabButton.addEventListener('shown.bs.tab', function () {
                if (enviadasTable) {
                    enviadasTable.columns.adjust().draw(false);
                }

                if (recibidasTable) {
                    recibidasTable.columns.adjust().draw(false);
                }
            });
        });

        /* ------------------------------------------------ Formulario pista */

        var $id = $('#pista-id');
        var $body = $('#pista-body');
        var $bodyError = $('#pista-body-error');
        var $formError = $('#pista-form-error');
        var $status = $('#pistas-status');

        function limpiarFormulario() {
            $id.val('');
            $body.val('');
            $bodyError.text('').removeAttr('data-kind');
            $formError.text('').removeAttr('data-kind');
        }

        function abrirFormulario(data) {
            limpiarFormulario();

            if (data) {
                $id.val(data.id || '');
                $body.val(data.body || '');
            }

            $('#modal-pista-title').text(data ? 'Modificar pista' : 'Agregar pista');

            var instance = modalFor('modal-pista');

            if (instance) {
                instance.show();
            }
        }

        $('#pista-nueva').on('click', function () {
            if (!puedeEscribir) {
                return;
            }

            abrirFormulario(null);
        });

        $('#pista-limpiar').on('click', function () {
            limpiarFormulario();
            $body.trigger('focus');
        });

        $('#pista-guardar').on('click', function () {
            if (!puedeEscribir) {
                return;
            }

            $bodyError.text('').removeAttr('data-kind');
            $formError.text('').removeAttr('data-kind');

            var body = $.trim($body.val());

            if (!body) {
                $bodyError.attr('data-kind', 'error').text('Escribe la pista.');
                $body.trigger('focus');
                return;
            }

            var id = $id.val();
            var editando = !!id;
            var $button = $(this);

            window.AppDialog.confirm(
                editando ? '¿Desea modificar la pista?' : '¿Desea enviar la pista a su amigo secreto?',
                function () {
                    $button.prop('disabled', true);

                    $.ajax({
                        url: editando ? urlFor(urls.pista, id) : urls.crear,
                        method: editando ? 'PUT' : 'POST',
                        dataType: 'json',
                        data: { _token: token, body: body }
                    }).done(function (response) {
                        var instance = modalFor('modal-pista');

                        if (instance) {
                            instance.hide();
                        }

                        window.AppDialog.success(response.message || 'Pista guardada.', {
                            callback: function () {
                                if (enviadasTable) {
                                    enviadasTable.ajax.reload(null, false);
                                }
                            }
                        });
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors && response.errors.body) {
                            $bodyError.attr('data-kind', 'error').text(response.errors.body[0]);
                        }

                        showError(xhr, $formError);
                    }).always(function () {
                        $button.prop('disabled', false);
                    });
                }
            );
        });

        $('#pistas-enviadas-table').on('click', '.btn-pista-editar', function () {
            if (!puedeEscribir) {
                return;
            }

            var id = $(this).data('id');

            $.getJSON(urlFor(urls.pista, id))
                .done(function (response) {
                    abrirFormulario(response.pista || {});
                })
                .fail(function (xhr) {
                    showError(xhr, $status);
                });
        });

        $('#pistas-enviadas-table, #pistas-recibidas-table').on('click', '.btn-pista-ver', function () {
            var id = $(this).data('id');
            var $bodyTarget = $('#pista-ver-body');

            $bodyTarget.html('<p class="participantes-empty">Cargando…</p>');

            var instance = modalFor('modal-pista-ver');

            if (instance) {
                instance.show();
            }

            $.getJSON(urlFor(urls.pista, id))
                .done(function (response) {
                    $bodyTarget.html(response.html || '');
                })
                .fail(function (xhr) {
                    $bodyTarget.html('');
                    showError(xhr, $status);
                });
        });

        $('#pistas-enviadas-table').on('click', '.btn-pista-eliminar', function () {
            if (!puedeEscribir) {
                return;
            }

            var id = $(this).data('id');

            window.AppDialog.confirm(
                '¿Desea eliminar esta pista?',
                function () {
                    $.ajax({
                        url: urlFor(urls.pista, id),
                        method: 'DELETE',
                        dataType: 'json',
                        data: { _token: token }
                    }).done(function (response) {
                        window.AppDialog.success(response.message || 'Pista eliminada.', {
                            callback: function () {
                                if (enviadasTable) {
                                    enviadasTable.ajax.reload(null, false);
                                }
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
