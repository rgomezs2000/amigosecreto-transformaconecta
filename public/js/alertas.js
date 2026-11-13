/**
 * Campana de notificaciones.
 *
 * - Cuenta las notificaciones nuevas y actualiza el número de la cabecera.
 * - Marca como leída la que se abre (y navega si tiene enlace).
 * - Permite silenciar el sonido y dejar solo avisos visuales.
 */
(function ($) {
    'use strict';

    $(function () {
        var $panel = $('#notifications-panel');

        if (!$panel.length) {
            return;
        }

        var contadorUrl = $panel.data('url-contador');
        var listaUrl = $panel.data('url-lista');
        var leerTodasUrl = $panel.data('url-leer-todas');
        var leerUrl = String($panel.data('url-leer') || '');
        var preferenciasUrl = $panel.data('url-preferencias');
        var token = $panel.data('csrf');
        var sound = String($panel.data('sound')) === '1';

        var $count = $('#notifications-count');
        var $dot = $('#notifications-dot');
        var $open = $('#notifications-open');
        var $markAll = $('#alert-mark-all');
        var lastCount = parseInt($open.attr('data-count'), 10) || 0;
        var lastListSignature = null;
        var currentPage = 1;
        var perPage = 10;
        var lastPage = 1;
        var audioContext = null;
        var modalElement = document.getElementById('modal-notification-detail');
        var notificationModal = modalElement && window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(modalElement)
            : null;

        function beep() {
            if (!sound) {
                return;
            }

            try {
                audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
                var oscillator = audioContext.createOscillator();
                var gain = audioContext.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = 740;
                gain.gain.value = 0.05;
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.12);
            } catch (error) {
                // Sin audio disponible.
            }
        }

        function updateCount(count) {
            count = Math.max(0, parseInt(count, 10) || 0);
            lastCount = count;
            $open.attr('data-count', count).attr('aria-label', 'Notificaciones: ' + count);
            $markAll.prop('disabled', count === 0);

            if (count === 1) {
                // Un solo aviso: punto rojo, sin número.
                $dot.prop('hidden', false);
                $count.prop('hidden', true).text('');
            } else if (count >= 2) {
                $dot.prop('hidden', true);
                $count.prop('hidden', false).text(count > 99 ? '+99' : String(count));
            } else {
                $dot.prop('hidden', true);
                $count.prop('hidden', true).text('');
            }
        }

        function notificationActionLabel(url) {
            return /conversacion=/.test(String(url || '')) ? 'Ir al chat' : 'Abrir';
        }

        function openNotificationDetail(title, body, date, url) {
            $('#modal-notification-detail-title').text(title || 'Notificación');
            $('#notification-detail-date').text(date || '');
            $('#notification-detail-body').text(body || '').prop('hidden', !body);

            var $action = $('#notification-detail-open');

            if (url) {
                $action.attr('href', url).prop('hidden', false);
                $action.find('span').text(notificationActionLabel(url));
            } else {
                $action.attr('href', '#').prop('hidden', true);
            }

            if (notificationModal) {
                notificationModal.show();
            }
        }

        function prefValue(name) {
            var $input = $panel.find('.alert-pref[data-field="' + name + '"]');

            return $input.length && $input.is(':checked') ? 1 : 0;
        }

        function post(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, data)
            });
        }

        function status(message) {
            $('#alert-prefs-status').text(message);
        }

        function chatIsOpen() {
            var $chat = $('#chat-panel');

            return $chat.length > 0 && !$chat.prop('hidden');
        }

        function renderList(items) {
            var $list = $('#alert-list');

            $list.empty();

            if (!items || !items.length) {
                $list.append($('<li class="alert-empty"></li>').text('No tienes notificaciones.'));
                return;
            }

            items.forEach(function (item) {
                var $item = $('<li class="alert-item"></li>').attr('data-id', item.id);

                if (!item.read) {
                    $item.addClass('is-unread');
                }

                var $button = $('<button type="button" class="alert-item__button"></button>')
                    .attr('data-id', item.id)
                    .attr('data-url', item.url || '')
                    .attr('data-read', item.read ? '1' : '0');

                var $text = $('<span class="alert-item__text"></span>');

                $text.append($('<span class="alert-item__title"></span>').text(item.title || 'Notificación'));

                if (item.body) {
                    $text.append($('<span class="alert-item__body"></span>').text(item.body));
                }

                $text.append($('<span class="alert-item__date"></span>').text(item.date || ''));

                $button.append($('<span class="alert-item__dot" aria-hidden="true"></span>'), $text);

                var $row = $('<div class="alert-item__row"></div>');
                $row.append($button);

                if (item.url) {
                    $row.append(
                        $('<a class="alert-item__open"></a>')
                            .attr('href', item.url)
                            .attr('data-id', item.id)
                            .append($('<i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>'))
                            .append($('<span></span>').text('Abrir'))
                    );
                }

                $item.append($row);
                $list.append($item);
            });
        }

        function loadList(force, page) {
            if (!listaUrl) {
                return;
            }

            if (typeof page !== 'undefined') {
                currentPage = Math.max(1, parseInt(page, 10) || 1);
            }

            $.getJSON(listaUrl, { page: currentPage, per_page: perPage })
                .done(function (response) {
                    var items = response.notifications || [];
                    var pagination = response.pagination || {};

                    updateCount(response.count);

                    if (pagination.last_page && currentPage > pagination.last_page) {
                        currentPage = pagination.last_page;
                        loadList(true);
                        return;
                    }

                    renderPagination(pagination);

                    var signature = currentPage + '|' + perPage + '|' + JSON.stringify(items);

                    // Si nada cambió, no se vuelve a pintar la lista.
                    if (!force && signature === lastListSignature) {
                        return;
                    }

                    lastListSignature = signature;
                    renderList(items);
                });
        }

        function renderPagination(pagination) {
            var $pager = $('#alert-pagination');
            var total = parseInt(pagination.total, 10) || 0;

            lastPage = Math.max(1, parseInt(pagination.last_page, 10) || 1);

            if (!total) {
                $pager.prop('hidden', true);
                return;
            }

            $pager.prop('hidden', false);
            $('#alert-page-info').text(
                'Mostrando ' + (pagination.from || 0) + ' a ' + (pagination.to || 0) + ' de ' + total
            );
            $('#alert-page-prev').prop('disabled', currentPage <= 1);
            $('#alert-page-next').prop('disabled', currentPage >= lastPage);
            $('#alert-page-size').val(String(pagination.per_page || perPage));
        }

        function goToPage(page) {
            if (page < 1 || page > lastPage || page === currentPage) {
                return;
            }

            currentPage = page;
            loadList(true);

            var body = document.querySelector('#notifications-panel .offcanvas-body');

            if (body) {
                body.scrollTop = 0;
            }
        }

        function poll() {
            $.getJSON(contadorUrl)
                .done(function (response) {
                    var count = parseInt(response.count, 10) || 0;
                    var previous = lastCount;

                    // El chat ya avisa con su propio sonido cuando está abierto.
                    if (count > previous && previous > 0 && !chatIsOpen()) {
                        beep();
                    }

                    sound = !!response.sound;
                    updateCount(count);

                    if ($panel.hasClass('show') && count !== previous) {
                        loadList();
                    }
                });
        }

        $('#alert-list').on('click', '.alert-item__button', function () {
            var $button = $(this);
            var $item = $button.closest('.alert-item');
            var id = $button.data('id');

            openNotificationDetail(
                $item.find('.alert-item__title').text(),
                $item.find('.alert-item__body').text(),
                $item.find('.alert-item__date').text(),
                String($button.attr('data-url') || '')
            );

            if (String($button.attr('data-read')) === '1') {
                return;
            }

            post(leerUrl.replace('__ID__', encodeURIComponent(id)), {})
                .done(function (response) {
                    $item.removeClass('is-unread');
                    $button.attr('data-read', '1');
                    updateCount(response.count);
                });
        });

        $('#alert-list').on('click', '.alert-item__open', function (event) {
            var $link = $(this);
            var href = $link.attr('href');
            var id = $link.data('id');
            var $item = $link.closest('.alert-item');
            var $button = $item.find('.alert-item__button');
            var conversationId = null;

            if (!href) {
                return;
            }

            try {
                conversationId = new window.URL(href, window.location.origin).searchParams.get('conversacion');
            } catch (error) {
                conversationId = null;
            }

            function markRead() {
                if (String($button.attr('data-read')) === '1') {
                    return null;
                }

                return post(leerUrl.replace('__ID__', encodeURIComponent(id)), {})
                    .done(function (response) {
                        $item.removeClass('is-unread');
                        $button.attr('data-read', '1');
                        updateCount(response.count);
                    });
            }

            if (conversationId && $('#chat-panel').length) {
                // Abre el chat flotante en la conversación, sin recargar la página.
                event.preventDefault();
                markRead();
                document.dispatchEvent(new CustomEvent('chat:open', {
                    detail: { id: conversationId }
                }));

                return;
            }

            // Otros enlaces: navegación explícita, marcando antes como leída.
            event.preventDefault();

            var request = markRead();

            if (request) {
                request.always(function () {
                    window.location.assign(href);
                });
            } else {
                window.location.assign(href);
            }
        });

        $('#notification-detail-open').on('click', function (event) {
            var href = $(this).attr('href');
            var conversationId = null;

            if (!href || href === '#') {
                event.preventDefault();

                return;
            }

            try {
                conversationId = new window.URL(href, window.location.origin).searchParams.get('conversacion');
            } catch (error) {
                conversationId = null;
            }

            if (conversationId && $('#chat-panel').length) {
                event.preventDefault();

                if (notificationModal) {
                    notificationModal.hide();
                }

                document.dispatchEvent(new CustomEvent('chat:open', {
                    detail: { id: conversationId }
                }));
            }
        });

        $markAll.on('click', function () {
            $markAll.prop('disabled', true);

            post(leerTodasUrl, {})
                .done(function () {
                    $('#alert-list .alert-item').removeClass('is-unread');
                    $('#alert-list .alert-item__button').attr('data-read', '1');
                    updateCount(0);
                })
                .fail(function () {
                    $markAll.prop('disabled', false);
                });
        });

        $('#alert-page-prev').on('click', function () {
            goToPage(currentPage - 1);
        });

        $('#alert-page-next').on('click', function () {
            goToPage(currentPage + 1);
        });

        $('#alert-page-size').on('change', function () {
            perPage = parseInt($(this).val(), 10) || 10;
            currentPage = 1;
            loadList(true);
        });

        $panel.find('.alert-pref').on('change', function () {
            var $input = $(this);
            var field = $input.data('field');
            var payload = {};

            payload[field] = $input.is(':checked') ? 1 : 0;
            $input.prop('disabled', true);
            status('Guardando...');

            post(preferenciasUrl, payload)
                .done(function (response) {
                    if (response.preferences) {
                        sound = !!response.preferences.sound;
                        $panel.attr('data-sound', sound ? '1' : '0');
                    }

                    status('Preferencias guardadas.');
                })
                .fail(function () {
                    $input.prop('checked', !$input.is(':checked'));
                    status('No se pudieron guardar las preferencias.');
                })
                .always(function () {
                    $input.prop('disabled', false);
                });
        });

        $('#notifications-panel').on('shown.bs.offcanvas', function () {
            currentPage = 1;
            loadList(true);
            poll();
        });

        // Sondeo ligero: no recarga la página y se pausa si la pestaña no está visible.
        window.setInterval(function () {
            if (!document.hidden) {
                poll();
            }
        }, 10000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                poll();
            }
        });

        updateCount(lastCount);
    });
})(window.jQuery);
