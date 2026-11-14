/**
 * Moderación: reportes del chat, bloqueos entre usuarios y vetados de grupo.
 *
 * La pantalla tiene tres pestañas y dos modales de dos listas. Cada modal
 * muestra, a la izquierda, a las personas sin la sanción y, a la derecha, a
 * las que la tienen. Cada movimiento (botón o arrastre) guarda la lista
 * completa de los dos lados en el endpoint de sincronización.
 *
 * Español literal, controles grandes y sin animaciones.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#moderacion-config');

        if (!$config.length) {
            return;
        }

        var csrf = $config.attr('data-csrf');

        /* ------------------------------------------------------------------
           Pestañas
           ------------------------------------------------------------------ */

        var $tabs = $('.moderacion-tab');
        var $panels = $('.moderacion-panel');
        var openTab = $.noop;

        function activateTab(name) {
            $tabs.each(function () {
                var active = $(this).attr('data-tab') === name;

                $(this).toggleClass('is-active', active)
                    .attr('aria-selected', active ? 'true' : 'false');
            });

            $panels.each(function () {
                $(this).prop('hidden', $(this).attr('data-panel') !== name);
            });

            openTab(name);
        }

        $tabs.on('click', function () {
            activateTab($(this).attr('data-tab'));
        });

        $('.moderacion-tabs').on('keydown', '.moderacion-tab', function (event) {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                return;
            }

            event.preventDefault();

            var $items = $tabs.filter(':visible');
            var index = $items.index(this);
            var next = event.key === 'ArrowRight' ? index + 1 : index - 1;

            if (next < 0) {
                next = $items.length - 1;
            }

            if (next >= $items.length) {
                next = 0;
            }

            $items.eq(next).trigger('focus').trigger('click');
        });

        /* ------------------------------------------------------------------
           Reportes (solo lectura)
           ------------------------------------------------------------------ */

        var modalElement = document.getElementById('modal-moderacion-detail');
        var modal = modalElement ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
        var takeElement = document.getElementById('modal-moderacion-take');
        var takeModal = takeElement ? window.bootstrap.Modal.getOrCreateInstance(takeElement) : null;
        var closeElement = document.getElementById('modal-moderacion-close');
        var closeModal = closeElement ? window.bootstrap.Modal.getOrCreateInstance(closeElement) : null;
        var referElement = document.getElementById('modal-moderacion-refer');
        var referModal = referElement ? window.bootstrap.Modal.getOrCreateInstance(referElement) : null;
        var tomarUrl = $config.attr('data-url-tomar') || '';
        var cerrarUrl = $config.attr('data-url-cerrar') || '';
        var remitirUrl = $config.attr('data-url-remitir') || '';
        var adminsUrl = $config.attr('data-url-admins') || '';
        var detailReportId = null;
        var detailTicketChatId = null;

        function escapeText(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        /**
         * Datos de la fila a la que pertenece un botón de la tabla.
         *
         * En móvil, phablet y tablet DataTables Responsive oculta columnas y
         * pinta los botones dentro de una fila hija (`tr.child`); hay que subir
         * a la fila real para poder leer sus datos.
         */
        function rowDataFor(table, $element) {
            var $row = $element.closest('tr');

            if (!$row.length) {
                return null;
            }

            if ($row.hasClass('child') || $row.hasClass('dtrgrow')) {
                $row = $row.prev('tr');
            }

            return table.row($row).data() || null;
        }

        function reportFilterData() {
            return {
                filter_type: $('#moderacion-filter-type').val() || 'all',
                date_from: $('#moderacion-filter-from').val() || '',
                date_to: $('#moderacion-filter-to').val() || '',
                status: $('#moderacion-filter-status').val() || '',
                manager_id: $('#moderacion-filter-manager').val() || '',
                referred_to_id: $('#moderacion-filter-referred').val() || ''
            };
        }

        /* ------------------------------------------------------------------
           Filtros de administrador con Select2

           El <select> sigue siendo el valor del filtro (lo lee
           reportFilterData). Select2 le agrega el buscador por nombre o
           @usuario y se inicializa al mostrar el filtro, no antes: así mide
           bien su ancho y no se carga en las otras pestañas.
           ------------------------------------------------------------------ */

        function select2FilterOptions() {
            return {
                theme: 'bootstrap-5',
                width: '100%',
                language: {
                    noResults: function () {
                        return 'Sin coincidencias';
                    },
                    searching: function () {
                        return 'Buscando...';
                    }
                },
                // Además del nombre, busca dentro del usuario de la cuenta.
                matcher: function (params, data) {
                    var term = $.trim(params.term || '').toLowerCase();

                    if (term === '') {
                        return data;
                    }

                    var username = $(data.element).attr('data-username') || '';
                    var haystack = (data.text + ' ' + username).toLowerCase();

                    return haystack.indexOf(term) === -1 ? null : data;
                },
                templateResult: function (data) {
                    if (!data.id) {
                        return data.text;
                    }

                    var $option = $('<span class="moderacion-select2-option">')
                        .append($('<span>').text(data.text));
                    var username = $(data.element).attr('data-username');

                    if (username) {
                        $option.append($('<small>').text('@' + username));
                    }

                    return $option;
                }
            };
        }

        function isSelect2Ready($select) {
            return $select.length > 0 && $.fn.select2 !== undefined
                && $select.hasClass('select2-hidden-accessible');
        }

        function activateFilterSelect2(selector) {
            var $select = $(selector);

            if (!$select.length || $.fn.select2 === undefined) {
                return;
            }

            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }

            $select.select2(select2FilterOptions());
        }

        function closeFilterSelect2(selector) {
            var $select = $(selector);

            if (isSelect2Ready($select)) {
                $select.select2('close');
            }
        }

        // Deja el select en "todos" sin disparar la recarga del listado.
        function resetFilterSelect2(selector) {
            var $select = $(selector);

            if (!$select.length) {
                return;
            }

            $select.val('');

            if (isSelect2Ready($select)) {
                $select.trigger('change.select2');
            }
        }

        /* Select2 elige la dirección del desplegable al abrirlo y la conserva:
           si el combo se abrió cerca del borde inferior queda hacia arriba
           aunque después se desplace la página y ya haya espacio abajo. Aquí
           se recoloca en cada desplazamiento, siempre abajo si cabe y arriba
           solo cuando no hay otra opción. */
        var repositionTimer = null;

        // En diferido: Select2 también recoloca en su propio manejador de scroll
        // y su decisión no debe pisar esta corrección. Un temporizador de 0 ms
        // se ejecuta después de esos manejadores y antes de pintar.
        function runAfterScrollHandlers(callback) {
            return window.setTimeout(callback, 0);
        }

        function placeFilterDropdown(id) {
            var $select = $('#' + id);
            var results = document.getElementById('select2-' + id + '-results');

            if (!results || !isSelect2Ready($select)) {
                return;
            }

            var $control = $select.closest('.matriz-filter').find('.select2-container');
            var $panel = $(results).closest('.select2-dropdown');

            if (!$control.hasClass('select2-container--open') || !$panel.length) {
                return;
            }

            var rect = $control[0].getBoundingClientRect();
            var panelRect = $panel[0].getBoundingClientRect();
            var height = panelRect.height;
            var roomBelow = window.innerHeight - rect.bottom;
            var roomAbove = rect.top;
            var above = roomBelow < height && roomAbove > roomBelow;
            var delta = Math.round((above ? rect.top - height : rect.bottom) - panelRect.top);

            // Solo se corrige la diferencia: el desplegable de Select2 se cuelga
            // del body y su posición real no siempre está en coordenadas del
            // documento, así que moverlo por diferencia evita suponerlo.
            if (delta !== 0) {
                $panel.parent().css('top', '+=' + delta + 'px');
            }

            $control.toggleClass('select2-container--above', above)
                .toggleClass('select2-container--below', !above);
            $panel.toggleClass('select2-dropdown--above', above)
                .toggleClass('select2-dropdown--below', !above);
        }

        function placeOpenFilterDropdowns() {
            placeFilterDropdown('moderacion-filter-manager');
            placeFilterDropdown('moderacion-filter-referred');
        }

        function scheduleFilterReposition() {
            if (repositionTimer !== null) {
                return;
            }

            // En diferido, después de que Select2 haga su propio reajuste:
            // su decisión no debe pisar esta.
            repositionTimer = runAfterScrollHandlers(function () {
                repositionTimer = null;
                placeOpenFilterDropdowns();
            });
        }

        function watchFilterDropdowns() {
            if ($.fn.select2 === undefined) {
                return;
            }

            $('#moderacion-filter-manager, #moderacion-filter-referred').on('select2:open', function () {
                runAfterScrollHandlers(placeOpenFilterDropdowns);
            });

            $(window).on('scroll.filterSelect2 resize.filterSelect2', scheduleFilterReposition);
        }

        watchFilterDropdowns();

        function toggleReportFilter() {
            var type = $('#moderacion-filter-type').val() || 'all';
            var manager = type === 'manager';
            var referred = type === 'referred';

            $('#moderacion-filter-from-wrap, #moderacion-filter-to-wrap')
                .toggleClass('d-none', type !== 'date');
            $('#moderacion-filter-status-wrap').toggleClass('d-none', type !== 'status');
            $('#moderacion-filter-manager-wrap').toggleClass('d-none', !manager);
            $('#moderacion-filter-referred-wrap').toggleClass('d-none', !referred);

            if (manager) {
                activateFilterSelect2('#moderacion-filter-manager');
            } else {
                closeFilterSelect2('#moderacion-filter-manager');
            }

            if (referred) {
                activateFilterSelect2('#moderacion-filter-referred');
            } else {
                closeFilterSelect2('#moderacion-filter-referred');
            }
        }

        var reportesTable = $('#moderacion-table').DataTable({
            serverSide: true,
            processing: true,
            autoWidth: true,
            ajax: {
                url: $config.attr('data-url-datos'),
                type: 'GET',
                data: function (data) {
                    $.extend(data, reportFilterData());
                }
            },
            columns: [
                { data: 'ticket', className: 'roles-id' },
                { data: 'date' },
                { data: 'status' },
                { data: 'manager' },
                { data: 'referred_to' },
                { data: 'reporter' },
                { data: 'author' },
                { data: 'reason' },
                { data: 'conversation' },
                { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
            ],
            columnDefs: [
                { orderable: false, targets: [8, 9] }
            ],
            order: [[1, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            searching: true,
            dom: 'lrtip',
            language: {
                emptyTable: 'No hay reportes registrados.',
                info: 'Mostrando _START_ a _END_ de _TOTAL_',
                infoEmpty: 'Sin reportes',
                infoFiltered: '(filtrado de _MAX_)',
                lengthMenu: 'Mostrar _MENU_',
                loadingRecords: 'Cargando reportes...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron reportes',
                paginate: {
                    first: 'Primera',
                    last: 'Última',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            }
        });

        $('#moderacion-search').on('input', function () {
            reportesTable.search(this.value).draw();
        });

        $('#moderacion-clear').on('click', function () {
            $('#moderacion-search').val('');
            $('#moderacion-filter-type').val('all');
            $('#moderacion-filter-from').val('');
            $('#moderacion-filter-to').val('');
            $('#moderacion-filter-status').val('pendiente');
            resetFilterSelect2('#moderacion-filter-manager');
            resetFilterSelect2('#moderacion-filter-referred');
            toggleReportFilter();
            reportesTable.search('').ajax.reload(null, true);
        });

        $('#moderacion-filter-manager, #moderacion-filter-referred').on('change', function () {
            reportesTable.ajax.reload(null, true);
        });

        $('#moderacion-filter-type').on('change', function () {
            toggleReportFilter();
            reportesTable.ajax.reload(null, true);
        });

        $('#moderacion-filter-from, #moderacion-filter-to, #moderacion-filter-status').on('change', function () {
            reportesTable.ajax.reload(null, true);
        });

        toggleReportFilter();

        $('#moderacion-table').on('click', '.moderacion-detail', function () {
            var row = rowDataFor(reportesTable, $(this));

            if (!row) {
                return;
            }

            detailReportId = row.id;
            detailTicketChatId = row.ticket_chat_id || null;

            $('#modal-moderacion-detail-title').text(
                'Detalle del reporte Nº: ' + (row.ticket || row.id)
            );
            $('#moderacion-detail-ticket').text(row.ticket || row.id || '—');
            $('#moderacion-detail-date').text(row.date || '—');
            $('#moderacion-detail-status').html(row.status_html || row.status || '—');
            $('#moderacion-detail-assigned').text(row.assigned_raw || '—');
            $('#moderacion-detail-manager').text(row.manager_raw || '—');
            $('#moderacion-detail-referred').text(row.referred_to_raw || '—');
            $('#moderacion-detail-taken').text(row.taken_at || '—');
            $('#moderacion-detail-attention').text(row.attention_observation || '—');
            $('#moderacion-detail-reporter').text(row.reporter_raw || row.reporter || '—');
            $('#moderacion-detail-author').text(row.author_raw || row.author || '—');
            $('#moderacion-detail-conversation').text(row.conversation_raw || row.conversation || '—');
            $('#moderacion-detail-reason').text(row.reason_full || row.reason || '—');
            $('#moderacion-detail-message').text(row.message_full || row.message || '—');
            $('#moderacion-detail-closed-at').text(row.closed_at || '—');
            $('#moderacion-detail-closed-by').text(row.closed_by_name || '—');
            $('#moderacion-detail-close-observation').text(row.close_observation || '—');

            $('#moderacion-detail-take').prop('hidden', !row.can_take);
            $('#moderacion-detail-close').prop('hidden', !row.can_close);
            // Remitir: solo el administrador que gestiona el caso.
            $('#moderacion-detail-refer').prop('hidden', !row.can_refer);

            renderReferrals(row.referrals);

            var $chat = $('#moderacion-detail-chat');

            if (row.conversation_url && row.status_class === 'atendiendo') {
                $chat.attr('href', row.conversation_url).prop('hidden', false);
            } else {
                $chat.prop('hidden', true).removeAttr('href');
            }

            // Chat del ticket: existe desde que el caso se toma y se mantiene
            // (en solo lectura) después de cerrarlo.
            var $ticketChat = $('#moderacion-detail-ticket-chat');

            if (row.ticket_chat_url) {
                $ticketChat.attr('href', row.ticket_chat_url).prop('hidden', false);
            } else {
                $ticketChat.prop('hidden', true).removeAttr('href');
            }

            if (modal) {
                modal.show();
            }
        });

        $('#moderacion-detail-take').on('click', function () {
            if (!detailReportId) {
                return;
            }

            $('#moderacion-take-observation').val('');
            $('#moderacion-take-error').text('').removeAttr('data-kind');

            if (takeModal) {
                takeModal.show();
            }
        });

        $('#moderacion-detail-close').on('click', function () {
            if (!detailReportId) {
                return;
            }

            $('#moderacion-close-observation').val('');
            $('#moderacion-close-confirmed').prop('checked', false);
            $('#moderacion-close-error').text('').removeAttr('data-kind');

            if (closeModal) {
                closeModal.show();
            }
        });

        function caseError(xhr) {
            var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};

            return response.message || 'No se pudo completar la operación.';
        }

        /* ------------------------------------------------------------------
           Remitir el caso a otro administrador
           ------------------------------------------------------------------ */

        var referAdmins = null;
        var referAdminId = null;
        var referActive = -1;

        function renderReferrals(list) {
            var $box = $('#moderacion-detail-referrals');
            var items = list || [];

            if (!items.length) {
                $box.html('<p class="moderacion-referrals__empty">Sin remisiones.</p>');
                return;
            }

            $box.html(items.map(function (item) {
                return '<div class="moderacion-referral">' +
                    '<p class="moderacion-referral__head">' + escapeText(item.date) +
                    ' · ' + escapeText(item.from) + ' → ' + escapeText(item.to) + '</p>' +
                    '<p class="moderacion-referral__body">' + escapeText(item.observation) + '</p>' +
                    '</div>';
            }).join(''));
        }

        function referFilter(term) {
            var needle = $.trim(String(term || '')).toLowerCase();

            return (referAdmins || []).filter(function (admin) {
                if (!needle) {
                    return true;
                }

                return (admin.name + ' ' + admin.username).toLowerCase().indexOf(needle) !== -1;
            });
        }

        function renderReferOptions(term) {
            var items = referFilter(term);
            var $list = $('#moderacion-refer-list');

            referActive = -1;

            if (!items.length) {
                $list.html('<p class="moderacion-combobox__empty">Sin coincidencias.</p>').prop('hidden', false);
                return;
            }

            $list.html(items.map(function (admin) {
                return '<button type="button" class="moderacion-combobox__option" role="option"' +
                    ' data-id="' + admin.id + '" data-name="' + escapeText(admin.name) + '">' +
                    '<span>' + escapeText(admin.name) + '</span>' +
                    '<small>@' + escapeText(admin.username) + '</small>' +
                    '</button>';
            }).join('')).prop('hidden', false);
        }

        function selectReferAdmin($option) {
            referAdminId = parseInt($option.attr('data-id'), 10) || null;
            $('#moderacion-refer-admin-id').val(referAdminId || '');
            $('#moderacion-refer-search').val($option.attr('data-name') || '');
            $('#moderacion-refer-selected')
                .text(referAdminId ? 'Remitir a: ' + ($option.attr('data-name') || '') : '')
                .removeAttr('data-kind');
            $('#moderacion-refer-list').prop('hidden', true).empty();
            $('#moderacion-refer-search').attr('aria-expanded', 'false');
        }

        function closeReferOptions() {
            $('#moderacion-refer-list').prop('hidden', true);
            $('#moderacion-refer-search').attr('aria-expanded', 'false');
            referActive = -1;
        }

        function loadReferAdmins() {
            if (referAdmins !== null) {
                renderReferOptions($('#moderacion-refer-search').val());
                return;
            }

            $.getJSON(adminsUrl).done(function (response) {
                referAdmins = (response && response.items) || [];
                renderReferOptions($('#moderacion-refer-search').val());
            }).fail(function () {
                referAdmins = [];
                renderReferOptions($('#moderacion-refer-search').val());
            });
        }

        $('#moderacion-detail-refer').on('click', function () {
            if (!detailReportId) {
                return;
            }

            referAdminId = null;
            $('#moderacion-refer-admin-id').val('');
            $('#moderacion-refer-search').val('');
            $('#moderacion-refer-selected').text('');
            $('#moderacion-refer-observation').val('');
            $('#moderacion-refer-error').text('').removeAttr('data-kind');
            closeReferOptions();

            loadReferAdmins();

            if (referModal) {
                referModal.show();
            }

            // Se oculta la ficha al abrir la remisión (orden inverso para que
            // Bootstrap no deje las dos ventanas apiladas).
            if (modal) {
                modal.hide();
            }
        });

        $('#moderacion-refer-search').on('input', function () {
            referAdminId = null;
            $('#moderacion-refer-admin-id').val('');
            $('#moderacion-refer-selected').text('');
            renderReferOptions($(this).val());
            $(this).attr('aria-expanded', 'true');
        });

        $('#moderacion-refer-search').on('focus', function () {
            loadReferAdmins();
            renderReferOptions($(this).val());
            $(this).attr('aria-expanded', 'true');
        });

        $('#moderacion-refer-search').on('keydown', function (event) {
            var $options = $('#moderacion-refer-list .moderacion-combobox__option');

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!$options.length) {
                    return;
                }

                event.preventDefault();
                referActive = event.key === 'ArrowDown'
                    ? (referActive + 1) % $options.length
                    : (referActive - 1 + $options.length) % $options.length;

                $options.removeClass('is-active').eq(referActive).addClass('is-active');
                return;
            }

            if (event.key === 'Enter' && referActive > -1) {
                event.preventDefault();
                selectReferAdmin($options.eq(referActive));
                return;
            }

            if (event.key === 'Escape') {
                closeReferOptions();
            }
        });

        $('#moderacion-refer-list').on('click', '.moderacion-combobox__option', function () {
            selectReferAdmin($(this));
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#moderacion-refer-combobox').length) {
                closeReferOptions();
            }
        });

        $('#moderacion-refer-confirm').on('click', function () {
            if (!detailReportId || !remitirUrl) {
                return;
            }

            var $button = $(this);
            var $error = $('#moderacion-refer-error');
            var observation = $.trim($('#moderacion-refer-observation').val());

            $error.text('').removeAttr('data-kind');

            if (!referAdminId) {
                $error.attr('data-kind', 'error').text('Elige el administrador al que remites el caso.');
                return;
            }

            if (!observation) {
                $error.attr('data-kind', 'error').text('Escribe la observación de la remisión.');
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: remitirUrl.replace('__ID__', detailReportId),
                method: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                data: {
                    admin_id: referAdminId,
                    observation: observation
                }
            }).done(function (response) {
                if (referModal) {
                    referModal.hide();
                }

                // La ficha queda con datos viejos: se cierra.
                if (modal) {
                    modal.hide();
                }

                reportesTable.ajax.reload(null, false);
                refreshModerationCounts();

                // El chat del caso deja de estar en nuestra cuenta: si estaba
                // abierto en el chat flotante, se cierra.
                if (detailTicketChatId) {
                    document.dispatchEvent(new CustomEvent('chat:leave', {
                        detail: { id: parseInt(detailTicketChatId, 10) }
                    }));
                }

                if (response && response.message && window.bootbox) {
                    window.bootbox.alert({
                        title: 'Aviso',
                        message: response.message,
                        centerVertical: true
                    });
                }
            }).fail(function (xhr) {
                $error.attr('data-kind', 'error').text(caseError(xhr));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });

        $('#moderacion-take-confirm').on('click', function () {
            if (!detailReportId || !tomarUrl) {
                return;
            }

            var $button = $(this);
            var $error = $('#moderacion-take-error');

            $button.prop('disabled', true);
            $error.text('').removeAttr('data-kind');

            $.ajax({
                url: tomarUrl.replace('__ID__', detailReportId),
                method: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                data: {
                    attention_observation: $('#moderacion-take-observation').val()
                }
            }).done(function (response) {
                if (takeModal) {
                    takeModal.hide();
                }

                if (modal) {
                    modal.hide();
                }

                reportesTable.ajax.reload(null, false);
                refreshModerationCounts();

                // El caso ya tiene chat: se abre en la pestaña Reportes.
                var chatId = response && (response.ticket_chat_id
                    || (response.report && response.report.ticket_chat_id));

                if (chatId) {
                    document.dispatchEvent(new CustomEvent('chat:open', {
                        detail: { id: parseInt(chatId, 10) }
                    }));
                }
            }).fail(function (xhr) {
                $error.attr('data-kind', 'error').text(caseError(xhr));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });

        $('#moderacion-close-confirm').on('click', function () {
            if (!detailReportId || !cerrarUrl) {
                return;
            }

            var observation = $.trim($('#moderacion-close-observation').val());
            var confirmed = $('#moderacion-close-confirmed').is(':checked');
            var $error = $('#moderacion-close-error');

            $error.text('').removeAttr('data-kind');

            if (!observation) {
                $error.attr('data-kind', 'error').text('Escribe la observación de cierre.');
                return;
            }

            if (!confirmed) {
                $error.attr('data-kind', 'error').text('Confirma que el reporte quedó cerrado.');
                return;
            }

            var $button = $(this);

            $button.prop('disabled', true);

            $.ajax({
                url: cerrarUrl.replace('__ID__', detailReportId),
                method: 'POST',
                dataType: 'json',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                data: {
                    close_observation: observation,
                    confirmed: 1
                }
            }).done(function () {
                if (closeModal) {
                    closeModal.hide();
                }

                if (modal) {
                    modal.hide();
                }

                reportesTable.ajax.reload(null, false);
                refreshModerationCounts();
            }).fail(function (xhr) {
                $error.attr('data-kind', 'error').text(caseError(xhr));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });

        /* ------------------------------------------------------------------
           Modal de dos listas (bloqueos y vetados)
           ------------------------------------------------------------------ */

        function Transfer(element) {
            var $modal = $(element);
            var $root = $modal.find('[data-transfer]');

            if (!$root.length) {
                return null;
            }

            var kind = $root.attr('data-kind');
            var rightKey = $root.attr('data-right-key');
            var subjectPrefix = $root.attr('data-subject-prefix') || '';
            var showMember = $root.attr('data-show-member') === '1';
            var detailUrl = $root.attr('data-url-detalle') || '';
            var syncUrl = $root.attr('data-url-sincronizar') || '';

            var $leftList = $root.find('[data-side="available"] [data-list]');
            var $rightList = $root.find('[data-side="right"] [data-list]');
            var $status = $root.find('[data-transfer-status]');
            var $subject = $modal.find('[data-transfer-subject]');
            var $save = $modal.find('[data-transfer-save]');
            var $confirm = $root.find('[data-transfer-confirm]');
            var $confirmText = $root.find('[data-transfer-confirm-text]');
            var $confirmYes = $root.find('[data-transfer-confirm-yes]');
            var $confirmNo = $root.find('[data-transfer-confirm-no]');

            var state = { available: [], right: [] };
            var baseline = null;
            var currentId = null;
            var currentLabel = '';
            var selected = null;
            var anchor = null;
            var dragging = null;
            var saving = false;
            var confirming = false;

            function idsOf(items) {
                return items.map(function (item) {
                    return item.id;
                });
            }

            function sortedIds(items) {
                return idsOf(items).sort(function (first, second) {
                    return first - second;
                });
            }

            function snapshot() {
                return {
                    available: sortedIds(state.available),
                    right: sortedIds(state.right)
                };
            }

            function sameIds(first, second) {
                if (!first || !second || first.length !== second.length) {
                    return false;
                }

                for (var index = 0; index < first.length; index++) {
                    if (first[index] !== second[index]) {
                        return false;
                    }
                }

                return true;
            }

            function hasChanges() {
                if (!baseline) {
                    return false;
                }

                var current = snapshot();

                return !sameIds(baseline.available, current.available) ||
                    !sameIds(baseline.right, current.right);
            }

            function updateSave() {
                $save.prop('disabled', saving || confirming || !hasChanges());
            }

            function sortState() {
                function byName(first, second) {
                    return String(first.name || '').localeCompare(String(second.name || ''), 'es');
                }

                state.available.sort(byName);
                state.right.sort(byName);
            }

            function setStatus(message, kindOf) {
                $status.text(message || '').attr('data-kind', kindOf || 'info');
            }

            function renderList($list, items, emptyText) {
                $list.empty();

                if (!items.length) {
                    $list.append(
                        $('<li class="moderacion-transfer__empty">').text(emptyText)
                    );

                    return;
                }

                items.forEach(function (item) {
                    var $label = $('<span class="moderacion-transfer__label">');

                    $('<span class="moderacion-transfer__name">')
                        .text(item.name || 'Sin nombre')
                        .appendTo($label);

                    if (item.username) {
                        $('<span class="moderacion-transfer__meta">').text('@' + item.username).appendTo($label);
                    }

                    if (showMember && item.member) {
                        $('<span class="moderacion-transfer__tag">').text('En el grupo').appendTo($label);
                    }

                    $('<li class="moderacion-transfer__item">')
                        .attr('data-id', item.id)
                        .attr('role', 'option')
                        .attr('tabindex', '0')
                        .attr('aria-selected', 'false')
                        .attr('draggable', 'true')
                        .append($label)
                        .appendTo($list);
                });
            }

            function listOfSide(side) {
                return side === 'available' ? $leftList : $rightList;
            }

            function itemsOfSide(side) {
                return listOfSide(side).find('li[data-id]');
            }

            function isChosen(side, id) {
                return !!selected && selected.side === side && selected.ids.indexOf(id) !== -1;
            }

            function applySelection() {
                $root.find('.moderacion-transfer__item').each(function () {
                    var $item = $(this);
                    var side = stateKeyOf($item.closest('[data-list]'));
                    var chosen = isChosen(side, Number($item.attr('data-id')));

                    $item.toggleClass('is-selected', chosen)
                        .attr('aria-selected', chosen ? 'true' : 'false');
                });
            }

            /* La lista derecha usa la clave del servidor (blocked/banned),
               pero el estado interno siempre se llama «right». */
            function stateKeyOf($list) {
                return $list.attr('data-list') === 'available' ? 'available' : 'right';
            }

            /* Ids del tramo entre dos personas, en el orden en que se ven. */
            function idsBetween(side, fromId, toId) {
                var ids = itemsOfSide(side).map(function () {
                    return Number($(this).attr('data-id'));
                }).get();

                var from = ids.indexOf(fromId);
                var to = ids.indexOf(toId);

                if (from === -1 || to === -1) {
                    return [toId];
                }

                return ids.slice(Math.min(from, to), Math.max(from, to) + 1);
            }

            /**
             * Selección de personas: un clic elige una; con Shift se toma el
             * tramo desde la última elegida y con Ctrl (o Cmd) se suman o
             * quitan personas sueltas.
             */
            function selectItem($item, event) {
                var side = stateKeyOf($item.closest('[data-list]'));
                var id = Number($item.attr('data-id'));
                var shift = !!(event && event.shiftKey);
                var multiple = !!(event && (event.ctrlKey || event.metaKey));

                if (shift && anchor && anchor.side === side) {
                    selected = { side: side, ids: idsBetween(side, anchor.id, id) };
                    applySelection();

                    return;
                }

                if (multiple) {
                    var ids = selected && selected.side === side ? selected.ids.slice() : [];
                    var index = ids.indexOf(id);

                    if (index === -1) {
                        ids.push(id);
                    } else {
                        ids.splice(index, 1);
                    }

                    selected = ids.length ? { side: side, ids: ids } : null;
                    anchor = { side: side, id: id };
                    applySelection();

                    return;
                }

                if (isChosen(side, id) && selected.ids.length === 1) {
                    selected = null;
                } else {
                    selected = { side: side, ids: [id] };
                }

                anchor = { side: side, id: id };
                applySelection();
            }

            function render() {
                renderList(
                    $leftList,
                    state.available,
                    kind === 'vetados' ? 'No hay personas disponibles.' : 'Todas las personas están bloqueadas.'
                );
                renderList(
                    $rightList,
                    state.right,
                    kind === 'vetados' ? 'No hay personas vetadas.' : 'No hay personas bloqueadas.'
                );

                $root.find('[data-side="available"] [data-count]').text(state.available.length);
                $root.find('[data-side="right"] [data-count]').text(state.right.length);

                applySelection();
            }

            function payload() {
                var data = { available: idsOf(state.available) };

                data[rightKey] = idsOf(state.right);

                return data;
            }

            function save() {
                if (saving || !hasChanges()) {
                    return;
                }

                confirming = false;
                $confirm.prop('hidden', true);
                $confirmText.text('');
                saving = true;
                updateSave();
                setStatus('Guardando cambios...', 'info');

                $.ajax({
                    url: syncUrl.replace('__ID__', currentId),
                    method: 'POST',
                    dataType: 'json',
                    contentType: 'application/json; charset=utf-8',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    data: JSON.stringify(payload())
                }).done(function (response) {
                    baseline = snapshot();
                    setStatus(response.message || 'Cambios guardados.', 'ok');

                    if (typeof $modal.data('on-saved') === 'function') {
                        $modal.data('on-saved')();
                    }
                }).fail(function (xhr) {
                    var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};

                    // La lista local se conserva: el administrador corrige lo que
                    // haga falta y reintenta sin recargar ni perder lo movido.
                    setStatus(response.message || 'No se pudieron guardar los cambios.', 'error');
                }).always(function () {
                    saving = false;
                    updateSave();
                });
            }

            function confirmSave() {
                if (saving || !hasChanges()) {
                    return;
                }

                var name = currentLabel || 'este usuario';

                $confirmText.text(
                    kind === 'vetados'
                        ? '¿Seguro que quieres guardar los cambios de veto del grupo ' + name + '?'
                        : '¿Seguro que quieres guardar los cambios de bloqueo de ' + name + '?'
                );

                confirming = true;
                $confirm.prop('hidden', false);

                if ($confirm.length && typeof $confirm.get(0).scrollIntoView === 'function') {
                    $confirm.get(0).scrollIntoView({ block: 'nearest' });
                }

                updateSave();
                $confirmYes.trigger('focus');
            }

            function cancelConfirm() {
                confirming = false;
                $confirm.prop('hidden', true);
                $confirmText.text('');
                updateSave();
            }

            function moveIds(ids, direction) {
                if (!ids.length) {
                    return;
                }

                var from = direction === 'right' ? 'available' : 'right';
                var to = direction === 'right' ? 'right' : 'available';
                var moving = [];

                ids.forEach(function (id) {
                    state[from] = state[from].filter(function (item) {
                        if (item.id !== id) {
                            return true;
                        }

                        moving.push(item);

                        return false;
                    });
                });

                state[to] = state[to].concat(moving);
                sortState();
                selected = null;
                anchor = null;
                confirming = false;
                $confirm.prop('hidden', true);
                $confirmText.text('');
                render();
                updateSave();
                setStatus('Cambios sin guardar. Pulsa Guardar para aplicarlos.', 'info');
            }

            function move(direction, all) {
                if (saving) {
                    return;
                }

                var from = direction === 'right' ? 'available' : 'right';
                var ids = all
                    ? idsOf(state[from])
                    : (selected && selected.side === from ? selected.ids.slice() : []);

                if (!ids.length) {
                    setStatus(
                        all ? 'No hay personas para mover.' : 'Selecciona al menos una persona.',
                        'error'
                    );

                    return;
                }

                moveIds(ids, direction);
            }

            function load(id, label) {
                currentId = Number(id);
                currentLabel = label || '';
                selected = null;
                anchor = null;
                baseline = null;
                saving = false;
                confirming = false;

                $save.prop('disabled', true);
                $confirm.prop('hidden', true);
                $confirmText.text('');
                setStatus('');
                state = { available: [], right: [] };
                render();

                $subject.attr('data-label', currentLabel).text(
                    currentLabel ? subjectPrefix + ' ' + currentLabel : 'Cargando...'
                );

                $.getJSON(detailUrl.replace('__ID__', currentId)).done(function (response) {
                    var subject = response.subject || {};

                    state.available = response.available || [];
                    state.right = response[rightKey] || [];
                    currentLabel = subject.name || currentLabel;

                    sortState();
                    baseline = snapshot();
                    render();
                    updateSave();

                    $subject.text(subjectPrefix + ' ' + (currentLabel || 'sin nombre'));
                }).fail(function (xhr) {
                    var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};

                    setStatus(response.message || 'No se pudo cargar la información.', 'error');
                });
            }

            function transferData(event) {
                var original = event.originalEvent || event;

                return original.dataTransfer || null;
            }

            $root.on('click', '[data-list] [data-id]', function (event) {
                if (saving) {
                    return;
                }

                selectItem($(this), event);
            });

            $root.on('keydown', '[data-list] [data-id]', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                selectItem($(this), event);
            });

            $root.on('click', '[data-move]', function () {
                switch ($(this).attr('data-move')) {
                    case 'to-right-all':
                        move('right', true);
                        break;
                    case 'to-right':
                        move('right', false);
                        break;
                    case 'to-left':
                        move('left', false);
                        break;
                    default:
                        move('left', true);
                }
            });

            $save.on('click', confirmSave);

            $confirmYes.on('click', function () {
                if (saving) {
                    return;
                }

                confirming = false;
                $confirm.prop('hidden', true);
                $confirmText.text('');
                save();
            });

            $confirmNo.on('click', cancelConfirm);

            $root.on('dragstart', 'li[data-id]', function (event) {
                var $row = $(this);
                var side = stateKeyOf($row.closest('[data-list]'));
                var id = Number($row.attr('data-id'));

                // Si la fila ya forma parte de la selección, se arrastra toda
                // la selección; si no, se arrastra solo esa persona.
                if (!isChosen(side, id)) {
                    selected = { side: side, ids: [id] };
                    anchor = { side: side, id: id };
                    applySelection();
                }

                var ids = selected.ids.slice();

                dragging = {
                    ids: ids,
                    list: $row.closest('[data-list]').attr('data-list')
                };

                itemsOfSide(side).each(function () {
                    if (ids.indexOf(Number($(this).attr('data-id'))) !== -1) {
                        $(this).addClass('is-dragging');
                    }
                });

                var data = transferData(event);

                if (data) {
                    try {
                        data.effectAllowed = 'move';
                        data.setData('text/plain', ids.join(','));
                    } catch (error) {
                        // Sin dataTransfer no se puede arrastrar; los botones siguen funcionando.
                    }
                }
            });

            $root.on('dragend', 'li[data-id]', function () {
                dragging = null;
                $root.find('.is-dragging, .is-drop-target').removeClass('is-dragging is-drop-target');
            });

            $root.on('dragover', '[data-list]', function (event) {
                var $list = $(this);

                if (!dragging || $list.attr('data-list') === dragging.list) {
                    return;
                }

                event.preventDefault();

                var data = transferData(event);

                if (data) {
                    try {
                        data.dropEffect = 'move';
                    } catch (error) {
                        // Irrelevante: el arrastre ya está permitido.
                    }
                }

                $list.addClass('is-drop-target');
            });

            $root.on('dragleave', '[data-list]', function () {
                $(this).removeClass('is-drop-target');
            });

            $root.on('drop', '[data-list]', function (event) {
                var $list = $(this);

                event.preventDefault();
                $list.removeClass('is-drop-target');

                if (!dragging) {
                    return;
                }

                var target = $list.attr('data-list');
                var source = dragging.list;
                var ids = dragging.ids.slice();

                dragging = null;

                if (target === source) {
                    return;
                }

                moveIds(ids, target === 'available' ? 'left' : 'right');
            });

            $modal.on('hidden.bs.modal', function () {
                // «Cerrar» descarta lo pendiente: al abrir de nuevo, load()
                // vuelve a traer el estado real del servidor.
                selected = null;
                dragging = null;
                confirming = false;
                $confirm.prop('hidden', true);
            });

            return {
                open: function (id, label) {
                    load(id, label);

                    if (window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance($modal.get(0)).show();
                    }
                }
            };
        }

        var bloquesModal = Transfer(document.getElementById('modal-moderacion-bloqueos'));
        var baneadosModal = Transfer(document.getElementById('modal-moderacion-baneados'));

        /* ------------------------------------------------------------------
           Bloqueos entre usuarios
           ------------------------------------------------------------------ */

        var usuariosTable = null;

        function usuariosFilterData() {
            return {
                filter_type: $('#moderacion-usuarios-filter-type').val() || 'all',
                role: $('#moderacion-usuarios-role').val() || ''
            };
        }

        function toggleUsuariosFilter() {
            $('#moderacion-usuarios-filter-role-wrap').toggleClass(
                'd-none',
                ($('#moderacion-usuarios-filter-type').val() || 'all') !== 'role'
            );
        }

        function initUsuariosTable() {
            var table = $('#moderacion-usuarios-table').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: $config.attr('data-url-usuarios'),
                    type: 'GET',
                    data: function (data) {
                        $.extend(data, usuariosFilterData());
                    }
                },
                columns: [
                    { data: 'name' },
                    { data: 'username' },
                    { data: 'role', orderable: false, searchable: false },
                    { data: 'blocked_count', className: 'roles-id' },
                    { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
                ],
                columnDefs: [
                    { orderable: false, targets: [2, 4] }
                ],
                order: [[0, 'asc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: {
                    emptyTable: 'No hay usuarios registrados.',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ usuarios',
                    infoEmpty: 'Sin usuarios',
                    infoFiltered: '(filtrado de _MAX_)',
                    lengthMenu: 'Mostrar _MENU_',
                    loadingRecords: 'Cargando usuarios...',
                    processing: 'Procesando...',
                    zeroRecords: 'No se encontraron usuarios',
                    paginate: {
                        first: 'Primera',
                        last: 'Última',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            $('#moderacion-usuarios-search').on('input', function () {
                table.search(this.value).draw();
            });

            $('#moderacion-usuarios-clear').on('click', function () {
                $('#moderacion-usuarios-search').val('');
                $('#moderacion-usuarios-filter-type').val('all');
                $('#moderacion-usuarios-role').val('admin');
                toggleUsuariosFilter();
                table.search('').ajax.reload(null, true);
            });

            $('#moderacion-usuarios-filter-type').on('change', function () {
                toggleUsuariosFilter();
                table.ajax.reload(null, true);
            });

            $('#moderacion-usuarios-role').on('change', function () {
                table.ajax.reload(null, true);
            });

            toggleUsuariosFilter();

            return table;
        }

        $('#moderacion-usuarios-table').on('click', '.moderacion-bloqueos', function () {
            if (!bloquesModal) {
                return;
            }

            bloquesModal.open($(this).attr('data-id'), $(this).attr('data-name'));
        });

        /* ------------------------------------------------------------------
           Vetados de grupo
           ------------------------------------------------------------------ */

        var gruposTable = null;

        function initGruposTable() {
            var table = $('#moderacion-grupos-table').DataTable({
                serverSide: true,
                processing: true,
                autoWidth: true,
                ajax: {
                    url: $config.attr('data-url-grupos'),
                    type: 'GET'
                },
                columns: [
                    { data: 'name' },
                    { data: 'owner' },
                    { data: 'members', orderable: false, searchable: false, className: 'roles-id' },
                    { data: 'banned', orderable: false, searchable: false, className: 'roles-id' },
                    { data: 'actions', orderable: false, searchable: false, className: 'roles-actions' }
                ],
                columnDefs: [
                    { orderable: false, targets: [2, 3, 4] }
                ],
                order: [[0, 'asc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                dom: 'lrtip',
                language: {
                    emptyTable: 'No hay grupos registrados.',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ grupos',
                    infoEmpty: 'Sin grupos',
                    infoFiltered: '(filtrado de _MAX_)',
                    lengthMenu: 'Mostrar _MENU_',
                    loadingRecords: 'Cargando grupos...',
                    processing: 'Procesando...',
                    zeroRecords: 'No se encontraron grupos',
                    paginate: {
                        first: 'Primera',
                        last: 'Última',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            $('#moderacion-grupos-search').on('input', function () {
                table.search(this.value).draw();
            });

            $('#moderacion-grupos-clear').on('click', function () {
                $('#moderacion-grupos-search').val('');
                table.search('').draw();
            });

            return table;
        }

        $('#moderacion-grupos-table').on('click', '.moderacion-baneados', function () {
            if (!baneadosModal) {
                return;
            }

            baneadosModal.open($(this).attr('data-id'), $(this).attr('data-name'));
        });

        /* ------------------------------------------------------------------
           Apertura de cada pestaña
           ------------------------------------------------------------------ */

        if (bloquesModal) {
            $('#modal-moderacion-bloqueos').data('on-saved', function () {
                if (usuariosTable) {
                    usuariosTable.ajax.reload(null, false);
                }
            });
        }

        if (baneadosModal) {
            $('#modal-moderacion-baneados').data('on-saved', function () {
                if (gruposTable) {
                    gruposTable.ajax.reload(null, false);
                }
            });
        }

        openTab = function (name) {
            if (name === 'reportes') {
                reportesTable.columns.adjust();
            }

            if (name === 'bloqueos') {
                if (usuariosTable) {
                    usuariosTable.columns.adjust();
                } else {
                    usuariosTable = initUsuariosTable();
                }
            }

            if (name === 'vetados') {
                if (gruposTable) {
                    gruposTable.columns.adjust();
                } else {
                    gruposTable = initGruposTable();
                }
            }
        };

        /* ------------------------------------------------------------------
           Contadores de las pestañas: solo texto, sin recargar la página.
           ------------------------------------------------------------------ */

        var contadoresUrl = $config.attr('data-url-contadores');
        var numberFormat = window.Intl ? new window.Intl.NumberFormat('es-VE') : null;

        function formatCount(value) {
            var number = parseInt(value, 10) || 0;

            return numberFormat ? numberFormat.format(number) : String(number);
        }

        function canRefreshCounts() {
            if (!contadoresUrl || document.hidden) {
                return false;
            }

            if (document.querySelector('.modal.show, .bootbox')) {
                return false;
            }

            var active = document.activeElement;

            return !(active && (active.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName)));
        }

        function refreshModerationCounts() {
            if (!canRefreshCounts()) {
                return;
            }

            $.getJSON(contadoresUrl).done(function (response) {
                $('[data-moderation-count="reports"]').text(formatCount(response.reports));
                $('[data-moderation-count="pending"]').text(formatCount(response.pending));
                $('[data-moderation-count="attending"]').text(formatCount(response.attending));
                $('[data-moderation-count="closed"]').text(formatCount(response.closed));
                $('[data-moderation-count="blocks"]').text(formatCount(response.blocks));
                $('[data-moderation-count="bans"]').text(formatCount(response.bans));
            });
        }

        if (contadoresUrl) {
            window.setInterval(function () {
                if (!document.hidden) {
                    refreshModerationCounts();
                }
            }, 20000);

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshModerationCounts();
                }
            });
        }

        activateTab('reportes');
    });
})(window.jQuery);

