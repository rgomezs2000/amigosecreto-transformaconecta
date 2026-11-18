/**
 * «Ver tu amigo»: pestañas, lista de deseos del amigo, pistas recibidas,
 * actividad de la invitación y retiro voluntario.
 *
 * Las acciones de obsequios y consumibles viven en ver-tu-amigo.js, que solo se
 * carga cuando la etapa de obsequios está habilitada.
 */
(function ($) {
    'use strict';

    $(function () {
        var $config = $('#amigo-config');

        if (!$config.length) {
            return;
        }

        var token = $config.attr('data-csrf');
        var aceptarUrl = $config.attr('data-url-aceptar');
        var rechazarUrl = $config.attr('data-url-rechazar');

        function modalFor(id) {
            var element = document.getElementById(id);

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        function setStatus($target, message, kind) {
            if (!$target || !$target.length) {
                return;
            }

            if (!message) {
                $target.text('').removeAttr('data-kind');
                return;
            }

            $target.attr('data-kind', kind || 'info').text(message);
        }

        function showError(xhr, $target) {
            var response = (xhr && xhr.responseJSON) || {};

            if ($target && $target.length) {
                setStatus($target, response.message || 'No se pudo completar la acción.', 'error');
                return;
            }

            window.AppDialog.error(response.message || 'No se pudo completar la acción.');
        }

        /* -------------------------------------------------- Tablas simples */

        function initSimpleTable(selector, $search, $clear, emptyText) {
            var $table = $(selector);

            if (!$table.length || !$.fn || !$.fn.DataTable) {
                return null;
            }

            var api = $table.DataTable({
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: {
                    lengthMenu: 'Mostrar _MENU_ registros',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                    infoEmpty: 'Sin registros',
                    infoFiltered: '(filtrado de _MAX_)',
                    zeroRecords: 'No se encontraron coincidencias',
                    emptyTable: emptyText,
                    paginate: {
                        first: 'Primera',
                        last: 'Última',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            var timer = null;

            if ($search && $search.length) {
                $search.on('input', function () {
                    var value = this.value;

                    window.clearTimeout(timer);
                    timer = window.setTimeout(function () {
                        api.search(value).draw();
                    }, 200);
                });
            }

            if ($clear && $clear.length) {
                $clear.on('click', function () {
                    if ($search && $search.length) {
                        $search.val('').trigger('focus');
                    }

                    api.search('').draw();
                });
            }

            return api;
        }

        var wishTable = initSimpleTable(
            '#amigo-deseos-table',
            $('#amigo-deseos-search'),
            $('#amigo-deseos-clear'),
            'Tu amigo todavía no registró deseos.'
        );

        var cluesTable = initSimpleTable(
            '#amigo-pistas-table',
            $('#amigo-pistas-search'),
            $('#amigo-pistas-clear'),
            'Tu amigo secreto todavía no te dejó pistas.'
        );

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tabButton) {
            tabButton.addEventListener('shown.bs.tab', function () {
                if (wishTable) {
                    wishTable.columns.adjust().draw(false);
                }

                if (cluesTable) {
                    cluesTable.columns.adjust().draw(false);
                }
            });
        });

        // Acceso directo desde «Sorteo ciego»: abre la pestaña de obsequios.
        if (new URLSearchParams(window.location.search).get('seccion') === 'obsequios') {
            var giftsTab = document.getElementById('amigo-tab-obsequios');

            if (giftsTab && window.bootstrap && window.bootstrap.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(giftsTab).show();
            }
        }

        /* ---------------------------------------------------- Invitación */

        var $invitacion = $('.amigo-invitacion');
        var invitacionToken = $invitacion.data('token') || '';
        var $motivoField = $('#invitacion-motivo-field');
        var $motivo = $('#invitacion-motivo');
        var $motivoError = $('#invitacion-motivo-error');
        var $formError = $('#invitacion-form-error');

        function actualizarMotivo() {
            var rechaza = $('#invitacion-rechazar').is(':checked');

            $motivoField.prop('hidden', !rechaza);
        }

        $('input[name="invitacion-respuesta"]').on('change', actualizarMotivo);
        actualizarMotivo();

        function abrirInvitacion() {
            if (!invitacionToken) {
                return;
            }

            $formError.text('').removeAttr('data-kind');
            $motivo.val('');
            $motivoError.text('').removeAttr('data-kind');
            $('#invitacion-aceptar').prop('checked', true);
            actualizarMotivo();

            var instance = modalFor('modal-invitacion');

            if (instance) {
                instance.show();
            }
        }

        $('#amigo-invitacion-abrir').on('click', abrirInvitacion);

        if ($invitacion.length
            && $invitacion.attr('data-auto-open') === '1'
            && $invitacion.attr('data-status') === 'pending') {
            window.setTimeout(abrirInvitacion, 350);
        }

        $('#invitacion-responder').on('click', function () {
            var rechaza = $('#invitacion-rechazar').is(':checked');
            var motivo = $.trim($motivo.val());
            var $button = $(this);

            $formError.text('').removeAttr('data-kind');
            $motivoError.text('').removeAttr('data-kind');

            if (!rechaza) {
                window.AppDialog.confirm(
                    '¿Desea aceptar la invitación y participar en el evento?',
                    function () {
                        enviarRespuesta(false, '', $button);
                    }
                );

                return;
            }

            if (motivo.length < 3) {
                $motivoError.attr('data-kind', 'error').text('Escribe el motivo del rechazo.');
                $motivo.trigger('focus');
                return;
            }

            window.AppDialog.confirm(
                '¿Desea rechazar la invitación? Tu participación quedará como declinada.',
                function () {
                    enviarRespuesta(true, motivo, $button);
                }
            );
        });

        function enviarRespuesta(rechaza, motivo, $button) {
            var url = String(rechaza ? rechazarUrl : aceptarUrl).replace(
                '__TOKEN__',
                encodeURIComponent(invitacionToken)
            );

            $button.prop('disabled', true);

            $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, rechaza ? { reason: motivo } : {})
            }).done(function (response) {
                var instance = modalFor('modal-invitacion');

                if (instance) {
                    instance.hide();
                }

                window.AppDialog.success(response.message || 'Respuesta registrada.', {
                    callback: function () {
                        window.location.assign(response.redirect || window.location.href);
                    }
                });
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};

                if (response.errors && response.errors.reason) {
                    $motivoError.attr('data-kind', 'error').text(response.errors.reason[0]);
                }

                setStatus($formError, response.message || 'No se pudo registrar tu respuesta.', 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        /* ------------------------------------------------------- Retiro */

        $('.btn-amigo-retirar').on('click', function () {
            $('#retirar-motivo').val('');
            $('#retirar-motivo-error').text('').removeAttr('data-kind');

            var instance = modalFor('modal-retirar');

            if (instance) {
                instance.show();
            }
        });

        $('#retirar-confirm').on('click', function () {
            var motivo = $.trim($('#retirar-motivo').val());
            var $button = $(this);

            $('#retirar-motivo-error').text('').removeAttr('data-kind');

            if (motivo.length < 3) {
                $('#retirar-motivo-error').attr('data-kind', 'error').text('Escribe el motivo del retiro.');
                $('#retirar-motivo').trigger('focus');
                return;
            }

            window.AppDialog.confirm(
                '¿Desea retirarse del evento? Tu participación quedará como retirada.',
                function () {
                    $button.prop('disabled', true);

                    $.ajax({
                        url: $config.attr('data-url-retirar'),
                        method: 'POST',
                        dataType: 'json',
                        data: { _token: token, reason: motivo }
                    }).done(function (response) {
                        var instance = modalFor('modal-retirar');

                        if (instance) {
                            instance.hide();
                        }

                        window.location.assign(response.redirect || window.location.href);
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors && response.errors.reason) {
                            $('#retirar-motivo-error').attr('data-kind', 'error').text(response.errors.reason[0]);
                        } else {
                            $('#retirar-motivo-error')
                                .attr('data-kind', 'error')
                                .text(response.message || 'No se pudo completar el retiro.');
                        }
                    }).always(function () {
                        $button.prop('disabled', false);
                    });
                },
                { danger: true, confirmLabel: 'Retirarme' }
            );
        });
    });
})(window.jQuery);
