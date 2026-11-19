/**
 * Participantes: monitoreo del evento con fichas en modal.
 *
 * - La tabla principal se pagina en el servidor; solo tiene la búsqueda libre
 *   y el evento elegido (sin criterios de filtro).
 * - El botón «Limpiar» vacía la búsqueda y devuelve la consulta al evento
 *   actual y en curso.
 * - Las fichas abren en modal: datos, lista de deseos, pistas (dos pestañas)
 *   y obsequios (regalo único y consumibles). Las tablas de los modales
 *   también se paginan en el servidor.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#participantes-config');

        if (!$config.length) {
            return;
        }

        var urls = {
            datos: $config.attr('data-url-datos'),
            ficha: $config.attr('data-url-ficha'),
            listaDeseos: $config.attr('data-url-lista-deseos'),
            pistas: $config.attr('data-url-pistas'),
            obsequios: $config.attr('data-url-obsequios'),
            consumibles: $config.attr('data-url-consumibles'),
            invitar: $config.attr('data-url-invitar'),
            expulsar: $config.attr('data-url-expulsar'),
            descartar: $config.attr('data-url-descartar')
        };

        var defaultEvent = String($config.attr('data-evento-actual') || '');

        var paginate = {
            first: 'Primera',
            last: 'Última',
            next: 'Siguiente',
            previous: 'Anterior'
        };

        function urlFor(template, id) {
            return String(template || '').replace('__ID__', encodeURIComponent(id));
        }

        function eventoActual() {
            return $('#participantes-evento').val() || '';
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

        function cargar(template, id, data, done) {
            $.getJSON(urlFor(template, id), $.extend({ evento: eventoActual() }, data || {}))
                .done(done)
                .fail(showError);
        }

        function loading() {
            return '<p class="participantes-empty">Cargando…</p>';
        }

        var participantsTable = $('#participantes-table');

        if (!participantsTable.length) {
            return;
        }

        /* ---------- Tabla principal de participantes ---------- */

        var table = participantsTable.DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: urls.datos,
                type: 'GET',
                data: function (d) {
                    d.evento = eventoActual();
                }
            },
            columns: [
                { data: 'id', className: 'roles-id' },
                { data: 'name', className: 'roles-name' },
                { data: 'email', className: 'roles-name' },
                { data: 'username', className: 'roles-name' },
                { data: 'status', orderable: false, className: 'roles-name' },
                { data: 'secret', orderable: false, className: 'roles-name' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 4, 5, 6] }
            ],
            order: [[1, 'asc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                processing: 'Cargando...',
                lengthMenu: 'Mostrar _MENU_ participantes',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ participantes',
                infoEmpty: 'Sin participantes',
                infoFiltered: '(filtrado de _MAX_)',
                zeroRecords: 'No se encontraron participantes',
                emptyTable: 'No hay participantes en este evento',
                paginate: paginate
            },
            drawCallback: function (settings) {
                var json = (settings && settings.json) || {};

                if (json.estado_html) {
                    $('#participantes-estado').html(json.estado_html);
                }
            }
        });

        var searchTimer = null;

        $('#participantes-search').on('input', function () {
            var value = this.value;

            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                table.search(value).draw();
            }, 300);
        });

        $('#participantes-evento').on('change', function () {
            table.ajax.reload(null, true);
        });

        $('#participantes-clear').on('click', function () {
            $('#participantes-search').val('');

            if (defaultEvent) {
                $('#participantes-evento').val(defaultEvent);
            }

            table.search('').ajax.reload(null, true);
            $('#participantes-search').trigger('focus');
        });

        /* ---------- Ficha del participante ---------- */

        var ficha = { id: null };

        participantsTable.on('click', '.btn-participant-view', function () {
            var id = $(this).data('id');

            ficha.id = id;
            $('#participante-ficha-body').html(loading());
            $('#participante-ficha-actions').empty();

            var instance = modalFor('modal-participante-ficha');

            if (instance) {
                instance.show();
            }

            cargar(urls.ficha, id, {}, function (response) {
                if (String(ficha.id) !== String(id)) {
                    return;
                }

                $('#participante-ficha-body').html(response.html || '');
                $('#participante-ficha-actions').html(response.actions_html || '');
            });
        });

        /* ---------- Lista de deseos ---------- */

        var deseos = { id: null };

        function abrirDeseos(id) {
            deseos.id = id;
            $('#participante-deseos-body').html(loading());

            var instance = modalFor('modal-participante-deseos');

            if (instance) {
                instance.show();
            }

            cargar(urls.listaDeseos, id, {}, function (response) {
                if (String(deseos.id) !== String(id)) {
                    return;
                }

                $('#participante-deseos-body').html(response.html || '');
            });
        }

        participantsTable.on('click', '.btn-participant-wishlist', function () {
            abrirDeseos($(this).data('id'));
        });

        function abrirDeseoDesdeUrl() {
            var params = new URLSearchParams(window.location.search);
            var participantId = params.get('participante');
            var eventId = params.get('evento');

            if (!participantId || params.get('accion') !== 'deseos') {
                return;
            }

            if (eventId && String($('#participantes-evento').val()) !== String(eventId)) {
                $('#participantes-evento').val(eventId).trigger('change');
            }

            abrirDeseos(participantId);
        }

        window.setTimeout(abrirDeseoDesdeUrl, 700);

        /* ---------- Pistas: dos pestañas paginadas ---------- */

        var pistas = { id: null, recibidas: null, enviadas: null };
        var pistasModal = document.getElementById('modal-participante-pistas');
        var pistasRecibidasTab = document.getElementById('pistas-tab-recibidas');
        var pistasEnviadasTab = document.getElementById('pistas-tab-enviadas');

        function destroyPistasTables() {
            ['#pistas-recibidas-table', '#pistas-enviadas-table'].forEach(function (selector) {
                if ($.fn.DataTable.isDataTable(selector)) {
                    $(selector).DataTable().destroy();
                }
            });

            pistas.recibidas = null;
            pistas.enviadas = null;
        }

        function resetPistasUi() {
            $('#pistas-nombre').text('—');
            $('#pistas-aviso').empty();
            $('#pistas-count-recibidas, #pistas-count-enviadas').text('');
            $('#pistas-tablas').addClass('d-none');

            if (window.bootstrap && pistasRecibidasTab) {
                window.bootstrap.Tab.getOrCreateInstance(pistasRecibidasTab).show();
            }
        }

        function pintarPistas(response) {
            var estado = response.estado || {};

            if (response.participant && response.participant.name) {
                $('#pistas-nombre').text(response.participant.name);
            }

            $('#pistas-aviso').html(response.aviso_html || '');
            $('#pistas-tablas').toggleClass('d-none', !estado.consulta);

            if (typeof response.recibidas_count === 'number') {
                $('#pistas-count-recibidas').text(
                    response.recibidas_count > 0 ? '(' + response.recibidas_count + ')' : ''
                );
            }

            if (typeof response.enviadas_count === 'number') {
                $('#pistas-count-enviadas').text(
                    response.enviadas_count > 0 ? '(' + response.enviadas_count + ')' : ''
                );
            }
        }

        function pistasTable(tipo) {
            var selector = tipo === 'enviadas' ? '#pistas-enviadas-table' : '#pistas-recibidas-table';

            if ($.fn.DataTable.isDataTable(selector)) {
                return $(selector).DataTable();
            }

            if (!pistas.id) {
                return null;
            }

            var columns = tipo === 'enviadas'
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
                ];

            var api = $(selector).DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: urlFor(urls.pistas, pistas.id),
                    type: 'GET',
                    data: function (d) {
                        d.evento = eventoActual();
                        d.tipo = tipo;
                    }
                },
                columns: columns,
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: {
                    processing: 'Cargando...',
                    lengthMenu: 'Mostrar _MENU_ pistas',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ pistas',
                    infoEmpty: 'Sin pistas',
                    infoFiltered: '(filtrado de _MAX_)',
                    zeroRecords: 'No hay pistas registradas',
                    emptyTable: 'Sin pistas registradas',
                    paginate: paginate
                },
                drawCallback: function (settings) {
                    pintarPistas((settings && settings.json) || {});
                }
            });

            if (tipo === 'enviadas') {
                pistas.enviadas = api;
            } else {
                pistas.recibidas = api;
            }

            return api;
        }

        participantsTable.on('click', '.btn-participant-clues', function () {
            var id = $(this).data('id');

            pistas.id = id;
            destroyPistasTables();
            resetPistasUi();

            var instance = modalFor('modal-participante-pistas');

            if (instance) {
                instance.show();
            }
        });

        if (pistasModal) {
            pistasModal.addEventListener('shown.bs.modal', function () {
                if (!pistas.id) {
                    return;
                }

                pistasTable('recibidas');
            });

            pistasModal.addEventListener('hidden.bs.modal', function () {
                destroyPistasTables();
            });
        }

        if (pistasEnviadasTab) {
            pistasEnviadasTab.addEventListener('shown.bs.tab', function () {
                if (!pistas.id) {
                    return;
                }

                var api = pistasTable('enviadas');

                if (api) {
                    api.columns.adjust();
                }
            });
        }

        /* ---------- Obsequios: regalo único + consumibles ---------- */

        var obsequios = { id: null, asignado: false, listo: false, tabla: null };
        var obsequiosModal = document.getElementById('modal-participante-obsequios');

        function destroyConsumibles() {
            if ($.fn.DataTable.isDataTable('#obsequios-consumibles-table')) {
                $('#obsequios-consumibles-table').DataTable().destroy();
            }

            obsequios.tabla = null;
            $('#obsequios-consumibles-totales').text('');
        }

        function pintarTotales(response) {
            var totales = response.totales || {};
            var partes = [];

            if (typeof totales.cantidad === 'number') {
                partes.push('Obsequios: ' + totales.cantidad);
            }

            if (totales.valor) {
                partes.push('Valor total: ' + totales.valor);
            }

            $('#obsequios-consumibles-totales').text(partes.join(' · '));
        }

        function consumiblesTable() {
            if ($.fn.DataTable.isDataTable('#obsequios-consumibles-table')) {
                return $('#obsequios-consumibles-table').DataTable();
            }

            if (!obsequios.id) {
                return null;
            }

            var api = $('#obsequios-consumibles-table').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: urlFor(urls.consumibles, obsequios.id),
                    type: 'GET',
                    data: function (d) {
                        d.evento = eventoActual();
                    }
                },
                columns: [
                    { data: 'fecha', className: 'col-fecha' },
                    { data: 'nombre', className: 'col-texto' },
                    { data: 'cantidad', className: 'col-numero' },
                    { data: 'valor', className: 'col-valor' }
                ],
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: {
                    processing: 'Cargando...',
                    lengthMenu: 'Mostrar _MENU_ obsequios',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ obsequios',
                    infoEmpty: 'Sin obsequios',
                    infoFiltered: '(filtrado de _MAX_)',
                    zeroRecords: 'No hay obsequios consumibles registrados',
                    emptyTable: 'Sin obsequios consumibles registrados',
                    paginate: paginate
                },
                drawCallback: function (settings) {
                    pintarTotales((settings && settings.json) || {});
                }
            });

            obsequios.tabla = api;

            return api;
        }

        function cargarObsequios() {
            var id = obsequios.id;

            if (!id) {
                return;
            }

            cargar(urls.obsequios, id, {}, function (response) {
                if (String(obsequios.id) !== String(id)) {
                    return;
                }

                var estado = response.estado || {};

                obsequios.asignado = !!estado.asignado;
                obsequios.listo = true;

                if (response.participant && response.participant.name) {
                    $('#obsequios-nombre').text(response.participant.name);
                }

                $('#obsequios-aviso').html(response.aviso_html || '');
                $('#obsequios-obsequio').html(response.html || '');
                $('#obsequios-secciones').toggleClass('d-none', !obsequios.asignado);
                pintarTotales(response);

                if (obsequios.asignado && obsequiosModal && obsequiosModal.classList.contains('show')) {
                    consumiblesTable();
                }
            });
        }

        participantsTable.on('click', '.btn-participant-gifts', function () {
            var id = $(this).data('id');

            obsequios.id = id;
            obsequios.asignado = false;
            obsequios.listo = false;

            destroyConsumibles();
            $('#obsequios-nombre').text('—');
            $('#obsequios-aviso').empty();
            $('#obsequios-obsequio').html(loading());
            $('#obsequios-secciones').addClass('d-none');

            var instance = modalFor('modal-participante-obsequios');

            if (instance) {
                instance.show();
            }

            cargarObsequios();
        });

        if (obsequiosModal) {
            obsequiosModal.addEventListener('shown.bs.modal', function () {
                if (obsequios.listo && obsequios.asignado) {
                    consumiblesTable();
                }
            });

            obsequiosModal.addEventListener('hidden.bs.modal', function () {
                destroyConsumibles();
            });
        }

        /* ---------- Fase Unión: invitar, descartar y expulsar ---------- */

        function adminUrl(template, id) {
            return String(template || '')
                .replace('__EVENT__', encodeURIComponent(eventoActual()))
                .replace('__ID__', encodeURIComponent(id));
        }

        function adminPost(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: $config.attr('data-csrf'), evento: eventoActual() }, data || {})
            });
        }

        function reloadTable() {
            table.ajax.reload(null, false);
        }

        function copyLink(text) {
            if (!text) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
                return;
            }

            var $temp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
        }

        participantsTable.on('click', '.btn-participant-link', function () {
            copyLink($(this).data('url'));
            window.AppDialog.success('Enlace personal copiado al portapapeles.');
        });

        $(document).on('click', '.btn-ficha-link', function () {
            copyLink($(this).data('url'));
            window.AppDialog.success('Enlace personal copiado al portapapeles.');
        });

        $(document).on('click', '.btn-ficha-wishlist', function () {
            abrirDeseos($(this).data('id'));
        });

        function recargarFicha(id) {
            if (String(ficha.id) !== String(id)) {
                return;
            }

            cargar(urls.ficha, id, {}, function (response) {
                if (String(ficha.id) !== String(id)) {
                    return;
                }

                $('#participante-ficha-body').html(response.html || '');
                $('#participante-ficha-actions').html(response.actions_html || '');
            });
        }

        function enviarCanalParticipante(id, channel, $button) {
            var messages = {
                email: 'Invitación enviada por correo.',
                chat: 'Invitación enviada al chat del sistema.',
                notify: 'Notificación enviada en el sistema.',
                link: 'Enlace personal copiado al portapapeles.'
            };

            $button.prop('disabled', true);

            adminPost(adminUrl(urls.invitar, id), { channel: channel })
                .done(function (response) {
                    if (channel === 'link' && response.invitation_url) {
                        copyLink(response.invitation_url);
                    }

                    reloadTable();
                    recargarFicha(id);
                    window.AppDialog.success(response.message || messages[channel] || 'Invitación enviada.');
                })
                .fail(function (xhr) {
                    $button.prop('disabled', false);
                    showError(xhr);
                });
        }

        participantsTable.on('click', '.btn-participant-email', function () {
            enviarCanalParticipante($(this).data('id'), 'email', $(this));
        });

        participantsTable.on('click', '.btn-participant-chat', function () {
            enviarCanalParticipante($(this).data('id'), 'chat', $(this));
        });

        participantsTable.on('click', '.btn-participant-notify', function () {
            enviarCanalParticipante($(this).data('id'), 'notify', $(this));
        });

        $(document).on('click', '.btn-ficha-email', function () {
            enviarCanalParticipante($(this).data('id'), 'email', $(this));
        });

        $(document).on('click', '.btn-ficha-chat', function () {
            enviarCanalParticipante($(this).data('id'), 'chat', $(this));
        });

        $(document).on('click', '.btn-ficha-notify', function () {
            enviarCanalParticipante($(this).data('id'), 'notify', $(this));
        });

        function descartarParticipante(id) {
            window.AppDialog.prompt(
                'Motivo por el que se descarta la invitación (opcional):',
                function (reason) {
                    window.AppDialog.confirm(
                        '¿Desea descartar esta invitación? La persona ya no contará como pendiente.',
                        function () {
                            adminPost(adminUrl(urls.descartar, id), { reason: reason || '' })
                                .done(function () {
                                    reloadTable();
                                    recargarFicha(id);
                                })
                                .fail(showError);
                        }
                    );
                },
                { title: 'Descartar invitación', required: false, maxlength: 1000 }
            );
        }

        participantsTable.on('click', '.btn-participant-discard', function () {
            descartarParticipante($(this).data('id'));
        });

        $(document).on('click', '.btn-ficha-discard', function () {
            descartarParticipante($(this).data('id'));
        });

        participantsTable.on('click', '.btn-participant-expel', function () {
            expulsarParticipante($(this).data('id'));
        });

        // La ficha también permite expulsar al participante activo.
        $(document).on('click', '.btn-ficha-expel', function () {
            expulsarParticipante($(this).data('id'));
        });

        function expulsarParticipante(id) {
            window.AppDialog.prompt(
                'Motivo de la expulsión (obligatorio):',
                function (reason) {
                    if (!$.trim(reason)) {
                        window.AppDialog.error('Escribe el motivo de la expulsión.');
                        return;
                    }

                    window.AppDialog.confirm(
                        '¿Desea expulsar al participante del evento?',
                        function () {
                            adminPost(adminUrl(urls.expulsar, id), { reason: reason })
                                .done(function () {
                                    reloadTable();
                                    recargarFicha(id);
                                })
                                .fail(showError);
                        },
                        { danger: true, confirmLabel: 'Expulsar' }
                    );
                },
                { title: 'Expulsar participante', required: true, maxlength: 1000 }
            );
        }
    });
})(window.jQuery);

