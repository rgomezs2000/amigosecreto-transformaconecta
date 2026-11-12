/**
 * Eventos: tarjetas de solo lectura y acciones AJAX.
 * Crear, iniciar, suspender, posponer, cancelar y ver participantes.
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

        function alertDialog(message) {
            if (window.bootbox) {
                window.bootbox.alert({ title: 'Aviso', message: message, centerVertical: true });
                return;
            }

            window.alert(message);
        }

        function showError(xhr) {
            var response = xhr.responseJSON || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            alertDialog(message || 'No se pudo completar la operación.');
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

        // Crear evento -----------------------------------------------------
        var participantsPage = 1;

        function paginateParticipants(reset) {
            var perPage = parseInt($('#evento-participants-length').val(), 10) || 2;
            var $rows = $('.evento-participants__list .evento-participant');
            var total = $rows.length;
            var pages = Math.max(1, Math.ceil(total / perPage));

            if (reset) {
                participantsPage = 1;
            }

            participantsPage = Math.min(Math.max(1, participantsPage), pages);

            $rows.each(function (index) {
                $(this).prop('hidden', index < (participantsPage - 1) * perPage || index >= participantsPage * perPage);
            });

            $('#evento-participants-info').text('Página ' + participantsPage + ' de ' + pages);
            $('#evento-participants-prev').prop('disabled', participantsPage <= 1);
            $('#evento-participants-next').prop('disabled', participantsPage >= pages);
        }

        $('#evento-participants-length').on('change', function () {
            paginateParticipants(true);
        });

        $('#evento-participants-prev').on('click', function () {
            participantsPage--;
            paginateParticipants();
        });

        $('#evento-participants-next').on('click', function () {
            participantsPage++;
            paginateParticipants();
        });

        function openCreate() {
            $('#form-evento')[0].reset();
            $('#evento-name-error, #evento-date-error').text('').removeAttr('data-kind');
            paginateParticipants(true);
            modal('modal-evento-form').show();
        }

        $('#eventos-new').on('click', openCreate);

        function saveEvent() {
            var name = $.trim($('#evento-name').val());
            var date = $('#evento-date').val();
            var observations = $.trim($('#evento-observations').val());
            var notes = {};

            $('#evento-name-error, #evento-date-error').text('').removeAttr('data-kind');

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

            $('.evento-participant__notes').each(function () {
                notes[$(this).data('user-id')] = { notes: $(this).val() };
            });

            confirmDialog('¿Desea guardar el evento?', function () {
                post(crearUrl, {
                    name: name,
                    exchange_at: date,
                    status_reason: observations,
                    participants: notes
                }).done(function (response) {
                    confirmDialog('El evento se guardó. ¿Desea iniciarlo ahora?', function () {
                        post(participantesBase + '/' + response.event_id + '/iniciar', {})
                            .done(function (startResponse) {
                                redirect(startResponse);
                            })
                            .fail(showError);
                    });
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
                    }

                    showError(xhr);
                });
            });
        }

        $('#form-evento').on('submit', function (event) {
            event.preventDefault();
            saveEvent();
        });

        // Participantes ----------------------------------------------------
        var modalParticipants = [];
        var modalParticipantsPage = 0;

        function renderModalParticipants() {
            var perPage = parseInt($('#evento-modal-participants-length').val(), 10) || 2;
            var total = modalParticipants.length;
            var pages = Math.max(1, Math.ceil(total / perPage));

            modalParticipantsPage = Math.min(modalParticipantsPage, pages - 1);

            var start = modalParticipantsPage * perPage;
            var pageItems = modalParticipants.slice(start, start + perPage);

            $('#evento-participantes-list').html(
                pageItems.map(function (participant) {
                    return '<div class="evento-participant">' +
                        '<span class="status-badge status-badge--active evento-participant__status">' +
                        $('<div>').text(participant.status).html() + '</span>' +
                        '<div class="evento-participant__data">' +
                        '<p class="evento-participant__name">' + $('<div>').text(participant.name).html() + '</p>' +
                        '<p class="evento-participant__email">' + $('<div>').text(participant.email).html() + '</p>' +
                        (participant.notes ? '<p class="evento-card__note">' + $('<div>').text(participant.notes).html() + '</p>' : '') +
                        '</div>' +
                        '</div>';
                }).join('') || '<p class="menu-empty">El evento no tiene participantes.</p>'
            );

            $('#evento-modal-participants-info').text(
                total === 0 ? '0 de 0' : (start + 1) + '–' + Math.min(start + perPage, total) + ' de ' + total
            );

            $('#evento-modal-participants-prev').prop('disabled', modalParticipantsPage === 0);
            $('#evento-modal-participants-next').prop('disabled', modalParticipantsPage >= pages - 1);
        }

        $('.btn-evento-participantes').on('click', function () {
            var id = eventId(this);

            $.getJSON(participantesBase + '/' + id + '/participantes')
                .done(function (response) {
                    modalParticipants = response.participants || [];
                    modalParticipantsPage = 0;

                    $('#evento-participantes-count').text('Participantes activos: ' + response.count);

                    renderModalParticipants();
                    modal('modal-evento-participantes').show();
                })
                .fail(showError);
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

        // Reiniciar --------------------------------------------------------
        $('.btn-evento-reiniciar').on('click', function () {
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
        $('.btn-evento-iniciar').on('click', function () {
            var id = eventId(this);

            confirmDialog('¿Desea iniciar el evento? Se enviará un correo a los participantes.', function () {
                post(participantesBase + '/' + id + '/iniciar', {})
                    .done(redirect)
                    .fail(showError);
            });
        });

        // Suspender --------------------------------------------------------
        $('.btn-evento-suspender').on('click', function () {
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

            confirmDialog('¿Desea suspender el evento?', function () {
                post(participantesBase + '/' + id + '/suspender', { reason: reason })
                    .done(redirect)
                    .fail(showError);
            });
        });

        // Posponer ---------------------------------------------------------
        $('.btn-evento-posponer').on('click', function () {
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

            confirmDialog('¿Desea posponer el evento para esta nueva fecha?', function () {
                post(participantesBase + '/' + id + '/posponer', {
                    exchange_at: date,
                    reason: reason,
                    suspend: suspend ? 1 : 0
                }).done(redirect).fail(showError);
            });
        });

        // Cancelar ---------------------------------------------------------
        $('.btn-evento-cancelar').on('click', function () {
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

            confirmDialog('¿Desea cancelar el evento? Se eliminará del sistema.', function () {
                post(participantesBase + '/' + id + '/cancelar', { reason: reason })
                    .done(redirect)
                    .fail(showError);
            });
        });
    });
})(window.jQuery);
