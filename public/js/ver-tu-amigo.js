/**
 * «Ver tu amigo»: obsequio único y obsequios consumibles del participante.
 *
 * - El valor se escribe en US$ y el equivalente en Bs. se calcula con la tasa
 *   oficial del BCV mediante AJAX (solo lectura).
 * - El obsequio queda en solo lectura al guardarse; «Modificar» lo habilita y
 *   «Cancelar» lo restaura como estaba.
 * - Los consumibles viven en un DataTable paginado con modales para agregar,
 *   modificar, ver y eliminar.
 *
 * Estas acciones solo se cargan cuando el evento ya avanzó a la etapa de
 * obsequios (`EventStatus::Drawn`); el servidor vuelve a comprobarlo.
 */
(function ($) {
    'use strict';

    $(function () {
        var $config = $('#amigo-obsequio-config');

        if (!$config.length) {
            return;
        }

        var urls = {
            tasa: $config.attr('data-url-tasa'),
            obsequio: $config.attr('data-url-obsequio'),
            obsequioModificar: $config.attr('data-url-obsequio-modificar'),
            consumibles: $config.attr('data-url-consumibles'),
            consumibleCrear: $config.attr('data-url-consumible-crear'),
            consumible: $config.attr('data-url-consumible')
        };

        var token = $config.attr('data-csrf');
        var puedeEditar = $config.attr('data-puede-editar') === '1';
        var tieneObsequio = $config.attr('data-tiene-obsequio') === '1';

        function urlFor(template, id) {
            return String(template || '').replace('__ID__', encodeURIComponent(id));
        }

        function alertDialog(message) {
            if (window.bootbox) {
                window.bootbox.alert({ title: 'Aviso', message: message, centerVertical: true });
                return;
            }

            window.alert(message);
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

            alertDialog(response.message || 'No se pudo completar la acción.');
        }

        function monto(value) {
            if (typeof value === 'string') {
                value = value.replace(',', '.');
            }

            var numero = parseFloat(value);

            return isNaN(numero) ? 0 : numero;
        }

        function actualizarTasa(response) {
            if (!response || !response.tasa_formateada) {
                return;
            }

            var texto = 'Bs. ' + response.tasa_formateada;

            $('#amigo-tasa-valor').text(texto);
            $('#consumible-tasa-valor').text(texto);
        }

        function consultarTasa(valor, $destino, $hint) {
            if (!$.trim(valor) || monto(valor) <= 0) {
                $destino.val('');

                if ($hint) {
                    $hint.text('Se calcula con la tasa oficial del BCV.').removeAttr('data-kind');
                }

                return;
            }

            $.getJSON(urls.tasa, { valor: valor }).done(function (response) {
                actualizarTasa(response);

                if (response.disponible && response.valor_bs_formateado) {
                    $destino.val(response.valor_bs_formateado);

                    if ($hint) {
                        $hint.attr('data-kind', 'ok').text('Calculado con la tasa oficial del BCV.');
                    }

                    return;
                }

                $destino.val('');

                if ($hint) {
                    $hint.attr('data-kind', 'error').text('No se pudo obtener la tasa oficial. Intenta más tarde.');
                }
            }).fail(function () {
                $destino.val('');

                if ($hint) {
                    $hint.attr('data-kind', 'error').text('No se pudo obtener la tasa oficial.');
                }
            });
        }

        /* ------------------------------------------------------------ Obsequio */

        var $nombre = $('#amigo-obsequio-nombre');
        var $valor = $('#amigo-obsequio-valor');
        var $bs = $('#amigo-obsequio-bs');
        var $bsHint = $('#amigo-obsequio-bs-hint');
        var $guardar = $('#amigo-obsequio-guardar');
        var $limpiar = $('#amigo-obsequio-limpiar');
        var $cancelar = $('#amigo-obsequio-cancelar');
        var $modificar = $('#amigo-obsequio-modificar');
        var $status = $('#amigo-obsequio-status');
        var $meta = $('#amigo-obsequio-meta');
        var $metaTexto = $('#amigo-obsequio-meta-texto');
        var $nombreError = $('#amigo-obsequio-nombre-error');
        var $valorError = $('#amigo-obsequio-valor-error');

        var guardado = {
            nombre: $nombre.val() || '',
            valor: $valor.val() || '',
            bs: $bs.val() || ''
        };

        var editando = !tieneObsequio;

        function aplicarModo() {
            var bloquear = !puedeEditar || !editando;

            $nombre.prop('disabled', bloquear);
            $valor.prop('disabled', bloquear);
            $bs.prop('disabled', bloquear);

            $guardar.prop('hidden', !(puedeEditar && editando));
            $limpiar.prop('hidden', !(puedeEditar && editando));
            $cancelar.prop('hidden', !(puedeEditar && editando && tieneObsequio));
            $modificar.prop('hidden', !(puedeEditar && !editando && tieneObsequio));
        }

        function limpiarErroresObsequio() {
            $nombreError.text('').removeAttr('data-kind');
            $valorError.text('').removeAttr('data-kind');
        }

        function marcarError($campo, $error, mensaje) {
            $error.attr('data-kind', 'error').text(mensaje);
            $campo.trigger('focus');
        }

        function validarObsequio() {
            limpiarErroresObsequio();

            var nombre = $.trim($nombre.val());
            var valor = monto($valor.val());
            var ok = true;

            if (!nombre) {
                marcarError($nombre, $nombreError, 'Este campo es obligatorio.');
                ok = false;
            }

            if (!$.trim($valor.val())) {
                marcarError($valor, $valorError, 'Este campo es obligatorio.');
                ok = false;
            } else if (valor <= 0) {
                marcarError($valor, $valorError, 'Escribe un valor mayor que cero.');
                ok = false;
            }

            return { ok: ok, nombre: nombre, valor: valor };
        }

        function guardarObsequio() {
            if (!puedeEditar) {
                return;
            }

            var data = validarObsequio();

            if (!data.ok) {
                return;
            }

            var esModificacion = tieneObsequio;

            confirmDialog(
                esModificacion ? '¿Desea modificar el obsequio?' : '¿Desea registrar el obsequio?',
                function () {
                    $guardar.prop('disabled', true);

                    $.ajax({
                        url: esModificacion ? urls.obsequioModificar : urls.obsequio,
                        method: esModificacion ? 'PUT' : 'POST',
                        dataType: 'json',
                        data: {
                            _token: token,
                            nombre: data.nombre,
                            valor: data.valor
                        }
                    }).done(function (response) {
                        var gift = response.gift || {};

                        guardado = {
                            nombre: gift.nombre || data.nombre,
                            valor: gift.valor || data.valor,
                            bs: gift.valor_bs || ''
                        };

                        tieneObsequio = true;
                        editando = false;

                        $nombre.val(guardado.nombre);
                        $valor.val(guardado.valor);
                        $bs.val(guardado.bs);

                        if (gift.fecha) {
                            $metaTexto.text('Registrado el ' + gift.fecha);
                            $meta.removeAttr('hidden');
                        }

                        actualizarTasa(gift);
                        limpiarErroresObsequio();
                        setStatus($status, response.message || 'Obsequio guardado correctamente.', 'ok');
                        aplicarModo();
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors) {
                            if (response.errors.nombre) {
                                marcarError($nombre, $nombreError, response.errors.nombre[0]);
                            }

                            if (response.errors.valor) {
                                marcarError($valor, $valorError, response.errors.valor[0]);
                            }
                        }

                        setStatus($status, response.message || 'No se pudo guardar el obsequio.', 'error');
                    }).always(function () {
                        $guardar.prop('disabled', false);
                    });
                }
            );
        }

        $guardar.on('click', guardarObsequio);

        $modificar.on('click', function () {
            if (!puedeEditar) {
                return;
            }

            editando = true;
            limpiarErroresObsequio();
            setStatus($status, '', 'info');
            aplicarModo();
            $nombre.trigger('focus');
        });

        $cancelar.on('click', function () {
            editando = false;
            $nombre.val(guardado.nombre);
            $valor.val(guardado.valor);
            $bs.val(guardado.bs);
            limpiarErroresObsequio();
            setStatus($status, '', 'info');
            aplicarModo();
        });

        $limpiar.on('click', function () {
            $nombre.val('');
            $valor.val('');
            $bs.val('');
            limpiarErroresObsequio();
            setStatus($status, '', 'info');
            $bsHint.text('Se calcula con la tasa oficial del BCV.').removeAttr('data-kind');
            aplicarModo();
            $nombre.trigger('focus');
        });

        var tasaTimer = null;

        $valor.on('input', function () {
            var raw = this.value;

            window.clearTimeout(tasaTimer);
            tasaTimer = window.setTimeout(function () {
                consultarTasa(raw, $bs, $bsHint);
            }, 350);
        });

        aplicarModo();

        if ($valor.val()) {
            consultarTasa($valor.val(), $bs, $bsHint);
        } else {
            $.getJSON(urls.tasa, { valor: 1 }).done(actualizarTasa).fail(function () {});
        }

        /* -------------------------------------------------------- Consumibles */

        function modalFor(id) {
            var element = document.getElementById(id);

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        var $tabla = $('#amigo-consumibles-table');

        if (!$tabla.length) {
            return;
        }

        var $statusConsumibles = $('#amigo-consumibles-status');

        var tabla = $tabla.DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: urls.consumibles,
                type: 'GET'
            },
            columns: [
                { data: 'fecha', className: 'amigo-col-fecha' },
                { data: 'nombre', className: 'amigo-col-texto' },
                { data: 'valor_usd', className: 'amigo-col-valor' },
                { data: 'valor_bs', className: 'amigo-col-valor' },
                { data: 'actions', orderable: false, searchable: false, className: 'amigo-col-acciones' }
            ],
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ consumibles',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ consumibles',
                infoEmpty: 'Sin consumibles',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No hay consumibles registrados',
                emptyTable: 'Todavía no has agregado consumibles',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            },
            drawCallback: function (settings) {
                actualizarTasa((settings && settings.json) || {});
            }
        });

        var modalElement = document.getElementById('modal-consumible');
        var modal = modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
        var $consumibleId = $('#consumible-id');
        var $cNombre = $('#consumible-nombre');
        var $cValor = $('#consumible-valor');
        var $cBs = $('#consumible-bs');
        var $cBsHint = $('#consumible-bs-hint');
        var $cNombreError = $('#consumible-nombre-error');
        var $cValorError = $('#consumible-valor-error');
        var $cFormError = $('#consumible-form-error');
        var $cLimpiar = $('#consumible-limpiar');
        var $cGuardar = $('#consumible-guardar');

        function limpiarFormularioConsumible() {
            $consumibleId.val('');
            $cNombre.val('');
            $cValor.val('');
            $cBs.val('');
            $cNombreError.text('').removeAttr('data-kind');
            $cValorError.text('').removeAttr('data-kind');
            $cFormError.text('').removeAttr('data-kind');
            $cBsHint.text('Se calcula con la tasa oficial del BCV.').removeAttr('data-kind');
        }

        function abrirModalConsumible(modo, data) {
            limpiarFormularioConsumible();

            $('#modal-consumible-title').text(modo === 'editar' ? 'Modificar consumible' : 'Nuevo consumible');

            if (data) {
                $consumibleId.val(data.id || '');
                $cNombre.val(data.nombre || '');
                $cValor.val(data.valor || '');
                $cBs.val(data.valor_bs || '');
            }

            if (modal) {
                modal.show();
            }
        }

        function validarConsumible() {
            $cNombreError.text('').removeAttr('data-kind');
            $cValorError.text('').removeAttr('data-kind');
            $cFormError.text('').removeAttr('data-kind');

            var nombre = $.trim($cNombre.val());
            var valor = monto($cValor.val());
            var ok = true;

            if (!nombre) {
                $cNombreError.attr('data-kind', 'error').text('Este campo es obligatorio.');
                ok = false;
            }

            if (!$.trim($cValor.val())) {
                $cValorError.attr('data-kind', 'error').text('Este campo es obligatorio.');
                ok = false;
            } else if (valor <= 0) {
                $cValorError.attr('data-kind', 'error').text('Escribe un valor mayor que cero.');
                ok = false;
            }

            if (!ok) {
                (nombre ? $cValor : $cNombre).trigger('focus');
            }

            return { ok: ok, nombre: nombre, valor: valor };
        }

        $('#amigo-consumible-nuevo').on('click', function () {
            if (!puedeEditar) {
                return;
            }

            abrirModalConsumible('crear', null);
        });

        $cLimpiar.on('click', function () {
            limpiarFormularioConsumible();
            $cNombre.trigger('focus');
        });

        $cValor.on('input', function () {
            var raw = this.value;

            window.clearTimeout(tasaTimer);
            tasaTimer = window.setTimeout(function () {
                consultarTasa(raw, $cBs, $cBsHint);
            }, 350);
        });

        $cGuardar.on('click', function () {
            if (!puedeEditar) {
                return;
            }

            var data = validarConsumible();

            if (!data.ok) {
                return;
            }

            var id = $consumibleId.val();
            var esModificacion = !!id;

            confirmDialog(
                esModificacion ? '¿Desea modificar el consumible?' : '¿Desea agregar el consumible?',
                function () {
                    $cGuardar.prop('disabled', true);

                    $.ajax({
                        url: esModificacion ? urlFor(urls.consumible, id) : urls.consumibleCrear,
                        method: esModificacion ? 'PUT' : 'POST',
                        dataType: 'json',
                        data: {
                            _token: token,
                            nombre: data.nombre,
                            valor: data.valor
                        }
                    }).done(function (response) {
                        if (modal) {
                            modal.hide();
                        }

                        setStatus($statusConsumibles, response.message || 'Consumible guardado correctamente.', 'ok');
                        tabla.ajax.reload(null, false);
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors) {
                            if (response.errors.nombre) {
                                $cNombreError.attr('data-kind', 'error').text(response.errors.nombre[0]);
                            }

                            if (response.errors.valor) {
                                $cValorError.attr('data-kind', 'error').text(response.errors.valor[0]);
                            }
                        }

                        $cFormError.attr('data-kind', 'error').text(
                            response.message || 'No se pudo guardar el consumible.'
                        );
                    }).always(function () {
                        $cGuardar.prop('disabled', false);
                    });
                }
            );
        });

        if (modalElement) {
            modalElement.addEventListener('hidden.bs.modal', function () {
                limpiarFormularioConsumible();
            });

            modalElement.addEventListener('shown.bs.modal', function () {
                $cNombre.trigger('focus');
            });
        }

        $tabla.on('click', '.btn-consumible-editar', function () {
            if (!puedeEditar) {
                return;
            }

            $.getJSON(urlFor(urls.consumible, $(this).data('id')))
                .done(function (response) {
                    abrirModalConsumible('editar', response.consumible || {});
                })
                .fail(function (xhr) {
                    showError(xhr, $statusConsumibles);
                });
        });

        $tabla.on('click', '.btn-consumible-ver', function () {
            var $body = $('#consumible-ver-body');
            var id = $(this).data('id');

            $body.html('<p class="amigo-empty">Cargando…</p>');

            var vista = modalFor('modal-consumible-ver');

            if (vista) {
                vista.show();
            }

            $.getJSON(urlFor(urls.consumible, id))
                .done(function (response) {
                    $body.html(response.html || '');
                })
                .fail(function (xhr) {
                    $body.html('');
                    showError(xhr, $statusConsumibles);
                });
        });

        $tabla.on('click', '.btn-consumible-eliminar', function () {
            if (!puedeEditar) {
                return;
            }

            var id = $(this).data('id');
            var nombre = $(this).data('nombre') || 'el consumible';

            confirmDialog('¿Desea eliminar «' + nombre + '»?', function () {
                $.ajax({
                    url: urlFor(urls.consumible, id),
                    method: 'DELETE',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    setStatus($statusConsumibles, response.message || 'Consumible eliminado correctamente.', 'ok');
                    tabla.ajax.reload(null, false);
                }).fail(function (xhr) {
                    showError(xhr, $statusConsumibles);
                });
            });
        });
    });
})(window.jQuery);
