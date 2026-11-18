/**
 * Eventos: tarjetas de solo lectura y acciones AJAX.
 * Creación con presupuesto, invitaciones, inicio, suspensión, postergación,
 * cancelación y gestión de la participación.
 */
(function ($) {
    'use strict';

    $(function () {
        var $config = $('#eventos-config');

        if (!$config.length) {
            return;
        }

        var crearUrl = $config.attr('data-url-crear');
        var indexUrl = $config.attr('data-url-index');
        var reiniciarUrl = $config.attr('data-url-reiniciar');
        var participantesBase = $config.attr('data-url-participantes');
        var invitarUrl = $config.attr('data-url-invitar');
        var invitarUnoUrl = $config.attr('data-url-invitar-uno');
        var expulsarUrl = $config.attr('data-url-expulsar');
        var descartarUrl = $config.attr('data-url-descartar');
        var tasa = parseFloat($config.attr('data-tasa')) || 0;
        var token = $('#eventos-csrf').val();

        function modal(id) {
            var element = document.getElementById(id);

            return element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
        }

        function hide(id) {
            var instance = modal(id);

            if (instance) {
                instance.hide();
            }
        }

        function showError(xhr) {
            var response = xhr.responseJSON || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            window.AppDialog.error(message || 'No se pudo completar la operación.');
        }

        function redirect(response) {
            window.location.assign((response && response.redirect) || indexUrl);
        }

        function post(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, data || {})
            });
        }

        function cardOf(button) {
            return $(button).closest('.evento-card');
        }

        function eventId(button) {
            return cardOf(button).data('evento-id');
        }

        function urlFor(pattern, id, user) {
            return String(pattern || '')
                .replace('__ID__', id)
                .replace('__USER__', user);
        }

        // Presupuesto: referencia en bolívares, solo para mostrar ----------
        function updateBudgetBs() {
            var usd = parseFloat($('#evento-presupuesto').val());

            if (!isFinite(usd) || usd <= 0 || tasa <= 0) {
                $('#evento-presupuesto-bs').val('');
                return;
            }

            var bs = usd * tasa;

            $('#evento-presupuesto-bs').val('Bs. ' + bs.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }));
        }

        $('#evento-presupuesto').on('input change', updateBudgetBs);

        // Crear evento -----------------------------------------------------
        function openCreate() {
            $('#form-evento')[0].reset();
            $('#evento-name-error, #evento-date-error, #evento-presupuesto-error')
                .text('')
                .removeAttr('data-kind');
            $('#evento-presupuesto-bs').val('');
            modal('modal-evento-form').show();
        }

        $('#eventos-new').on('click', openCreate);

        function saveEvent() {
            var name = $.trim($('#evento-name').val());
            var date = $('#evento-date').val();
            var presupuesto = $.trim($('#evento-presupuesto').val());
            var observations = $.trim($('#evento-observations').val());

            $('#evento-name-error, #evento-date-error, #evento-presupuesto-error')
                .text('')
                .removeAttr('data-kind');

            if (!name) {
                $('#evento-name-error').attr('data-kind', 'error').text('Este campo es obligatorio.');
                $('#evento-name').trigger('focus');
                return;
            }

            if (!date) {
                $('#evento-date-error').attr('data-kind', 'error').text('Este campo es obligatorio.');
                $('#evento-date').trigger('focus');
                return;
            }

            if (!presupuesto || !isFinite(parseFloat(presupuesto)) || parseFloat(presupuesto) <= 0) {
                $('#evento-presupuesto-error')
                    .attr('data-kind', 'error')
                    .text('Escribe un presupuesto válido en US$.');
                $('#evento-presupuesto').trigger('focus');
                return;
            }

            AppDialog.confirm(
                '¿Desea guardar el evento? Se generará una invitación personal para cada usuario participante.',
                function () {
                    post(crearUrl, {
                        name: name,
                        exchange_at: date,
                        presupuesto: presupuesto,
                        status_reason: observations
                    }).done(function () {
                        window.location.reload();
                    }).fail(function (xhr) {
                        var response = xhr.responseJSON || {};

                        if (response.errors) {
                            if (response.errors.name) {
                                $('#evento-name-error').attr('data-kind', 'error').text(response.errors.name[0]);
                                $('#evento-name').trigger('focus');
                            }
                            if (response.errors.exchange_at) {
                                $('#evento-date-error').attr('data-kind', 'error').text(response.errors.exchange_at[0]);
                                $('#evento-date').trigger('focus');
                            }
                            if (response.errors.presupuesto) {
                                $('#evento-presupuesto-error')
                                    .attr('data-kind', 'error')
                                    .text(response.errors.presupuesto[0]);
                                $('#evento-presupuesto').trigger('focus');
                            }
                        }

                        showError(xhr);
                    });
                }
            );
        }

        $('#form-evento').on('submit', function (event) {
            event.preventDefault();
            saveEvent();
        });

        // Participantes ----------------------------------------------------
        var modalParticipants = [];
        var modalParticipantsPage = 0;
        var modalParticipantsEvent = 0;

        function statusBadgeKey(statusKey) {
            if (statusKey === 'active') {
                return 'active';
            }

            if (statusKey === 'pending') {
                return 'pending';
            }

            return 'inactive';
        }

        function renderModalParticipants() {
            var perPage = parseInt($('#evento-modal-participants-length').val(), 10) || 5;
            var total = modalParticipants.length;
            var pages = Math.max(1, Math.ceil(total / perPage));

            modalParticipantsPage = Math.min(modalParticipantsPage, pages - 1);

            var start = modalParticipantsPage * perPage;
            var pageItems = modalParticipants.slice(start, start + perPage);

            $('#evento-participantes-list').html(
                pageItems.map(function (participant) {
                    var actions = '';

                    if (participant.status_key === 'pending') {
                        actions = '<div class="table-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary btn-participante-invitar" data-user="' + participant.id + '" title="Enviar invitación"><i class="fa-solid fa-envelope"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary btn-participante-link" data-url="' + (participant.invitation_url || '') + '" title="Copiar enlace"><i class="fa-solid fa-link"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary btn-participante-descartar" data-user="' + participant.id + '" title="Descartar"><i class="fa-solid fa-ban"></i></button>' +
                            '</div>';
                    } else if (participant.status_key === 'active') {
                        actions = '<div class="table-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary btn-participante-expulsar" data-user="' + participant.id + '" title="Expulsar"><i class="fa-solid fa-user-slash"></i></button>' +
                            '</div>';
                    }

                    return '<div class="evento-participant">' +
                        '<span class="status-badge status-badge--' + statusBadgeKey(participant.status_key) + ' evento-participant__status">' +
                        $('<div>').text(participant.status).html() + '</span>' +
                        '<div class="evento-participant__data">' +
                        '<p class="evento-participant__name">' + $('<div>').text(participant.name).html() + '</p>' +
                        '<p class="evento-participant__email">' + $('<div>').text(participant.email).html() + '</p>' +
                        (participant.reason ? '<p class="evento-card__note">' + $('<div>').text(participant.reason).html() + '</p>' : '') +
                        '</div>' + actions +
                        '</div>';
                }).join('') || '<p class="menu-empty">El evento no tiene usuarios invitados.</p>'
            );

            $('#evento-modal-participants-info').text(
                total === 0 ? '0 de 0' : (start + 1) + '–' + Math.min(start + perPage, total) + ' de ' + total
            );

            $('#evento-modal-participants-prev').prop('disabled', modalParticipantsPage === 0);
            $('#evento-modal-participants-next').prop('disabled', modalParticipantsPage >= pages - 1);
        }

        function loadParticipants(id, openModal) {
            return $.getJSON(participantesBase + '/' + id + '/participantes')
                .done(function (response) {
                    modalParticipants = response.participants || [];
                    modalParticipantsEvent = id;
                    modalParticipantsPage = 0;

                    $('#evento-participantes-count').text(
                        'Activos: ' + (response.count || 0) +
                        ' · Pendientes: ' + (response.pending || 0) +
                        ' · Total: ' + (response.total || 0)
                    );

                    renderModalParticipants();

                    if (openModal) {
                        modal('modal-evento-participantes').show();
                    }
                })
                .fail(showError);
        }

        $('#eventos-lista').on('click', '.btn-evento-participantes', function () {
            loadParticipants(eventId(this), true);
        });

        $('#evento-modal-participants-length').on('change', function () {
            modalParticipantsPage = 0;
            renderModalParticipants();
        });

        $('#evento-modal-participants-prev').on('click', function () {
            modalParticipantsPage = Math.max(0, modalParticipantsPage - 1);
            renderModalParticipants();
        });

        $('#evento-modal-participants-next').on('click', function () {
            modalParticipantsPage += 1;
            renderModalParticipants();
        });

        // Enviar todas las invitaciones pendientes ------------------------
        $('#eventos-lista').on('click', '.btn-evento-invitar', function () {
            var id = eventId(this);

            AppDialog.confirm(
                '¿Desea enviar las invitaciones pendientes por correo, chat del sistema y notificación push?',
                function () {
                    post(urlFor(invitarUrl, id), {})
                        .done(function (response) {
                            window.AppDialog.success(response.message, {
                                callback: function () {
                                    window.location.reload();
                                }
                            });
                        })
                        .fail(showError);
                }
            );
        });

        // Invitar / descartar / expulsar una persona ----------------------
        $('#evento-participantes-list').on('click', '.btn-participante-invitar', function () {
            var userId = $(this).data('user');

            post(urlFor(invitarUnoUrl, modalParticipantsEvent, userId), {})
                .done(function (response) {
                    if (response.invitation_url) {
                        copyText(response.invitation_url);
                    }

                    window.AppDialog.success('Invitación enviada. El enlace personal se copió al portapapeles.', {
                        callback: function () {
                            loadParticipants(modalParticipantsEvent, false);
                        }
                    });
                })
                .fail(showError);
        });

        $('#evento-participantes-list').on('click', '.btn-participante-link', function () {
            var url = $(this).data('url');

            if (!url) {
                return;
            }

            copyText(url);
            window.AppDialog.success('Enlace personal copiado al portapapeles.');
        });

        $('#evento-participantes-list').on('click', '.btn-participante-descartar', function () {
            var userId = $(this).data('user');

            window.AppDialog.prompt(
                'Motivo por el que se descarta la invitación (opcional):',
                function (reason) {
                    AppDialog.confirm(
                        '¿Desea descartar esta invitación? La persona ya no contará como pendiente.',
                        function () {
                            post(urlFor(descartarUrl, modalParticipantsEvent, userId), { reason: reason || '' })
                                .done(function () {
                                    loadParticipants(modalParticipantsEvent, false);
                                })
                                .fail(showError);
                        }
                    );
                },
                { title: 'Descartar invitación', required: false, maxlength: 1000 }
            );
        });

        $('#evento-participantes-list').on('click', '.btn-participante-expulsar', function () {
            var userId = $(this).data('user');

            window.AppDialog.prompt(
                'Motivo de la expulsión (obligatorio):',
                function (reason) {
                    if (!$.trim(reason)) {
                        window.AppDialog.error('Escribe el motivo de la expulsión.');
                        return;
                    }

                    AppDialog.confirm(
                        '¿Desea expulsar al participante del evento?',
                        function () {
                            post(urlFor(expulsarUrl, modalParticipantsEvent, userId), { reason: reason })
                                .done(function () {
                                    loadParticipants(modalParticipantsEvent, false);
                                })
                                .fail(showError);
                        },
                        { danger: true, confirmLabel: 'Expulsar' }
                    );
                },
                { title: 'Expulsar participante', required: true, maxlength: 1000 }
            );
        });

        function copyText(text) {
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

        // Reiniciar --------------------------------------------------------
        $('#eventos-lista').on('click', '.btn-evento-reiniciar', function () {
            var year = $(this).data('year');

            $('#evento-reiniciar-log').text('');
            $('#evento-reiniciar-progress').css('width', '0%').removeClass('bg-danger');
            $('#evento-reiniciar-confirm').data('year', year).prop('disabled', false);
            modal('modal-evento-reiniciar').show();
        });

        $('#evento-reiniciar-confirm').on('click', function () {
            var $button = $(this);
            var year = $button.data('year');
            var $progress = $('#evento-reiniciar-progress');
            var $log = $('#evento-reiniciar-log');

            $button.prop('disabled', true);
            $progress.css('width', '15%');
            $log.text('Ejecutando el proceso...');

            var timer = window.setInterval(function () {
                var width = parseInt($progress[0].style.width, 10) || 0;

                if (width < 90) {
                    $progress.css('width', (width + 12) + '%');
                }
            }, 400);

            $.ajax({
                url: reiniciarUrl,
                method: 'POST',
                dataType: 'json',
                data: { _token: token, year: year }
            }).done(function (response) {
                window.clearInterval(timer);
                $progress.css('width', '100%');
                $log.text(response.data && response.data.output ? response.data.output : response.message);

                window.setTimeout(function () {
                    window.location.reload();
                }, 1500);
            }).fail(function (xhr) {
                window.clearInterval(timer);
                $progress.css('width', '100%').addClass('bg-danger');

                var data = xhr.responseJSON || {};
                $log.text((data.data && data.data.output) ? data.data.output : (data.message || 'No se pudo reiniciar el evento.'));

                $button.prop('disabled', false);
            });
        });

        // Iniciar ----------------------------------------------------------
        $('#eventos-lista').on('click', '.btn-evento-iniciar', function () {
            var id = eventId(this);
            var pending = parseInt($(this).data('pending'), 10) || 0;
            var active = parseInt($(this).data('active'), 10) || 0;

            if (pending > 0) {
                window.AppDialog.warning(
                    'Todavía hay ' + pending + ' invitación(es) en espera. Deben responder o descartarse antes de iniciar.'
                );
                return;
            }

            if (active < 2) {
                window.AppDialog.warning('Se necesitan al menos 2 participantes activos para iniciar el sorteo.');
                return;
            }

            AppDialog.confirm('¿Desea iniciar el evento? Se avisará a los participantes activos.', function () {
                post(participantesBase + '/' + id + '/iniciar', {})
                    .done(redirect)
                    .fail(showError);
            });
        });

        // Reiniciar el sorteo ciego (caso extremo, solo administración) -----
        $('#eventos-lista').on('click', '.btn-evento-reiniciar-sorteo', function () {
            var id = $(this).data('id');

            AppDialog.confirm(
                '¿Reiniciar el sorteo? Se borran las parejas y los chats de amigo secreto, y todos vuelven a elegir.',
                function () {
                    post(participantesBase + '/' + id + '/sorteo/reiniciar', {})
                        .done(redirect)
                        .fail(showError);
                }
            );
        });

        // Suspender --------------------------------------------------------
        $('#eventos-lista').on('click', '.btn-evento-suspender', function () {
            $('#evento-suspender-reason').val('');
            $('#evento-suspender-error').text('').removeAttr('data-kind');
            $('#modal-evento-suspender').data('evento-id', eventId(this));
            modal('modal-evento-suspender').show();
        });

        $('#evento-suspender-confirm').on('click', function () {
            var id = $('#modal-evento-suspender').data('evento-id');
            var reason = $.trim($('#evento-suspender-reason').val());

            $('#evento-suspender-error').text('').removeAttr('data-kind');

            if (!reason) {
                $('#evento-suspender-error').attr('data-kind', 'error').text('Escribe el motivo de la suspensión.');
                $('#evento-suspender-reason').trigger('focus');
                return;
            }

            AppDialog.confirm('¿Desea suspender el evento?', function () {
                post(participantesBase + '/' + id + '/suspender', { reason: reason })
                    .done(redirect)
                    .fail(showError);
            });
        });

        // Posponer ---------------------------------------------------------
        $('#eventos-lista').on('click', '.btn-evento-posponer', function () {
            var card = cardOf(this);

            $('#evento-posponer-date').val('');
            $('#evento-posponer-reason').val('');
            $('#evento-posponer-suspend').prop('checked', false);
            $('#evento-posponer-date-error, #evento-posponer-error').text('').removeAttr('data-kind');
            $('#modal-evento-posponer').data('evento-id', card.data('evento-id'));
            modal('modal-evento-posponer').show();
        });

        $('#evento-posponer-confirm').on('click', function () {
            var id = $('#modal-evento-posponer').data('evento-id');
            var date = $('#evento-posponer-date').val();
            var reason = $.trim($('#evento-posponer-reason').val());
            var suspend = $('#evento-posponer-suspend').is(':checked');

            $('#evento-posponer-date-error, #evento-posponer-error').text('').removeAttr('data-kind');

            if (!date) {
                $('#evento-posponer-date-error').attr('data-kind', 'error').text('Escribe la nueva fecha.');
                $('#evento-posponer-date').trigger('focus');
                return;
            }

            if (!reason) {
                $('#evento-posponer-error').attr('data-kind', 'error').text('Escribe el motivo de la postergación.');
                $('#evento-posponer-reason').trigger('focus');
                return;
            }

            AppDialog.confirm('¿Desea posponer el evento para esta nueva fecha?', function () {
                post(participantesBase + '/' + id + '/posponer', {
                    exchange_at: date,
                    reason: reason,
                    suspend: suspend ? 1 : 0
                }).done(redirect).fail(showError);
            });
        });

        // Cancelar ---------------------------------------------------------
        $('#eventos-lista').on('click', '.btn-evento-cancelar', function () {
            $('#evento-cancelar-reason').val('');
            $('#evento-cancelar-error').text('').removeAttr('data-kind');
            $('#modal-evento-cancelar').data('evento-id', eventId(this));
            modal('modal-evento-cancelar').show();
        });

        $('#evento-cancelar-confirm').on('click', function () {
            var id = $('#modal-evento-cancelar').data('evento-id');
            var reason = $.trim($('#evento-cancelar-reason').val());

            $('#evento-cancelar-error').text('').removeAttr('data-kind');

            if (!reason) {
                $('#evento-cancelar-error').attr('data-kind', 'error').text('Escribe el motivo de la cancelación.');
                $('#evento-cancelar-reason').trigger('focus');
                return;
            }

            AppDialog.confirm('¿Desea cancelar el evento? Se eliminará del sistema.', function () {
                post(participantesBase + '/' + id + '/cancelar', { reason: reason })
                    .done(redirect)
                    .fail(showError);
            }, { danger: true, confirmLabel: 'Cancelar evento' });
        });

        /* ------------------------------------------------------------------
           Tarjetas de eventos: refresco en reposo, sin tocar formularios.
           ------------------------------------------------------------------ */

        var $live = $('#eventos-live');
        var estadoUrl = $live.length ? $live.attr('data-url-estado') : '';

        function canRefreshEvents() {
            if (!estadoUrl || document.hidden) {
                return false;
            }

            if ($.active > 0) {
                return false;
            }

            if (document.querySelector('.modal.show, .bootbox')) {
                return false;
            }

            var active = document.activeElement;

            return !(active && (active.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName)));
        }

        function refreshEvents() {
            if (!canRefreshEvents()) {
                return;
            }

            $.getJSON(estadoUrl).done(function (response) {
                var events = response.events || [];
                var currentIds = $('.evento-card').map(function () {
                    return String($(this).data('evento-id'));
                }).get();
                var nextIds = events.map(function (event) {
                    return String(event.id);
                });
                var structuralChange = JSON.stringify(currentIds) !== JSON.stringify(nextIds);

                events.forEach(function (event) {
                    var $card = $('.evento-card[data-evento-id="' + event.id + '"]');

                    if (!$card.length) {
                        return;
                    }

                    var previousStatus = $card.find('[data-evento-field="status"]').first().text().trim();

                    if (previousStatus && previousStatus !== event.status) {
                        structuralChange = true;
                    }

                    $card.find('.status-badge')
                        .removeClass('status-badge--active status-badge--blocked status-badge--inactive status-badge--pending')
                        .addClass('status-badge--' + event.badge)
                        .text(event.status);
                    $card.find('[data-evento-field="status"]').text(event.status);
                    $card.find('[data-evento-field="exchange"]').text(event.exchange);
                    $card.find('[data-evento-field="budget"]').text(event.budget);
                    $card.find('[data-evento-field="participants"]').text(event.active);
                    $card.find('[data-evento-field="pending"]').text(event.pending);
                    $card.find('[data-evento-field="phase"]').text(event.phase_label);
                    $card.find('[data-evento-field="created"]').text(event.created);
                    $card.find('[data-evento-field="name"]').text(event.name);
                    $card.attr('data-evento-name', event.name);
                });

                if (structuralChange && response.html) {
                    // Reemplazo por AJAX del bloque de tarjetas, sin recargar
                    // la página; los manejadores están delegados en #eventos-lista.
                    $('#eventos-lista').html(response.html);
                }

                if (typeof response.can_create !== 'undefined') {
                    var $new = $('#eventos-new');

                    $new.prop('disabled', !response.can_create);
                    $new.attr('title', response.can_create
                        ? 'Crear un evento nuevo.'
                        : 'Ya existe un evento para el año actual. Solo se permite uno por año.');
                }
            });
        }

        if (estadoUrl) {
            window.setInterval(function () {
                if (!document.hidden) {
                    refreshEvents();
                }
            }, 25000);

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshEvents();
                }
            });
        }
    });
})(window.jQuery);
