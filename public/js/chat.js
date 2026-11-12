/**
 * Chat flotante universal.
 * Conversaciones, grupos, moderación, adjuntos, emojis, reacciones,
 * edición, eliminación y reportes.
 */
(function ($) {
    'use strict';

    var EMOJIS = {
        'Caras': ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '😊', '🙂', '😉', '😍', '😘', '😎', '🤔', '😐', '😴', '😢', '😭', '😡', '🥳'],
        'Gestos': ['👍', '👎', '👏', '🙌', '🤝', '🙏', '💪', '👌', '✌️', '🤞', '👋', '🫶'],
        'Objetos': ['🎁', '🎉', '🎈', '🔥', '⭐', '❤️', '💚', '💙', '💜', '🧡', '💛', '✨', '💫', '🎊'],
        'Comida': ['☕', '🍫', '🍰', '🍕', '🍎', '🍓', '🍪', '🧁']
    };

    $(function () {
        var $widget = $('#chat-widget');

        if (!$widget.length) {
            return;
        }

        var resumenUrl = $widget.attr('data-url-resumen');
        var abrirUrl = $widget.attr('data-url-abrir');
        var grupoUrl = $widget.attr('data-url-grupo');
        var secretUrl = $widget.attr('data-url-secret');
        var chatBase = $widget.attr('data-url-chat');
        var preferenciasUrl = $widget.attr('data-url-preferencias');
        var soundEnabled = $widget.attr('data-sound') === '1';
        var inAppEnabled = $widget.attr('data-in-app') === '1';
        var isAdmin = $widget.attr('data-is-admin') === '1';
        var token = $widget.attr('data-csrf');
        var pendingOpenId = null;

        try {
            var requestedConversation = new window.URLSearchParams(window.location.search).get('conversacion');
            var requestedId = requestedConversation ? parseInt(requestedConversation, 10) : NaN;

            pendingOpenId = isNaN(requestedId) ? null : requestedId;
        } catch (error) {
            pendingOpenId = null;
        }

        var currentId = null;
        var pollTimer = null;
        var messageTimer = null;
        var lastUnread = 0;
        var audioContext = null;
        var activeMessage = null;

        function escape(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        function chatBody() {
            var $input = $('#chat-input');
            var html = $input.html() || '';
            var text = $input.text().trim();

            if (!text) {
                return '';
            }

            return html === text ? text : html;
        }

        function chatText() {
            return $.trim($('#chat-input').text());
        }

        function clearChat() {
            $('#chat-input').empty();
        }

        function insertEmoji(emoji) {
            var input = $('#chat-input')[0];

            input.focus();

            if (document.queryCommandSupported && document.queryCommandSupported('insertText')) {
                document.execCommand('insertText', false, emoji);
            } else {
                input.appendChild(document.createTextNode(emoji));
            }
        }

        function beep() {
            if (!soundEnabled) {
                return;
            }

            try {
                audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
                var oscillator = audioContext.createOscillator();
                var gain = audioContext.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = 660;
                gain.gain.value = 0.05;
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.12);
            } catch (error) {
                // Sin audio disponible.
            }
        }

        function updateSoundButton() {
            var $button = $('#chat-sound-toggle');

            if (!$button.length) {
                return;
            }

            $button
                .attr('aria-pressed', soundEnabled ? 'true' : 'false')
                .attr('title', soundEnabled ? 'Silenciar el chat' : 'Activar el sonido del chat')
                .attr('aria-label', soundEnabled ? 'Silenciar el chat' : 'Activar el sonido del chat')
                .find('i')
                .attr('class', soundEnabled ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark');
        }

        function openPendingConversation(response) {
            if (!pendingOpenId) {
                return;
            }

            var items = (response.conversations || []).concat(response.moderation || []);
            var found = null;

            for (var index = 0; index < items.length; index += 1) {
                if (items[index].id === pendingOpenId) {
                    found = items[index];
                    break;
                }
            }

            pendingOpenId = null;

            if (!found) {
                return;
            }

            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', window.location.pathname);
            }

            openConversation(found.id, found.title, found.subtitle);
        }

        function setBadge(count) {
            var $badge = $('#chat-badge');

            if (!inAppEnabled || count <= 0) {
                $badge.prop('hidden', true).text('0');
            } else {
                $badge.prop('hidden', false).text(count > 99 ? '99+' : count);
            }
        }

        function alertDialog(message) {
            if (window.bootbox) {
                window.bootbox.alert({ title: 'Aviso', message: message, centerVertical: true });
            } else {
                window.alert(message);
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

        function promptDialog(title, message, onConfirm) {
            if (window.bootbox) {
                window.bootbox.prompt({
                    title: title,
                    message: message,
                    centerVertical: true,
                    buttons: {
                        cancel: { label: 'Cancelar', className: 'btn-outline-secondary' },
                        confirm: { label: 'Aceptar', className: 'btn-primary' }
                    },
                    callback: function (value) {
                        if (value !== null) {
                            onConfirm(value);
                        }
                    }
                });
                return;
            }

            onConfirm(window.prompt(message) || '');
        }

        function post(url, data) {
            return $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ _token: token }, data || {})
            });
        }

        function messageHtml(message) {
            var attachment = '';
            var body = message.deleted
                ? '<p class="chat-message__deleted">' + escape(message.deleted_label) + '</p>'
                : '<p class="chat-message__body">' + (message.body || '') + '</p>';
            var fileName = message.attachment_name || 'Archivo';
            var mediaType = message.type;

            if (fileName.toLowerCase().endsWith('.pdf')) {
                mediaType = 'pdf';
            }

            var list = (message.attachments && message.attachments.length)
                ? message.attachments
                : (message.attachment_url ? [{ url: message.attachment_url, type: mediaType, name: fileName }] : []);
            var visual = list.filter(function (item) {
                return item.type === 'image' || item.type === 'video';
            });
            var attachment = '';

            if (visual.length && !message.deleted) {
                var shown = visual.length > 4 ? visual.slice(0, 4) : visual;

                attachment = '<div class="chat-media-grid count-' + (visual.length > 4 ? 'many' : visual.length) + '">' +
                    shown.map(function (item, index) {
                        var more = (visual.length > 4 && index === 3)
                            ? '<span class="chat-media__more">+' + (visual.length - 4) + '</span>'
                            : '';

                        return '<button type="button" class="chat-media chat-media-open" data-index="' + index + '"' +
                            ' data-src="' + item.url + '" data-type="' + item.type + '" data-name="' + escape(item.name || '') + '">' +
                            (item.type === 'image'
                                ? '<img src="' + item.url + '" alt="Imagen adjunta">'
                                : '<video src="' + item.url + '"></video>') + more + '</button>';
                    }).join('') + '</div>';
            } else if (list.length && !message.deleted) {
                var item = list[0];

                if (item.type === 'pdf') {
                    attachment = '<div class="chat-attachment__pdf chat-media-open" data-lightbox="pdf" data-src="' + item.url +
                        '" data-name="' + escape(item.name) + '"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> ' +
                        escape(item.name) + '</div>';
                } else if (item.type === 'audio') {
                    attachment = '<div class="chat-audio">' +
                        '<canvas class="chat-wave chat-audio-open" width="220" height="40" data-url="' + item.url +
                        '" data-name="' + escape(item.name) + '"></canvas>' +
                        '<audio class="chat-attachment__audio" controls src="' + item.url + '"></audio>' +
                        '</div>';
                } else if (item.type === 'contact') {
                    attachment = '<div class="chat-attachment__contact"><i class="fa-solid fa-address-card" aria-hidden="true"></i> ' +
                        escape(item.name) + '</div>';
                } else {
                    attachment = '<div class="chat-file">' +
                        '<a class="chat-attachment__file" href="' + item.url + '" download target="_blank" rel="noopener">' +
                        '<i class="fa-solid ' + fileIcon(item.name) + '" aria-hidden="true"></i> ' +
                        escape(item.name) + '</a>' +
                        (isTextFile(item.name)
                            ? '<button type="button" class="chat-file__toggle" data-url="' + item.url +
                                '" data-name="' + escape(item.name) + '">Ver texto</button>' +
                                '<pre class="chat-file__content" hidden></pre>'
                            : '') +
                        '</div>';
                }
            }

            if (message.type === 'location' && !message.deleted) {
                attachment = '<a class="chat-attachment__location" href="' + (message.link_url || '#') +
                    '" target="_blank" rel="noopener"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Ver ubicación</a>';
            }

            var reactions = (message.reactions || []).map(function (reaction) {
                return '<button type="button" class="chat-reaction' + (reaction.mine ? ' is-mine' : '') +
                    '" data-reaction="' + escape(reaction.emoji) + '">' +
                    escape(reaction.emoji) + ' ' + reaction.count + '</button>';
            }).join('');

            var check = '';
            var linkCard = '';

            if (message.link_url) {
                linkCard = '<a class="chat-link-card" href="' + message.link_url + '" target="_blank" rel="noopener">' +
                    (message.link_image ? '<img src="' + message.link_image + '" alt="">' : '') +
                    '<span><strong>' + escape(message.link_title || message.link_url) + '</strong>' +
                    '<small>' + escape(message.link_description || '') + '</small></span></a>';
            }

            if (message.mine && !message.deleted) {
                check = '<i class="fa-solid fa-check chat-check' + (message.read ? ' chat-check--read' : '') + '"' +
                    ' title="' + (message.read ? 'Leído' : (message.delivered ? 'Entregado' : 'Enviado')) + '" aria-hidden="true"></i>';
            }

            return '<div class="chat-message' +
                (message.mine ? ' chat-message--mine' : '') +
                (message.is_moderation ? ' chat-message--moderation' : '') +
                (message.deleted ? ' chat-message--deleted' : '') + '"' +
                ' data-message-id="' + message.id + '"' +
                ' data-body="' + escape(message.body) + '"' +
                ' data-can-edit="' + (message.can_edit ? '1' : '0') + '"' +
                ' data-can-delete="' + (message.can_delete ? '1' : '0') + '"' +
                ' data-reported="' + (message.reported ? '1' : '0') + '">' +
                attachment + body + linkCard +
                '<p class="chat-message__meta">' + escape(message.sender) + ' · ' + escape(message.created_at) + ' ' +
                (message.edited ? '<span class="chat-message__edited">editado</span> ' : '') + check + '</p>' +
                (reactions ? '<div class="chat-message__reactions">' + reactions + '</div>' : '') +
                '</div>';
        }

        function dateLabel(date) {
            if (!date) {
                return '';
            }

            var today = new Date();
            var target = new Date(date + 'T12:00:00');
            var diff = Math.round((today.setHours(12, 0, 0, 0) - target.getTime()) / 86400000);

            if (diff === 0) {
                return 'Hoy';
            }

            if (diff === 1) {
                return 'Ayer';
            }

            if (diff > 1 && diff < 7) {
                return ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][target.getDay()];
            }

            return target.toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        }

        var pendingMessages = {};
        var pendingSeq = 0;
        var pendingActive = null;
        var lastData = { messages: [], new_count: 0 };

        function currentPending() {
            if (!pendingMessages[currentId]) {
                pendingMessages[currentId] = [];
            }

            return pendingMessages[currentId];
        }

        function pendingHtml(pending) {
            var indicator = pending.status === 'failed'
                ? '<i class="fa-solid fa-xmark chat-failed" title="Falló el envío"></i>'
                : '<i class="fa-regular fa-clock chat-clock" title="Enviando"></i>';
            var content = pending.body || (pending.files && pending.files.length ? pending.files[0].name : '');

            return '<div class="chat-message chat-message--mine chat-message--pending" data-pending-id="' + pending.id + '"' +
                ' data-body="' + escape(content) + '">' +
                '<p class="chat-message__body">' + escape(content) + '</p>' +
                '<p class="chat-message__meta">Tú · ' + indicator + '</p></div>';
        }

        function renderMessages() {
            var html = '';
            var lastDate = null;
            var messages = lastData.messages || [];

            messages.forEach(function (message, index) {
                if (message.created_date !== lastDate) {
                    lastDate = message.created_date;
                    html += '<div class="chat-date">' + escape(dateLabel(message.created_date)) + '</div>';
                }

                if (lastData.new_count > 0 && index === (messages.length - lastData.new_count)) {
                    html += '<div class="chat-new-divider">' + lastData.new_count + ' mensajes nuevos</div>';
                }

                html += messageHtml(message);
            });

            currentPending().forEach(function (pending) {
                html += pendingHtml(pending);
            });

            var $messages = $('#chat-messages');
            var wasAtBottom = ($messages[0].scrollHeight - $messages.scrollTop() - $messages[0].clientHeight) < 80;
            var previousScroll = $messages.scrollTop();

            $messages.html(html || '<p class="chat-hint">Aún no hay mensajes.</p>');

            $('#chat-messages .chat-file__toggle').each(function () {
                var url = $(this).data('url');

                if (openTexts[url] && textCache[url] !== undefined) {
                    $(this).next('.chat-file__content')
                        .text(textCache[url])
                        .prop('hidden', false)
                        .scrollTop(textScroll[url] || 0);
                    $(this).text('Ocultar');
                }
            });

            if (wasAtBottom) {
                $messages.scrollTop($messages[0].scrollHeight);
            } else {
                $messages.scrollTop(previousScroll);
            }

            drawWaveforms();
        }

        function loadMessages() {
            if (!currentId) {
                return;
            }

            $.getJSON(chatBase + '/' + currentId + '/mensajes')
                .done(function (response) {
                    lastData = response;
                    renderMessages();
                })
                .fail(function () {
                    $('#chat-messages').html('<p class="chat-hint">No se pudieron cargar los mensajes.</p>');
                });
        }

        function statusKind(subtitle) {
            if (/^(En línea|\d+ en línea)/i.test(subtitle)) {
                return 'online';
            }

            if (/^Última vez/i.test(subtitle)) {
                return 'last';
            }

            return 'offline';
        }

        function openConversation(id, title, subtitle) {
            currentId = id;
            $('#chat-title').text(title || '');
            $('#chat-subtitle').text(subtitle || '');
            $('#chat-status').attr('class', 'chat-status chat-status--' + statusKind(subtitle || ''));
            $('#chat-body').hide();
            $('#chat-panel > .chat-tabs').hide();
            $('#chat-conversation').prop('hidden', false).removeClass('is-gallery');
            $('#chat-gallery-panel').prop('hidden', true);
            $('#chat-messages').html('<p class="chat-hint">Cargando mensajes...</p>');

            loadMessages();

            window.clearInterval(messageTimer);
            messageTimer = window.setInterval(loadMessages, 3000);
        }

        function renderUsers(items) {
            if (!items || !items.length) {
                $('#chat-users').html('<p class="chat-hint">No hay otros usuarios disponibles.</p>');
                return;
            }

            $('#chat-users').html(items.map(function (user) {
                return '<button type="button" class="chat-item chat-user-open" data-id="' + user.id + '">' +
                    '<span class="chat-item__title">' + escape(user.name) +
                    ' <span class="' + (user.online ? 'chat-online' : 'chat-offline') + '"' +
                    ' title="' + (user.online ? 'En línea' : 'Desconectado') + '"></span></span>' +
                    '<span class="chat-item__meta">' + escape(user.role) +
                    (user.online ? ' · En línea' : ' · Desconectado') + '</span>' +
                    '</button>';
            }).join(''));
        }

        function renderConversations(items) {
            if (!items || !items.length) {
                $('#chat-conversations').html('<p class="chat-hint">Aún no tienes conversaciones.</p>');
                return;
            }

            $('#chat-conversations').html(items.map(function (item) {
                return '<button type="button" class="chat-item chat-conversation-open" data-id="' + item.id + '"' +
                    ' data-title="' + escape(item.title) + '"' +
                    ' data-subtitle="' + escape(item.subtitle || (item.is_group ? 'Grupo' : 'Conversación directa')) + '">' +
                    '<span class="chat-item__title">' + escape(item.title) + '</span>' +
                    (item.unread > 0 ? '<span class="chat-item__badge">' + item.unread + '</span>' : '') +
                    '<span class="chat-item__meta">' + escape(item.last_message || 'Sin mensajes') + '</span>' +
                    '</button>';
            }).join(''));
        }

        function renderModeration(items) {
            if (!isAdmin) {
                return;
            }

            if (!items || !items.length) {
                $('#chat-moderation').html('<p class="chat-hint">No hay conversaciones para moderar.</p>');
                return;
            }

            $('#chat-moderation').html(items.map(function (item) {
                return '<button type="button" class="chat-item chat-moderation-open" data-id="' + item.id + '"' +
                    ' data-title="' + escape(item.title) + '"' +
                    ' data-subtitle="' + escape(item.subtitle || 'Moderación') + '">' +
                    '<span class="chat-item__title">' + escape(item.title) + '</span>' +
                    '<span class="chat-item__meta">' + escape(item.is_group ? 'Grupo' : 'Conversación directa') + '</span>' +
                    '</button>';
            }).join(''));
        }

        function renderGroupUsers(users) {
            $('#chat-group-users').html(users.map(function (user) {
                return '<label class="chat-group__user">' +
                    '<input type="checkbox" class="form-check-input chat-group-user" value="' + user.id + '"> ' +
                    escape(user.name) + '</label>';
            }).join(''));
        }

        function refresh() {
            $.getJSON(resumenUrl)
                .done(function (response) {
                    renderConversations(response.conversations);
                    renderUsers(response.users);
                    renderModeration(response.moderation);

                    if (isAdmin && response.users) {
                        renderGroupUsers(response.users);
                    }

                    if (response.unread > lastUnread && lastUnread > 0) {
                        beep();
                    }

                    lastUnread = response.unread || 0;
                    setBadge(lastUnread);
                    openPendingConversation(response);
                })
                .fail(function () {
                    $('#chat-users').html('<p class="chat-hint">No se pudieron cargar los usuarios. Intenta de nuevo.</p>');
                });
        }

        function showList() {
            currentId = null;
            $('#chat-conversation').prop('hidden', true).removeClass('is-gallery');
            $('#chat-gallery-panel').prop('hidden', true);
            $('#chat-panel > .chat-tabs').show();
            $('#chat-body').show();
            window.clearInterval(messageTimer);
        }

        function hideContextMenu() {
            $('#chat-context-menu').prop('hidden', true).css({ top: '', left: '' });
            activeMessage = null;
            pendingActive = null;
        }

        function showContextMenu(event, $message) {
            event.preventDefault();
            event.stopPropagation();
            activeMessage = $message;

            var $menu = $('#chat-context-menu');

            $menu.find('[data-action="edit"]').prop('hidden', $message.data('can-edit') !== 1);
            $menu.find('[data-action="delete"]').prop('hidden', $message.data('can-delete') !== 1);
            $menu.find('[data-action="report"]').prop('hidden',
                $message.data('can-edit') === 1 || $message.data('reported') === 1);

            var pendingId = $message.data('pending-id');

            pendingActive = pendingId
                ? currentPending().find(function (item) { return item.id === pendingId; })
                : null;

            $menu.find('[data-action="resend"]').prop('hidden', !pendingActive || pendingActive.status !== 'failed');

            if (pendingId) {
                $menu.find('[data-action="reaction"], [data-action="edit"], [data-action="delete"], [data-action="report"]')
                    .prop('hidden', true);
            }

            $menu.prop('hidden', false).css({
                top: event.clientY + 'px',
                left: event.clientX + 'px'
            });
        }

        function buildEmojiPalette(mode) {
            var html = Object.keys(EMOJIS).map(function (category) {
                return '<p class="chat-emoji__category">' + escape(category) + '</p>' +
                    '<div class="chat-emoji__grid">' + EMOJIS[category].map(function (emoji) {
                        return '<button type="button" class="chat-emoji" data-emoji="' + emoji + '">' + emoji + '</button>';
                    }).join('') + '</div>';
            }).join('');

            $('#chat-emoji-palette').data('mode', mode).html(html).prop('hidden', false);
        }

        $('#chat-toggle').on('click', function () {
            var $panel = $('#chat-panel');
            var expanded = $panel.prop('hidden');

            $panel.prop('hidden', !expanded);
            $(this).attr('aria-expanded', expanded ? 'true' : 'false');

            if (expanded) {
                refresh();
                window.clearInterval(pollTimer);
                pollTimer = window.setInterval(refresh, 6000);
            } else {
                window.clearInterval(pollTimer);
            }
        });

        $('#chat-close').on('click', function () {
            $('#chat-panel').prop('hidden', true);
            $('#chat-toggle').attr('aria-expanded', 'false');
            window.clearInterval(pollTimer);
        });

        $('#chat-sound-toggle').on('click', function () {
            var next = !soundEnabled;

            $.ajax({
                url: preferenciasUrl,
                method: 'POST',
                dataType: 'json',
                data: { _token: token, chat_sound_enabled: next ? 1 : 0 }
            }).done(function () {
                soundEnabled = next;
                updateSoundButton();
            }).fail(function () {
                alertDialog('No se pudo guardar la preferencia de sonido. Intenta de nuevo.');
            });
        });

        updateSoundButton();

        $(document).on('click', function (event) {
            var $panel = $('#chat-panel');

            if ($panel.prop('hidden')) {
                return;
            }

            if ($(event.target).closest('#chat-widget').length) {
                return;
            }

            $panel.prop('hidden', true);
            $('#chat-toggle').attr('aria-expanded', 'false');
            window.clearInterval(pollTimer);
        });

        $('.chat-tab').on('click', function () {
            var tab = $(this).data('tab');

            $('.chat-tab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.chat-tab-panel').prop('hidden', true);
            $('.chat-tab-panel[data-panel="' + tab + '"]').prop('hidden', false);

            if (tab === 'users') {
                refresh();
            }

            if (tab === 'secret') {
                loadSecret();
            }
        });

        function loadSecret() {
            $.getJSON(secretUrl).done(function (response) {
                var items = response.items || [];

                $('#chat-secret').html(items.length ? items.map(function (item) {
                    return '<button type="button" class="chat-item chat-secret-open" data-id="' + item.conversation_id + '"' +
                        ' data-online="' + (item.online ? '1' : '0') + '">' +
                        '<span class="chat-item__title">' + escape(item.title) +
                        ' <span class="' + (item.online ? 'chat-online' : 'chat-offline') + '"></span></span>' +
                        '<span class="chat-item__meta">' + (item.online ? 'En línea' : 'Desconectado') + '</span></button>';
                }).join('') : '<p class="chat-hint">Aún no tienes amigo secreto asignado.</p>');
            }).fail(function () {
                $('#chat-secret').html('<p class="chat-hint">No se pudo cargar el amigo secreto.</p>');
            });
        }

        $widget.on('click', '.chat-secret-open', function () {
            var online = $(this).data('online') === 1;
            openConversation($(this).data('id'), 'Amigo secreto', online ? 'En línea' : 'Desconectado');
        });

        $widget.on('click', '.chat-conversation-open, .chat-moderation-open', function () {
            openConversation($(this).data('id'), $(this).data('title'), $(this).data('subtitle'));
        });

        $widget.on('click', '.chat-user-open', function () {
            post(abrirUrl, { user_id: $(this).data('id') })
                .done(function (response) {
                    openConversation(response.conversation.id, response.conversation.title, 'Conversación directa');
                });
        });

        $('#chat-back').on('click', function () {
            showList();
        });

        $('#chat-messages').on('contextmenu', '.chat-message', function (event) {
            showContextMenu(event, $(this));
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-context-menu').length) {
                hideContextMenu();
            }
        });

        $('#chat-context-menu').on('click', '.chat-context-item', function () {
            var action = $(this).data('action');
            var $message = activeMessage;

            if (!$message) {
                return;
            }

            var id = $message.data('message-id');
            var targetPending = pendingActive;

            hideContextMenu();

            if (action === 'resend') {
                if (targetPending && targetPending.status === 'failed') {
                    sendPending(targetPending);
                }

                return;
            }

            if (action === 'reaction') {
                buildEmojiPalette({ type: 'reaction', messageId: id });
                return;
            }

            if (action === 'edit') {
                promptDialog('Editar mensaje', 'Escribe el nuevo texto:', function (value) {
                    if (!value) {
                        return;
                    }

                    post(chatBase + '/mensajes/' + id + '/editar', { body: value })
                        .done(loadMessages)
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo editar.');
                        });
                });
                return;
            }

            if (action === 'delete') {
                confirmDialog('¿Desea eliminar el mensaje?', function () {
                    post(chatBase + '/mensajes/' + id + '/eliminar', {})
                        .done(loadMessages)
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo eliminar.');
                        });
                });
                return;
            }

            if (action === 'report') {
                promptDialog('Reportar mensaje', 'Escribe el motivo del reporte:', function (value) {
                    if (!value) {
                        return;
                    }

                    post(chatBase + '/mensajes/' + id + '/reportar', { reason: value })
                        .done(function () {
                            alertDialog('Reporte enviado a la administración.');
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo reportar.');
                        });
                });
            }
        });

        $(document).on('click', '.chat-reaction', function (event) {
            event.stopPropagation();

            var $button = $(this);
            var $message = $button.closest('.chat-message');

            post(chatBase + '/mensajes/' + $message.data('message-id') + '/reaccionar', {
                emoji: $button.data('reaction')
            }).done(loadMessages).fail(function (xhr) {
                alertDialog((xhr.responseJSON || {}).message || 'No se pudo reaccionar.');
            });
        });

        $('#chat-emoji-palette').on('click', '.chat-emoji', function (event) {
            event.stopPropagation();

            var emoji = $(this).data('emoji');
            var mode = $('#chat-emoji-palette').data('mode') || {};

            if (mode.type === 'reaction') {
                post(chatBase + '/mensajes/' + mode.messageId + '/reaccionar', { emoji: emoji })
                    .done(function () {
                        $('#chat-emoji-palette').prop('hidden', true);
                        loadMessages();
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo reaccionar.');
                    });
            } else {
                var $input = $('#chat-input');
                insertEmoji(emoji);
                $('#chat-emoji-palette').prop('hidden', true);
            }
        });

        $('#chat-emoji-toggle').on('click', function (event) {
            event.stopPropagation();
            buildEmojiPalette({ type: 'input' });
        });

        $('#chat-file').on('change', function () {
            addTrayFiles(this.files);
            this.value = '';
        });

        $('#chat-conversation').on('dragover', function (event) {
            event.preventDefault();
        });

        $('#chat-conversation').on('drop', function (event) {
            event.preventDefault();

            var dropped = event.originalEvent && event.originalEvent.dataTransfer
                ? event.originalEvent.dataTransfer.files
                : null;

            if (dropped && dropped.length) {
                addTrayFiles(dropped);
            }
        });

        $('#chat-form').on('submit', function (event) {
            event.preventDefault();

            var body = chatBody();

            if ((!body && !pendingFiles.length) || !currentId) {
                return;
            }

            queuePending(body, pendingFiles.slice(), currentLink);
            clearTray();
            clearChat();
            $('#chat-file').val('');
            $('#chat-file-name').prop('hidden', true);
            $('#chat-link-preview').prop('hidden', true);
            currentLink = null;
        });

        $('#chat-group-create').on('click', function () {
            var name = $.trim($('#chat-group-name').val());
            var ids = $('.chat-group-user:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();

            if (!name || !ids.length) {
                $('#chat-group-error').text('Escribe el nombre y selecciona al menos un usuario.');
                return;
            }

            post(grupoUrl, { name: name, user_ids: ids })
                .done(function (response) {
                    $('#chat-group-name').val('');
                    $('.chat-group-user').prop('checked', false);
                    $('#chat-group-error').text('');

                    refresh();
                    openConversation(response.conversation.id, response.conversation.title, 'Grupo');
                })
                .fail(function (xhr) {
                    $('#chat-group-error').text((xhr.responseJSON || {}).message || 'No se pudo crear el grupo.');
                });
        });

        function sendPending(pending) {
            if (!currentId) {
                return;
            }

            pending.status = 'sending';
            renderMessages();

            var data = new FormData();
            data.append('_token', token);

            if (pending.body) {
                data.append('body', pending.body);
            }

            (pending.files || []).forEach(function (file) {
                data.append('files[]', file);
            });

            if (pending.coords) {
                data.append('latitude', pending.coords.latitude);
                data.append('longitude', pending.coords.longitude);
            }

            if (pending.link && pending.link.url) {
                data.append('link_url', pending.link.url);

                if (pending.link.title) {
                    data.append('link_title', pending.link.title);
                }

                if (pending.link.description) {
                    data.append('link_description', pending.link.description);
                }

                if (pending.link.image && /^https?:\/\//.test(pending.link.image)) {
                    data.append('link_image', pending.link.image);
                }
            }

            $.ajax({
                url: chatBase + '/' + currentId + '/mensajes',
                method: 'POST',
                dataType: 'json',
                data: data,
                processData: false,
                contentType: false
            }).done(function () {
                var list = currentPending();
                var index = list.indexOf(pending);

                if (index > -1) {
                    list.splice(index, 1);
                }

                loadMessages();
                refresh();
            }).fail(function () {
                pending.status = 'failed';
                renderMessages();
            });
        }

        function queuePending(body, files, link, coords) {
            if (!currentId) {
                return;
            }

            var pending = {
                id: 'p' + (++pendingSeq),
                body: body || '',
                files: files || [],
                link: link || null,
                coords: coords || null,
                status: 'sending'
            };

            currentPending().push(pending);
            renderMessages();
            sendPending(pending);
        }

        function fileIcon(name) {
            var extension = (name || '').split('.').pop().toLowerCase();

            if (extension === 'pdf') {
                return 'fa-file-pdf';
            }

            if (['doc', 'docx', 'odt', 'rtf'].indexOf(extension) !== -1) {
                return 'fa-file-word';
            }

            if (['xls', 'xlsx', 'csv', 'ods'].indexOf(extension) !== -1) {
                return 'fa-file-excel';
            }

            if (['ppt', 'pptx', 'odp'].indexOf(extension) !== -1) {
                return 'fa-file-powerpoint';
            }

            if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2'].indexOf(extension) !== -1) {
                return 'fa-file-zipper';
            }

            if (['txt', 'md', 'log'].indexOf(extension) !== -1) {
                return 'fa-file-lines';
            }

            if (['js', 'php', 'py', 'html', 'css', 'json', 'xml', 'sql', 'java', 'c', 'cpp', 'ts', 'sh', 'yml', 'yaml', 'vue'].indexOf(extension) !== -1) {
                return 'fa-file-code';
            }

            return 'fa-file';
        }

        function isTextFile(name) {
            var extension = (name || '').split('.').pop().toLowerCase();

            return ['txt', 'md', 'log', 'js', 'php', 'py', 'html', 'css', 'json', 'xml', 'sql',
                'java', 'c', 'cpp', 'ts', 'sh', 'yml', 'yaml', 'vue'].indexOf(extension) !== -1;
        }

        var pendingFiles = [];

        function fileIsMedia(file) {
            return file.type.startsWith('image/') || file.type.startsWith('video/');
        }

        function renderTray() {
            var $tray = $('#chat-attachment-tray');

            if (!pendingFiles.length) {
                $tray.prop('hidden', true);
                $('#chat-tray-items').empty();
                return;
            }

            $('#chat-tray-items').html(pendingFiles.map(function (file, index) {
                var url = URL.createObjectURL(file);
                var preview = file.type.startsWith('image/')
                    ? '<img src="' + url + '" alt="">'
                    : (file.type.startsWith('video/')
                        ? '<video src="' + url + '"></video>'
                        : (file.type.startsWith('audio/')
                            ? '<i class="fa-solid fa-microphone"></i>'
                            : '<i class="fa-solid ' + fileIcon(file.name) + '"></i>'));

                return '<button type="button" class="chat-tray__item" data-index="' + index + '">' + preview + '</button>';
            }).join(''));

            $tray.prop('hidden', false);
        }

        function clearTray() {
            pendingFiles = [];
            renderTray();
            $('#chat-file').val('');
        }

        function addTrayFiles(files) {
            var list = Array.prototype.slice.call(files).filter(Boolean);

            if (!list.length) {
                return;
            }

            var currentHasMedia = pendingFiles.some(fileIsMedia);
            var currentHasOther = pendingFiles.some(function (file) { return !fileIsMedia(file); });
            var incomingHasMedia = list.some(fileIsMedia);
            var incomingHasOther = list.some(function (file) { return !fileIsMedia(file); });

            if (incomingHasOther && (list.length > 1 || currentHasMedia || currentHasOther)) {
                alertDialog('Puedes enviar varias fotos y videos juntos. Los audios, PDF y archivos van en un mensaje aparte.');
                return;
            }

            if (incomingHasMedia && currentHasOther) {
                alertDialog('No puedes mezclar fotos o videos con audios o archivos en el mismo mensaje.');
                return;
            }

            var converted = list.slice();
            var remaining = list.length;

            function finish() {
                remaining -= 1;

                if (remaining > 0) {
                    return;
                }

                pendingFiles = pendingFiles.concat(converted).slice(0, 6);
                renderTray();
            }

            list.forEach(function (file, index) {
                var convert = file.type.startsWith('image/')
                    && file.type !== 'image/gif'
                    && !/\.gif$/i.test(file.name);

                if (convert) {
                    convertToWebp(file, function (webp) {
                        converted[index] = webp;
                        finish();
                    });
                } else {
                    finish();
                }
            });
        }

        $('#chat-tray-items').on('click', '.chat-tray__item', function () {
            var index = parseInt($(this).data('index'), 10) || 0;
            var items = pendingFiles.map(function (file) {
                return {
                    url: URL.createObjectURL(file),
                    type: file.type.startsWith('image/') ? 'image' : (file.type.startsWith('video/') ? 'video' : (file.type.startsWith('audio/') ? 'audio' : 'file')),
                    name: file.name
                };
            });

            openCarousel(items, index);
        });

        $('#chat-tray-clear').on('click', clearTray);

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                clearTray();
                $('#chat-text-menu').prop('hidden', true);
            }
        });

        function convertToWebp(file, callback) {
            var reader = new FileReader();

            reader.onload = function () {
                var image = new Image();

                image.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = image.width;
                    canvas.height = image.height;
                    canvas.getContext('2d').drawImage(image, 0, 0);

                    canvas.toBlob(function (blob) {
                        callback(new File([blob], 'foto.webp', { type: 'image/webp' }));
                    }, 'image/webp', 0.82);
                };

                image.src = reader.result;
            };

            reader.readAsDataURL(file);
        }

        function sendLocation() {
            if (!navigator.geolocation) {
                alertDialog('La ubicación no está disponible en este dispositivo.');
                return;
            }

            navigator.geolocation.getCurrentPosition(function (position) {
                queuePending('', null, null, {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                });
            }, function () {
                alertDialog('No se pudo obtener la ubicación. Revisa el permiso.');
            });
        }

        var lightboxScale = 1;
        var lightboxDrag = null;

        function openLightbox(type, src, name) {
            var $stage = $('#chat-lightbox-stage').empty();

            $('#chat-lightbox-name').text(name || '');
            $('#chat-lightbox-download').attr('href', src || '#');
            lightboxScale = 1;

            if (type === 'image') {
                $stage.html('<img id="chat-lightbox-image" src="' + src + '" alt="' + escape(name) + '">');
            } else if (type === 'video') {
                $stage.html('<video controls src="' + src + '"></video>');
            } else if (type === 'pdf') {
                $stage.html('<iframe src="' + src + '" title="PDF"></iframe>');
            } else {
                $stage.html('<audio controls src="' + src + '"></audio>');
            }

            $('#chat-lightbox').prop('hidden', false);
        }

        function closeLightbox() {
            $('#chat-lightbox').prop('hidden', true);
            $('#chat-lightbox-stage').empty();
            lightboxScale = 1;
        }

        $('#chat-lightbox-close').on('click', closeLightbox);
        $('#chat-lightbox').on('click', function (event) {
            if (event.target === this) {
                closeLightbox();
            }
        });

        $('#chat-lightbox-stage').on('click', function (event) {
            if ($(event.target).is('img, video, audio, iframe, button, a, input')) {
                return;
            }

            closeLightbox();
        });

        var carousel = { items: [], index: 0 };

        function renderCarousel() {
            var item = carousel.items[carousel.index];

            if (!item) {
                return;
            }

            $('#chat-lightbox-name').text(item.name || '');
            $('#chat-lightbox-download').attr('href', item.url);

            var $stage = $('#chat-lightbox-stage').empty();

            if (item.type === 'image') {
                $stage.html('<img id="chat-lightbox-image" src="' + item.url + '" alt="' + escape(item.name) + '">');
            } else if (item.type === 'video') {
                $stage.html('<video controls src="' + item.url + '"></video>');
            } else if (item.type === 'pdf') {
                $stage.html('<iframe src="' + item.url + '" title="PDF"></iframe>');
            } else {
                $stage.html('<div class="chat-audio chat-audio--carousel">' +
                    '<canvas class="chat-wave" width="320" height="60" data-url="' + item.url + '"></canvas>' +
                    '<audio class="chat-attachment__audio" controls src="' + item.url + '"></audio></div>');
            }

            $('.chat-lightbox__footer .chat-carousel__thumbs').remove();

            if (carousel.items.length > 1) {
                $stage.append(
                    '<button type="button" class="chat-carousel__arrow chat-carousel__arrow--prev" aria-label="Anterior">&#8249;</button>' +
                    '<button type="button" class="chat-carousel__arrow chat-carousel__arrow--next" aria-label="Siguiente">&#8250;</button>'
                );
            }

            $('.chat-lightbox__footer').append(
                '<div class="chat-carousel__thumbs">' + carousel.items.map(function (entry, index) {
                    var thumb = entry.type === 'video'
                        ? '<video src="' + entry.url + '"></video>'
                        : (entry.type === 'image' ? '<img src="' + entry.url + '" alt="">' : '<i class="fa-regular fa-file"></i>');

                    return '<button type="button" class="chat-carousel__thumb' + (index === carousel.index ? ' is-active' : '') +
                        '" data-index="' + index + '" aria-label="Ver elemento ' + (index + 1) + '">' + thumb + '</button>';
                }).join('') + '</div>'
            );
        }

        function openCarousel(items, index) {
            carousel.items = items;
            carousel.index = Math.max(0, Math.min(index || 0, items.length - 1));
            renderCarousel();
            drawWaveforms();
            $('#chat-lightbox').prop('hidden', false);
        }

        $(document).on('click', '.chat-media-open', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var $grid = $(this).closest('.chat-media-grid');

            if ($grid.length) {
                var items = $grid.find('.chat-media-open').map(function () {
                    return {
                        url: $(this).data('src'),
                        type: $(this).data('type'),
                        name: $(this).data('name')
                    };
                }).get();

                openCarousel(items, $(this).data('index') || 0);
                return;
            }

            openLightbox($(this).data('lightbox') || $(this).data('type'), $(this).data('src'), $(this).data('name'));
        });

        $('#chat-lightbox-stage').on('click', '.chat-carousel__arrow', function () {
            if (!carousel.items.length) {
                return;
            }

            var step = $(this).hasClass('chat-carousel__arrow--next') ? 1 : -1;
            carousel.index = (carousel.index + step + carousel.items.length) % carousel.items.length;
            renderCarousel();
            drawWaveforms();
        });

        $(document).on('click', '.chat-carousel__thumb', function () {
            carousel.index = parseInt($(this).data('index'), 10) || 0;
            renderCarousel();
            drawWaveforms();
        });

        $(document).on('keydown', function (event) {
            if ($('#chat-lightbox').prop('hidden') || !carousel.items.length) {
                return;
            }

            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                var step = event.key === 'ArrowRight' ? 1 : -1;
                carousel.index = (carousel.index + step + carousel.items.length) % carousel.items.length;
                renderCarousel();
            }

            if (event.key === 'Escape') {
                closeLightbox();
            }
        });

        $('#chat-lightbox-stage').on('wheel', '#chat-lightbox-image', function (event) {
            event.preventDefault();
            lightboxScale = Math.min(4, Math.max(0.5, lightboxScale + (event.originalEvent.deltaY < 0 ? 0.15 : -0.15)));
            $(this).css('transform', 'scale(' + lightboxScale + ')');
        });

        $('#chat-lightbox-stage').on('mousedown', '#chat-lightbox-image', function (event) {
            lightboxDrag = { x: event.clientX, y: event.clientY };
            $(this).css('cursor', 'grabbing');
        });

        $(document).on('mousemove', function (event) {
            if (lightboxDrag === null) {
                return;
            }

            var dx = event.clientX - lightboxDrag.x;
            var dy = event.clientY - lightboxDrag.y;
            lightboxDrag = { x: event.clientX, y: event.clientY };

            $('#chat-lightbox-image').css({
                marginLeft: '+=' + dx + 'px',
                marginTop: '+=' + dy + 'px'
            });
        });

        $(document).on('mouseup', function () {
            lightboxDrag = null;
            $('#chat-lightbox-image').css('cursor', 'grab');
        });

        $('#chat-lightbox-copy').on('click', function () {
            var src = $('#chat-lightbox-download').attr('href');

            fetch(src).then(function (response) { return response.blob(); }).then(function (blob) {
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
                    alertDialog('Imagen copiada.');
                } else {
                    navigator.clipboard.writeText(src);
                    alertDialog('Enlace de la imagen copiado.');
                }
            }).catch(function () {
                alertDialog('No se pudo copiar.');
            });
        });

        $('#chat-attach').on('click', function (event) {
            event.stopPropagation();
            $('#chat-attach-menu').prop('hidden', false);
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-attach-menu, #chat-attach').length) {
                $('#chat-attach-menu').prop('hidden', true);
            }
        });

        $('#chat-attach-menu').on('click', '.chat-context-item', function () {
            var action = $(this).data('attach');
            var inputs = {
                'camera-photo': 'chat-camera-photo',
                'camera-video': 'chat-camera-video',
                'image': 'chat-image-picker',
                'video': 'chat-video-picker',
                'file': 'chat-file-picker',
                'contact': 'chat-contact-picker'
            };

            $('#chat-attach-menu').prop('hidden', true);

            if (action === 'location') {
                sendLocation();
            } else if (inputs[action]) {
                $('#' + inputs[action]).trigger('click');
            }
        });

        $('#chat-camera-photo, #chat-camera-video, #chat-image-picker, #chat-video-picker, #chat-file-picker, #chat-contact-picker').on('change', function () {
            var file = this.files && this.files[0];
            var id = this.id;

            if (!file) {
                return;
            }

            if (id === 'chat-camera-photo') {
                convertToWebp(file, function (converted) {
                    addTrayFiles([converted]);
                });
            } else {
                addTrayFiles([file]);
            }

            this.value = '';
        });

        var recorder = null;
        var recorderChunks = [];
        var micStream = null;
        var audioContext = null;
        var recordTimer = null;
        var recordSeconds = 0;
        var recordShouldSend = false;
        var waveFrame = null;

        function resetRecorder() {
            window.clearInterval(recordTimer);
            window.cancelAnimationFrame(waveFrame);
            waveFrame = null;
            $('#chat-recorder').prop('hidden', true);
            $('#chat-form').prop('hidden', false);
            $('#chat-mic').removeClass('is-recording');
            $('#chat-recorder-time').text('0:00');
            recordSeconds = 0;
        }

        function drawRecorderWave(stream) {
            var Context = window.AudioContext || window.webkitAudioContext;

            if (!Context) {
                return;
            }

            audioContext = new Context();
            var source = audioContext.createMediaStreamSource(stream);
            var analyser = audioContext.createAnalyser();
            analyser.fftSize = 128;
            source.connect(analyser);

            var data = new Uint8Array(analyser.frequencyBinCount);
            var canvas = $('#chat-recorder-wave')[0];
            var context = canvas.getContext('2d');

            function frame() {
                if ($('#chat-recorder').prop('hidden')) {
                    return;
                }

                analyser.getByteFrequencyData(data);
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#2F6F62';

                var x = 0;
                var bar = 3;
                var gap = 2;

                for (var i = 0; i < data.length && x < canvas.width; i += 2) {
                    var height = Math.max(2, (data[i] / 255) * canvas.height);
                    context.fillRect(x, (canvas.height - height) / 2, bar, height);
                    x += bar + gap;
                }

                waveFrame = window.requestAnimationFrame(frame);
            }

            frame();
        }

        function startRecorder() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alertDialog('El micrófono no está disponible en este dispositivo.');
                return;
            }

            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                micStream = stream;
                recorderChunks = [];
                recordShouldSend = false;

                var options = { audioBitsPerSecond: 32000 };

                if (window.MediaRecorder && MediaRecorder.isTypeSupported &&
                    MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                    options.mimeType = 'audio/webm;codecs=opus';
                }

                recorder = new MediaRecorder(stream, options);

                recorder.ondataavailable = function (event) {
                    if (event.data.size > 0) {
                        recorderChunks.push(event.data);
                    }
                };

                recorder.onstop = function () {
                    var blob = new Blob(recorderChunks, { type: 'audio/webm' });
                    var file = new File([blob], 'audio.webm', { type: 'audio/webm' });

                    if (micStream) {
                        micStream.getTracks().forEach(function (track) { track.stop(); });
                    }

                    if (audioContext) {
                        audioContext.close();
                        audioContext = null;
                    }

                    resetRecorder();

                    if (recordShouldSend && blob.size > 0) {
                        queuePending('', [file], null);
                    }
                };

                recorder.start();
                $('#chat-form').prop('hidden', true);
                $('#chat-recorder').prop('hidden', false);
                $('#chat-mic').addClass('is-recording');
                recordSeconds = 0;
                $('#chat-recorder-time').text('0:00');

                recordTimer = window.setInterval(function () {
                    recordSeconds += 1;
                    var minutes = Math.floor(recordSeconds / 60);
                    var seconds = recordSeconds % 60;
                    $('#chat-recorder-time').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
                }, 1000);

                drawRecorderWave(stream);
            }).catch(function () {
                alertDialog('No diste permiso al micrófono.');
            });
        }

        $('#chat-mic').on('click', function () {
            if (chatText() !== '') {
                $('#chat-form').trigger('submit');
                return;
            }

            startRecorder();
        });

        $('#chat-recorder-cancel').on('click', function () {
            recordShouldSend = false;

            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            } else {
                resetRecorder();
            }
        });

        $('#chat-recorder-send').on('click', function () {
            recordShouldSend = true;

            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            } else {
                resetRecorder();
            }
        });

        var currentLink = null;
        var linkTimer = null;
        var galleryItems = [];

        $('#chat-input').on('input', function () {
            var value = $(this).text();
            var match = value.match(/https?:\/\/[^\s]+/);

            window.clearTimeout(linkTimer);

            if (!match) {
                $('#chat-link-preview').prop('hidden', true);
                currentLink = null;
                return;
            }

            linkTimer = window.setTimeout(function () {
                $.getJSON(chatBase + '/enlace', { url: match[0] }).done(function (response) {
                    var preview = response.preview || {};
                    currentLink = preview;
                    $('#chat-link-title').text(preview.title || preview.url || '');
                    $('#chat-link-description').text(preview.description || '');

                    if (preview.image) {
                        $('#chat-link-image').attr('src', preview.image).prop('hidden', false);
                    } else {
                        $('#chat-link-image').prop('hidden', true);
                    }

                    $('#chat-link-preview').prop('hidden', false);
                });
            }, 700);
        });

        $('#chat-link-close').on('click', function () {
            $('#chat-link-preview').prop('hidden', true);
            currentLink = null;
        });

        function formatSize(bytes) {
            if (!bytes) {
                return '';
            }

            if (bytes < 1024) {
                return bytes + ' B';
            }

            if (bytes < 1048576) {
                return (bytes / 1024).toFixed(1) + ' KB';
            }

            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        var waveCache = {};

        function drawPeaks(canvas, peaks, progress) {
            var context = canvas.getContext('2d');
            var played = Math.round((progress || 0) * canvas.width);
            context.clearRect(0, 0, canvas.width, canvas.height);

            peaks.forEach(function (peak, index) {
                context.fillStyle = index <= played ? '#2F6F62' : '#94A3B8';
                context.fillRect(index, peak.top, 1, peak.bar);
            });
        }

        $(document).on('timeupdate', '.chat-attachment__audio', function () {
            var audio = this;
            var canvas = $(audio).prev('canvas')[0];

            if (!canvas) {
                return;
            }

            var peaks = waveCache[canvas.dataset.url + ':' + canvas.width];

            if (!peaks) {
                return;
            }

            var progress = audio.duration ? audio.currentTime / audio.duration : 0;
            drawPeaks(canvas, peaks, progress);
        });

        $(document).on('ended', '.chat-attachment__audio', function () {
            var canvas = $(this).prev('canvas')[0];

            if (!canvas) {
                return;
            }

            var peaks = waveCache[canvas.dataset.url + ':' + canvas.width];

            if (peaks) {
                drawPeaks(canvas, peaks, 0);
            }
        });

        $(document).on('click', '.chat-audio-open', function (event) {
            event.preventDefault();
            event.stopPropagation();

            openCarousel([{
                url: $(this).data('url'),
                type: 'audio',
                name: $(this).data('name')
            }], 0);
        });

        function drawWaveforms() {
            $('.chat-wave').each(function () {
                var canvas = this;
                var url = canvas.dataset.url;

                if (!url) {
                    return;
                }

                var key = url + ':' + canvas.width;

                if (waveCache[key]) {
                    drawPeaks(canvas, waveCache[key]);
                    return;
                }

                if (canvas.dataset.drawn) {
                    return;
                }

                canvas.dataset.drawn = '1';

                fetch(url)
                    .then(function (response) { return response.arrayBuffer(); })
                    .then(function (buffer) {
                        var Context = window.AudioContext || window.webkitAudioContext;

                        if (!Context) {
                            return;
                        }

                        new Context().decodeAudioData(buffer, function (decoded) {
                            var data = decoded.getChannelData(0);
                            var width = canvas.width;
                            var height = canvas.height;
                            var step = Math.ceil(data.length / width) || 1;
                            var peaks = [];

                            for (var i = 0; i < width; i++) {
                                var min = 1;
                                var max = -1;

                                for (var j = 0; j < step; j++) {
                                    var value = data[i * step + j] || 0;

                                    if (value < min) {
                                        min = value;
                                    }

                                    if (value > max) {
                                        max = value;
                                    }
                                }

                                peaks.push({
                                    top: (1 + min) * height / 2,
                                    bar: Math.max(1, (max - min) * height / 2)
                                });
                            }

                            waveCache[key] = peaks;
                            drawPeaks(canvas, peaks);
                        });
                    })
                    .catch(function () {
                        canvas.dataset.drawn = '';
                    });
            });
        }

        function renderGallery(filter) {
            var items = galleryItems.filter(function (item) {
                if (filter === 'multimedia') {
                    return ['image', 'video'].indexOf(item.kind) !== -1;
                }
                if (filter === 'documents') {
                    return ['file', 'pdf'].indexOf(item.kind) !== -1;
                }
                if (filter === 'audios') {
                    return item.kind === 'audio';
                }
                return item.kind === 'link';
            });

            if (!items.length) {
                $('#chat-gallery-list').html('<p class="chat-hint">Sin elementos en esta pestaña.</p>');
                return;
            }

            if (filter === 'multimedia') {
                $('#chat-gallery-list').html('<div class="chat-gallery-grid">' + items.map(function (item, index) {
                    var preview = item.kind === 'video'
                        ? '<video src="' + item.url + '"></video>'
                        : '<img src="' + item.url + '" alt="">';

                    return '<button type="button" class="chat-gallery-thumb gallery-item" title="' + escape(item.name || '') + '"' +
                        ' data-index="' + index + '" data-kind="' + item.kind + '" data-src="' + item.url + '"' +
                        ' data-name="' + escape(item.name || '') + '">' + preview + '</button>';
                }).join('') + '</div>');

                return;
            }

            if (filter === 'audios') {
                $('#chat-gallery-list').html('<div class="chat-gallery-grid">' + items.map(function (item, index) {
                    return '<button type="button" class="chat-gallery-audio gallery-item" title="' + escape(item.name || '') + '"' +
                        ' data-index="' + index + '" data-kind="audio" data-src="' + item.url + '"' +
                        ' data-name="' + escape(item.name || '') + '">' +
                        '<i class="fa-solid fa-microphone-lines" aria-hidden="true"></i></button>';
                }).join('') + '</div>');

                return;
            }

            $('#chat-gallery-list').html(items.map(function (item) {
                var icon = item.kind === 'link'
                    ? 'fa-link'
                    : (item.kind === 'audio' ? 'fa-microphone' : fileIcon(item.name));

                var meta = [
                    item.extension ? item.extension.toUpperCase() : '',
                    formatSize(item.size),
                    item.created_at || ''
                ].filter(Boolean).join(' · ');

                var nameLink = item.kind === 'link'
                    ? '<a class="chat-gallery-row__name" href="' + item.url + '" target="_blank" rel="noopener"' +
                        ' title="' + escape(item.name || '') + '">' + escape(item.name || 'Enlace') + '</a>'
                    : '<a class="chat-gallery-row__name" href="' + item.url + '" download' +
                        ' title="' + escape(item.name || '') + '">' + escape(item.name || 'Archivo') + '</a>';

                return '<div class="chat-gallery-row">' +
                    '<div class="chat-gallery-row__main">' +
                    '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>' +
                    nameLink +
                    '<span class="chat-gallery-row__meta">' + escape(meta) + '</span>' +
                    '</div></div>';
            }).join(''));
        }

        $('#chat-gallery').on('click', function () {
            if (!currentId) {
                return;
            }

            var $panel = $('#chat-gallery-panel');

            if (!$panel.prop('hidden')) {
                $panel.prop('hidden', true);
                $('#chat-conversation').removeClass('is-gallery');
                return;
            }

            $.getJSON(chatBase + '/' + currentId + '/media').done(function (response) {
                galleryItems = response.items || [];
                renderGallery('multimedia');
                $panel.prop('hidden', false);
                $('#chat-conversation').addClass('is-gallery');
            });
        });

        $('#chat-gallery-panel').on('click', '[data-gallery]', function () {
            $('[data-gallery]').removeClass('is-active');
            $(this).addClass('is-active');
            renderGallery($(this).data('gallery'));
        });

        $('#chat-gallery-panel').on('click', '.gallery-item', function () {
            if ($(this).closest('.chat-gallery-grid').length) {
                var isAudio = $(this).hasClass('chat-gallery-audio');
                var list = galleryItems
                    .filter(function (item) {
                        return isAudio
                            ? item.kind === 'audio'
                            : (item.kind === 'image' || item.kind === 'video');
                    })
                    .map(function (item) {
                        return {
                            url: item.url,
                            type: isAudio ? 'audio' : item.kind,
                            name: item.name
                        };
                    });

                openCarousel(list, parseInt($(this).data('index'), 10) || 0);
                return;
            }

            openLightbox($(this).data('kind'), $(this).data('src'), $(this).data('name'));
        });

        $('#chat-input').on('contextmenu', function (event) {
            event.preventDefault();
            event.stopPropagation();
            $('#chat-text-menu').prop('hidden', false).css({
                top: event.clientY + 'px',
                left: event.clientX + 'px'
            });
        });

        $('#chat-text-menu').on('click', '[data-text]', function (event) {
            event.stopPropagation();

            var action = $(this).data('text');
            var selection = null;

            try {
                selection = window.getSelection().toString();
            } catch (error) {
                selection = '';
            }

            $('#chat-text-menu').prop('hidden', true);
            $('#chat-input').focus();

            if (action === 'paste') {
                if (navigator.clipboard && navigator.clipboard.readText) {
                    navigator.clipboard.readText().then(function (text) {
                        document.execCommand('insertText', false, text);
                    }).catch(function () {
                        alertDialog('No se pudo pegar. Usa Ctrl+V.');
                    });
                } else {
                    alertDialog('Usa Ctrl+V para pegar.');
                }

                return;
            }

            document.execCommand(action, false, null);
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-text-menu').length) {
                $('#chat-text-menu').prop('hidden', true);
            }
        });

        var isTouchDevice = ('ontouchstart' in window || navigator.maxTouchPoints > 0) &&
            window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

        $('#chat-input').on('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            if (isTouchDevice) {
                return;
            }

            if (event.ctrlKey || event.metaKey) {
                event.preventDefault();
                document.execCommand('insertHTML', false, '<br>');
                return;
            }

            event.preventDefault();
            $('#chat-form').trigger('submit');
        });

        function adjustForKeyboard() {
            if (!window.visualViewport) {
                return;
            }

            var viewport = window.visualViewport;
            var keyboard = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);

            document.documentElement.style.setProperty('--chat-keyboard', keyboard + 'px');
        }

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', adjustForKeyboard);
            window.visualViewport.addEventListener('scroll', adjustForKeyboard);
            adjustForKeyboard();
        }

        $('#chat-input').on('focus', function () {
            window.setTimeout(function () {
                $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
            }, 250);
        });

        var textCache = {};
        var openTexts = {};
        var textScroll = {};

        $(document).on('scroll', '.chat-file__content', function () {
            var url = $(this).prev('.chat-file__toggle').data('url');

            if (url) {
                textScroll[url] = this.scrollTop;
            }
        });

        $(document).on('click', '.chat-file__toggle', function () {
            var $button = $(this);
            var url = $button.data('url');
            var $pre = $button.next('.chat-file__content');

            if (!$pre.length) {
                return;
            }

            if (!$pre.prop('hidden')) {
                openTexts[url] = false;
                $pre.prop('hidden', true);
                $button.text('Ver texto');
                return;
            }

            openTexts[url] = true;

            if (textCache[url] !== undefined) {
                $pre.text(textCache[url]).prop('hidden', false);
                $button.text('Ocultar');
                return;
            }

            fetch(url)
                .then(function (response) { return response.text(); })
                .then(function (text) {
                    var content = text.length > 20000 ? text.slice(0, 20000) + '\n…' : text;
                    textCache[url] = content;

                    if (!openTexts[url]) {
                        return;
                    }

                    $pre.text(content).prop('hidden', false);
                    $button.text('Ocultar');
                })
                .catch(function () {
                    alertDialog('No se pudo abrir el texto. Descárgalo para verlo.');
                });
        });

        if (pendingOpenId) {
            $('#chat-panel').prop('hidden', false);
            $('#chat-toggle').attr('aria-expanded', 'true');
            window.clearInterval(pollTimer);
            pollTimer = window.setInterval(refresh, 6000);
        }

        refresh();
        setBadge(0);
    });
})(window.jQuery);
