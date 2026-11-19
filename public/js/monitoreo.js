/**
 * Módulos de consulta de la administración: Lista de deseos, Usuarios
 * obsequiados y Pistas.
 *
 * Comparten la tabla paginada de usuarios (búsqueda, filtro por estatus de
 * participación y evento) y una ficha en modal con pestañas. Las tablas de las
 * pestañas se paginan y son de solo lectura.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#monitoreo-config');

        if (!$config.length) {
            return;
        }

        var tipo = String($config.attr('data-tipo') || '');
        var etiquetaAccion = String($config.attr('data-etiqueta-accion') || 'Ver');
        var defaultEvent = String($config.attr('data-evento-actual') || '');

        var urls = {
            datos: $config.attr('data-url-datos'),
            ficha: $config.attr('data-url-ficha'),
            deseos: $config.attr('data-url-deseos'),
            pistas: $config.attr('data-url-pistas'),
            obsequios: $config.attr('data-url-obsequios'),
            consumibles: $config.attr('data-url-consumibles')
        };

        var paginate = {
            first: 'Primera',
            last: 'Última',
            next: 'Siguiente',
            previous: 'Anterior'
        };

        var modalElement = document.getElementById('modal-monitoreo');
        var abierto = { id: null };
        var tablas = { deseos: null, consumibles: null, recibidas: null, enviadas: null };
        var obsequiosCargados = false;

        function urlFor(template, id) {
            return String(template || '').replace('__ID__', encodeURIComponent(id));
        }

        function eventoActual() {
            return $('#monitoreo-evento').val() || '';
        }

        function modalFor(id) {
            var element = document.getElementById(id);

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        function showError(xhr) {
            var response = (xhr && xhr.responseJSON) || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            window.AppDialog.error(message || 'No se pudo completar la consulta.');
        }

        function texto(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        function cargar(template, id, done, extra) {
            $.getJSON(urlFor(template, id), $.extend({ evento: eventoActual() }, extra || {}))
                .done(done)
                .fail(showError);
        }

        function lenguaje(entidad) {
            return {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ ' + entidad,
                info: 'Mostrando _START_ a _END_ de _TOTAL_ ' + entidad,
                infoEmpty: 'Sin registros',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron registros',
                emptyTable: 'Sin registros',
                paginate: paginate
            };
        }

        function limpiarCabecera(html) {
            var $html = $('<div>').html(html || '');
            $html.find('.participantes-modal__eyebrow, .participantes-modal__name').remove();

            return $html.html();
        }

        /* ------------------------------------------------ Tabla de usuarios */

        var table = $('#monitoreo-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: urls.datos,
                type: 'GET',
                data: function (data) {
                    data.evento = eventoActual();
                    data.estado = $('#monitoreo-estado').val() || '';
                }
            },
            columns: [
                { data: 'id', className: 'roles-id' },
                { data: 'name', className: 'roles-name' },
                { data: 'username', className: 'roles-name' },
                { data: 'status', orderable: false, className: 'roles-name' },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'roles-actions',
                    render: function (data, type, row) {
                        return '<button type="button" class="btn btn-sm btn-outline-secondary btn-monitoreo-open"' +
                            ' data-id="' + Number(row.id) + '" title="' + texto(etiquetaAccion) + '">' +
                            '<i class="fa-solid fa-eye" aria-hidden="true"></i>' +
                            '<span>' + texto(etiquetaAccion) + '</span></button>';
                    }
                }
            ],
            order: [[1, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: lenguaje('usuarios'),
            drawCallback: function (settings) {
                var json = (settings && settings.json) || {};

                if (json.estado_html) {
                    $('#monitoreo-estado-evento').html(json.estado_html);
                }

                if (typeof json.habilitado === 'boolean') {
                    $('#monitoreo-contenido').toggleClass('d-none', !json.habilitado);
                    $('#monitoreo-bloqueo').toggleClass('d-none', json.habilitado);
                }
            }
        });

        var searchTimer = null;

        $('#monitoreo-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#monitoreo-estado').on('change', function () {
            table.ajax.reload(null, true);
        });

        $('#monitoreo-evento').on('change', function () {
            table.ajax.reload(null, true);
        });

        $('#monitoreo-clear').on('click', function () {
            $('#monitoreo-search').val('');
            $('#monitoreo-estado').val('');

            if (defaultEvent) {
                $('#monitoreo-evento').val(defaultEvent);
            }

            table.search('').ajax.reload(null, true);
            $('#monitoreo-search').trigger('focus');
        });

        /* ------------------------------------------------------- Ficha */

        function destruirTablas() {
            ['#monitoreo-deseos table', '#monitoreo-consumibles-table',
                '#monitoreo-pistas-recibidas-table', '#monitoreo-pistas-enviadas-table']
                .forEach(function (selector) {
                    if ($.fn.DataTable.isDataTable(selector)) {
                        $(selector).DataTable().destroy();
                    }
                });

            tablas = { deseos: null, consumibles: null, recibidas: null, enviadas: null };
            obsequiosCargados = false;
        }

        function resetModal() {
            $('#monitoreo-nombre').text('—');
            $('#monitoreo-participante').html('<p class="participantes-empty">Cargando…</p>');
            $('#monitoreo-deseos').empty();
            $('#monitoreo-obsequio').empty();
            $('#monitoreo-consumibles-totales').text('');
            $('#monitoreo-consumibles-table tbody').empty();
            $('#monitoreo-pistas-recibidas-table tbody').empty();
            $('#monitoreo-pistas-enviadas-table tbody').empty();

            if (modalElement && window.bootstrap) {
                var primero = modalElement.querySelector('[data-monitoreo-tab="participante"]');

                if (primero) {
                    window.bootstrap.Tab.getOrCreateInstance(primero).show();
                }
            }
        }

        function cargarParticipante(id) {
            cargar(urls.ficha, id, function (response) {
                if (String(abierto.id) !== String(id)) {
                    return;
                }

                if (response.participant && response.participant.name) {
                    $('#monitoreo-nombre').text(response.participant.name);
                }

                $('#monitoreo-participante').html(limpiarCabecera(response.html || ''));
            });
        }

        function abrirFicha(id) {
            abierto.id = id;
            destruirTablas();
            resetModal();

            var instance = modalFor('modal-monitoreo');

            if (instance) {
                instance.show();
            }

            cargarParticipante(id);
        }

        table.on('click', '.btn-monitoreo-open', function () {
            abrirFicha($(this).data('id'));
        });

        /* ----------------------------------------------- Lista de deseos */

        function cargarDeseos() {
            var id = abierto.id;

            if (!id || tablas.deseos) {
                return;
            }

            cargar(urls.deseos, id, function (response) {
                if (String(abierto.id) !== String(id)) {
                    return;
                }

                $('#monitoreo-deseos').html(limpiarCabecera(response.html || ''));

                var $tabla = $('#monitoreo-deseos table');

                if ($tabla.length) {
                    tablas.deseos = $tabla.DataTable({
                        autoWidth: false,
                        pageLength: 10,
                        lengthMenu: [[10, 25, 50], [10, 25, 50]],
                        searching: true,
                        dom: 'lrtip',
                        language: lenguaje('deseos')
                    });
                }
            });
        }

        /* -------------------------------------------------- Obsequios */

        function cargarObsequios() {
            var id = abierto.id;

            if (!id || obsequiosCargados) {
                return;
            }

            obsequiosCargados = true;

            cargar(urls.obsequios, id, function (response) {
                if (String(abierto.id) !== String(id)) {
                    return;
                }

                $('#monitoreo-obsequio').html(limpiarCabecera(response.html || ''));
            }, { rol: 'recibido' });
        }

        function cargarConsumibles() {
            var id = abierto.id;

            if (!id || tablas.consumibles) {
                return;
            }

            tablas.consumibles = $('#monitoreo-consumibles-table').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: urlFor(urls.consumibles, id),
                    type: 'GET',
                    data: function (data) {
                        data.evento = eventoActual();
                        data.rol = 'recibido';
                    }
                },
                columns: [
                    { data: 'fecha', className: 'col-fecha' },
                    { data: 'nombre', className: 'col-texto' },
                    { data: 'cantidad', className: 'col-numero' },
                    { data: 'valor', className: 'col-valor' },
                    { data: 'valor_bs', className: 'col-valor' }
                ],
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: lenguaje('consumibles'),
                drawCallback: function (settings) {
                    var json = (settings && settings.json) || {};
                    var totales = json.totales || {};
                    var partes = [];

                    if (typeof totales.cantidad === 'number') {
                        partes.push('Obsequios: ' + totales.cantidad);
                    }

                    if (totales.valor) {
                        partes.push('Valor total: ' + totales.valor);
                    }

                    $('#monitoreo-consumibles-totales').text(partes.join(' · '));
                }
            });
        }

        /* ---------------------------------------------------- Pistas */

        function cargarPistas(tipoPistas) {
            var id = abierto.id;

            if (!id || tablas[tipoPistas]) {
                return;
            }

            var esEnviadas = tipoPistas === 'enviadas';
            var selector = esEnviadas
                ? '#monitoreo-pistas-enviadas-table'
                : '#monitoreo-pistas-recibidas-table';

            tablas[tipoPistas] = $(selector).DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: urlFor(urls.pistas, id),
                    type: 'GET',
                    data: function (data) {
                        data.evento = eventoActual();
                        data.tipo = tipoPistas;
                    }
                },
                columns: esEnviadas
                    ? [
                        { data: 'fecha', className: 'col-fecha' },
                        { data: 'para', orderable: false, className: 'col-texto' },
                        { data: 'pista', className: 'col-texto' },
                        { data: 'leida', orderable: false, className: 'col-estado' }
                    ]
                    : [
                        { data: 'fecha', className: 'col-fecha' },
                        { data: 'pista', className: 'col-texto' },
                        { data: 'leida', orderable: false, className: 'col-estado' }
                    ],
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: lenguaje('pistas')
            });
        }

        /* -------------------------------------------------- Pestañas */

        if (modalElement) {
            modalElement.querySelectorAll('[data-monitoreo-tab]').forEach(function (boton) {
                boton.addEventListener('shown.bs.tab', function () {
                    var tab = boton.getAttribute('data-monitoreo-tab') || '';

                    if (tab === 'deseos') {
                        cargarDeseos();
                        return;
                    }

                    if (tab === 'obsequio') {
                        cargarObsequios();
                        return;
                    }

                    if (tab === 'consumibles') {
                        cargarConsumibles();
                        return;
                    }

                    if (tab === 'recibidas' || tab === 'enviadas') {
                        cargarPistas(tab);
                    }
                });
            });

            modalElement.addEventListener('hidden.bs.modal', function () {
                destruirTablas();
                abierto.id = null;
            });
        }
    });
})(window.jQuery);
