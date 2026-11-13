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
        var contadorUrl = $widget.attr('data-url-contador');
        var abrirUrl = $widget.attr('data-url-abrir');
        var grupoUrl = $widget.attr('data-url-grupo');
        var secretUrl = $widget.attr('data-url-secret');
        var chatBase = $widget.attr('data-url-chat');
        var destinatariosUrl = $widget.attr('data-url-destinatarios');
        var preferenciasUrl = $widget.attr('data-url-preferencias');
        var soundEnabled = $widget.attr('data-sound') === '1';
        var inAppEnabled = $widget.attr('data-in-app') === '1';
        var isAdmin = $widget.attr('data-is-admin') === '1';
        var token = $widget.attr('data-csrf');
        var pendingOpenId = null;
        var pendingFromUrl = false;

        try {
            var requestedConversation = new window.URLSearchParams(window.location.search).get('conversacion');
            var requestedId = requestedConversation ? parseInt(requestedConversation, 10) : NaN;

            pendingOpenId = isNaN(requestedId) ? null : requestedId;
            pendingFromUrl = pendingOpenId !== null;
        } catch (error) {
            pendingOpenId = null;
            pendingFromUrl = false;
        }

        var currentId = null;
        var currentModeration = false;
        var pollTimer = null;
        var messageTimer = null;
        var counterTimer = null;
        var lastUnread = 0;
        var audioContext = null;
        var activeMessage = null;
        var activeConversation = null;
        var activeUser = null;
        var lastUsers = [];

        /** Mensaje citado por la respuesta que se está escribiendo. */
        var replyTarget = null;
        /** Mensaje que se está reenviando y destinos disponibles del modal. */
        var forwardSource = null;
        var forwardDestinations = null;

        /** Etiquetas de la conversación abierta: token -> {name, label, kind}. */
        var mentionIndex = {};
        var mentionMatches = [];
        var mentionActive = 0;
        var mentionRange = null;

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
            hideMentionSuggestions();
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
            var fromUrl = pendingFromUrl;

            for (var index = 0; index < items.length; index += 1) {
                if (items[index].id === pendingOpenId) {
                    found = items[index];
                    break;
                }
            }

            pendingOpenId = null;
            pendingFromUrl = false;

            if (!found) {
                return;
            }

            if (fromUrl && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', window.location.pathname);
            }

            openConversation(found.id, found.title, found.subtitle);
        }

        function setBadge(count) {
            var $badge = $('#chat-badge');

            if (!inAppEnabled || count <= 0) {
                $badge.prop('hidden', true).text('0');
            } else {
                $badge.prop('hidden', false).text(count > 99 ? '+99' : count);
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

        function request(method, url, data) {
            return $.ajax({
                url: url,
                method: method,
                dataType: 'json',
                data: $.extend({ _token: token }, data || {})
            });
        }

        function post(url, data) {
            return request('POST', url, data);
        }

        function remove(url, data) {
            return request('DELETE', url, data);
        }

        /**
         * Guarda el catálogo de etiquetas de la conversación abierta.
         */
        function setMentionCatalog(items) {
            mentionIndex = {};

            (items || []).forEach(function (item) {
                if (!item || !item.token) {
                    return;
                }

                mentionIndex[String(item.token).toLowerCase()] = item;
            });
        }

        /**
         * Resalta las @etiquetas con su color y su etiqueta visible.
         *
         * Azul para participantes, púrpura para el dueño del grupo y rojo para
         * el rol de administrador; @everyone, @here y @AmigoSecreto llevan su
         * propio color.
         */
        function highlightMentions(html) {
            return String(html || '').replace(
                /(^|[\s>])(@([\p{L}\p{N}_.-]+))/gu,
                function (match, prefix, token, name) {
                    var item = mentionIndex[name.toLowerCase()];
                    var kind = item && item.kind ? item.kind : 'member';
                    var label = item && item.label ? item.label : '';
                    var chip = '<span class="chat-mention chat-mention--' + kind + '">' + token;

                    if (label) {
                        chip += '<span class="chat-mention__role">' + escape(label) + '</span>';
                    }

                    return prefix + chip + '</span>';
                }
            );
        }

        /**
         * Convierte los enlaces del mensaje en hiperenlaces que abren en una
         * pestaña nueva.
         *
         * Los enlaces internos (por ejemplo, la invitación a un grupo) también
         * se abren así: la página de la invitación carga con la sesión del
         * usuario y desde ahí se puede aceptar. Solo se marcan los enlaces que
         * van sueltos en el texto, nunca los que ya están dentro de una
         * etiqueta.
         */
        function linkify(html) {
            return String(html || '').replace(
                /(^|[\s>])((?:https?:\/\/|www\.)[^\s<>"']+)/gi,
                function (match, prefix, url) {
                    var trailing = '';
                    var visible = url;

                    // La puntuación del final no forma parte del enlace.
                    while (/[.,;:!?]$/.test(visible)) {
                        trailing = visible.slice(-1) + trailing;
                        visible = visible.slice(0, -1);
                    }

                    // Un paréntesis de cierre sin abrir tampoco es del enlace.
                    while (visible.slice(-1) === ')'
                        && (visible.match(/\)/g) || []).length > (visible.match(/\(/g) || []).length) {
                        trailing = ')' + trailing;
                        visible = visible.slice(0, -1);
                    }

                    if (visible === '') {
                        return match;
                    }

                    var safe = visible.replace(/&/g, '&amp;');
                    var href = visible.indexOf('www.') === 0 ? 'http://' + visible : visible;

                    href = href.replace(/&/g, '&amp;');

                    return prefix + '<a class="chat-link" href="' + href + '"' +
                        ' target="_blank" rel="noopener noreferrer">' + safe + '</a>' + trailing;
                }
            );
        }

        function messageHtml(message) {
            var attachment = '';
            var body = message.deleted
                ? '<p class="chat-message__deleted">' + escape(message.deleted_label) + '</p>'
                : '<p class="chat-message__body">' + linkify(highlightMentions(message.body || '')) + '</p>';
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
                            ' data-message-id="' + message.id + '"' +
                            ' data-attachment-id="' + (item.id || '') + '"' +
                            ' data-can-delete="' + (message.can_delete ? '1' : '0') + '"' +
                            ' data-src="' + item.url + '" data-type="' + item.type + '" data-name="' + escape(item.name || '') + '">' +
                            (item.type === 'image'
                                ? '<img src="' + item.url + '" alt="Imagen adjunta">'
                                : '<video src="' + item.url + '"></video>') + more + '</button>';
                    }).join('') + '</div>';
            } else if (list.length && !message.deleted) {
                var item = list[0];

                if (item.type === 'pdf') {
                    attachment = '<div class="chat-attachment__pdf chat-media-open" data-lightbox="pdf" data-src="' + item.url +
                        '" data-message-id="' + message.id + '" data-attachment-id="' + (item.id || '') +
                        '" data-can-delete="' + (message.can_delete ? '1' : '0') +
                        '" data-name="' + escape(item.name) + '"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> ' +
                        escape(item.name) + '</div>';
                } else if (item.type === 'audio') {
                    attachment = '<div class="chat-audio">' +
                        '<canvas class="chat-wave chat-audio-open" width="220" height="40" data-url="' + item.url +
                        '" data-message-id="' + message.id + '" data-attachment-id="' + (item.id || '') +
                        '" data-can-delete="' + (message.can_delete ? '1' : '0') +
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

            // Cita del mensaje respondido: va dentro de la burbuja, arriba del
            // contenido, y al pulsarla se salta al mensaje original.
            var quote = '';

            if (message.reply) {
                quote = '<div class="chat-quote' + (message.reply.deleted ? ' chat-quote--deleted' : '') + '"' +
                    (message.reply.id ? ' data-reply-to="' + message.reply.id + '"' : '') +
                    ' role="button" tabindex="0" title="Ir al mensaje">' +
                    '<span class="chat-quote__sender">' + escape(message.reply.sender) + '</span>' +
                    '<span class="chat-quote__body">' + escape(message.reply.body) + '</span>' +
                    '</div>';
            }

            var forwarded = message.forwarded
                ? '<span class="chat-message__forwarded">Reenviado</span> '
                : '';

            return '<div class="chat-message' +
                (message.mine ? ' chat-message--mine' : '') +
                (message.is_moderation ? ' chat-message--moderation' : '') +
                (message.deleted ? ' chat-message--deleted' : '') + '"' +
                ' data-message-id="' + message.id + '"' +
                ' data-body="' + escape(message.body) + '"' +
                ' data-sender="' + escape(message.sender) + '"' +
                ' data-deleted="' + (message.deleted ? '1' : '0') + '"' +
                ' data-can-edit="' + (message.can_edit ? '1' : '0') + '"' +
                ' data-can-delete="' + (message.can_delete ? '1' : '0') + '"' +
                ' data-reported="' + (message.reported ? '1' : '0') + '">' +
                quote + attachment + body + linkCard +
                '<p class="chat-message__meta">' + escape(message.sender) + ' · ' + escape(message.created_at) + ' ' +
                forwarded +
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

        // Índice de lo ya pintado: permite añadir o actualizar solo las burbujas
        // que cambian, sin reconstruir la conversación (y sin cortar audios,
        // videos ni el texto que el usuario tenga abierto).
        var paintedMessages = {};
        var paintedLastId = 0;
        var paintedLastDate = null;
        var recentHash = null;
        var messageRequestPending = false;
        var messageFailures = 0;
        var summaryFailures = 0;

        function isPanelOpen() {
            return !$('#chat-panel').prop('hidden');
        }

        function canPoll() {
            return !document.hidden && navigator.onLine !== false;
        }

        function isNearBottom($box) {
            return ($box[0].scrollHeight - $box.scrollTop() - $box[0].clientHeight) < 80;
        }

        function forgetPaintedMessages() {
            paintedMessages = {};
            paintedLastId = 0;
            paintedLastDate = null;
            recentHash = null;
        }

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
            var quote = pending.reply
                ? '<div class="chat-quote">' +
                    '<span class="chat-quote__sender">' + escape(pending.reply.sender) + '</span>' +
                    '<span class="chat-quote__body">' + escape(pending.reply.body) + '</span></div>'
                : '';

            return '<div class="chat-message chat-message--mine chat-message--pending" data-pending-id="' + pending.id + '"' +
                ' data-body="' + escape(content) + '">' +
                quote +
                '<p class="chat-message__body">' + escape(content) + '</p>' +
                '<p class="chat-message__meta">Tú · ' + indicator + '</p></div>';
        }

        function paintPending() {
            var $box = $('#chat-messages');

            if (!$box.length) {
                return;
            }

            $box.children('.chat-message--pending').remove();

            currentPending().forEach(function (pending) {
                $box.append(pendingHtml(pending));
            });
        }

        // Deja las burbujas «enviando» en sintonía con la cola real: quita las
        // que ya se confirmaron (o se descartaron) y mantiene el resto al final.
        function syncPending() {
            var $box = $('#chat-messages');
            var pending = currentPending();
            var missing = {};

            pending.forEach(function (item) {
                missing[item.id] = item;
            });

            $box.children('.chat-message--pending').each(function () {
                var id = this.getAttribute('data-pending-id');

                if (!missing[id]) {
                    $(this).remove();
                    return;
                }

                delete missing[id];
            });

            pending.forEach(function (item) {
                if (missing[item.id]) {
                    $box.append(pendingHtml(item));
                }
            });

            $box.children('.chat-message--pending').appendTo($box);
        }

        function restoreOpenTexts($box) {
            $box.find('.chat-file__toggle').each(function () {
                var url = $(this).data('url');

                if (openTexts[url] && textCache[url] !== undefined) {
                    $(this).next('.chat-file__content')
                        .text(textCache[url])
                        .prop('hidden', false)
                        .scrollTop(textScroll[url] || 0);
                    $(this).text('Ocultar');
                }
            });
        }

        function rememberMessage(message) {
            paintedMessages[message.id] = JSON.stringify(message);
            paintedLastDate = message.created_date;

            if (message.id > paintedLastId) {
                paintedLastId = message.id;
            }
        }

        function appendMessage(message) {
            var $existing = $('#chat-messages .chat-message[data-message-id="' + message.id + '"]');

            // Nunca se duplica una burbuja que ya está en pantalla (por ejemplo
            // si el sondeo llegó antes que la respuesta del envío).
            if ($existing.length) {
                updateMessageNode(message);
                return;
            }

            if (paintedMessages[message.id]) {
                updateMessageNode(message);
                return;
            }

            var divider = '';

            if (message.created_date !== paintedLastDate) {
                divider = '<div class="chat-date">' + escape(dateLabel(message.created_date)) + '</div>';
            }

            $('#chat-messages').append(divider + messageHtml(message));
            rememberMessage(message);
        }

        function updateMessageNode(message) {
            var $node = $('#chat-messages .chat-message[data-message-id="' + message.id + '"]');
            var stored = paintedMessages[message.id];
            var current = JSON.stringify(message);

            if (!$node.length) {
                if (message.id > paintedLastId) {
                    appendMessage(message);
                } else {
                    rememberMessage(message);
                }

                return;
            }

            if (stored === current) {
                return;
            }

            var before = stored ? JSON.parse(stored) : null;

            if (before === null) {
                $node.replaceWith(messageHtml(message));
                rememberMessage(message);
                return;
            }

            // Lo que cambia el contenido repinta solo esa burbuja. La cita se
            // incluye: si borran o editan el mensaje citado, cambia con ella.
            if (before.body !== message.body ||
                before.deleted !== message.deleted ||
                before.edited !== message.edited ||
                before.deleted_label !== message.deleted_label ||
                before.forwarded !== message.forwarded ||
                JSON.stringify(before.reply) !== JSON.stringify(message.reply) ||
                JSON.stringify(before.attachments) !== JSON.stringify(message.attachments) ||
                before.link_url !== message.link_url) {
                $node.replaceWith(messageHtml(message));
                rememberMessage(message);
                return;
            }

            // Confirmaciones de lectura: se toca solo el icono.
            if (before.read !== message.read || before.delivered !== message.delivered) {
                var $meta = $node.children('.chat-message__meta');
                var check = '';

                $meta.find('.chat-check').remove();

                if (message.mine && !message.deleted) {
                    check = '<i class="fa-solid fa-check chat-check' + (message.read ? ' chat-check--read' : '') +
                        '" title="' + (message.read ? 'Leído' : (message.delivered ? 'Entregado' : 'Enviado')) +
                        '" aria-hidden="true"></i>';
                }

                $meta.append(check);
            }

            // Reacciones: se toca solo su contenedor.
            if (JSON.stringify(before.reactions) !== JSON.stringify(message.reactions)) {
                var reactions = (message.reactions || []).map(function (reaction) {
                    return '<button type="button" class="chat-reaction' + (reaction.mine ? ' is-mine' : '') +
                        '" data-reaction="' + escape(reaction.emoji) + '">' +
                        escape(reaction.emoji) + ' ' + reaction.count + '</button>';
                }).join('');
                var $reactions = $node.children('.chat-message__reactions');

                if (reactions) {
                    if ($reactions.length) {
                        $reactions.html(reactions);
                    } else {
                        $node.append('<div class="chat-message__reactions">' + reactions + '</div>');
                    }
                } else {
                    $reactions.remove();
                }
            }

            $node.attr('data-body', message.body)
                .attr('data-sender', message.sender)
                .attr('data-deleted', message.deleted ? '1' : '0')
                .attr('data-can-edit', message.can_edit ? '1' : '0')
                .attr('data-can-delete', message.can_delete ? '1' : '0')
                .attr('data-reported', message.reported ? '1' : '0')
                .removeData('body sender deleted can-edit can-delete reported');

            rememberMessage(message);
        }

        function renderMessages() {
            var html = '';
            var messages = lastData.messages || [];

            forgetPaintedMessages();

            messages.forEach(function (message, index) {
                if (message.created_date !== paintedLastDate) {
                    paintedLastDate = message.created_date;
                    html += '<div class="chat-date">' + escape(dateLabel(message.created_date)) + '</div>';
                }

                if (lastData.new_count > 0 && index === (messages.length - lastData.new_count)) {
                    html += '<div class="chat-new-divider">' + lastData.new_count + ' mensajes nuevos</div>';
                }

                html += messageHtml(message);
                rememberMessage(message);
            });

            var $messages = $('#chat-messages');
            var wasAtBottom = isNearBottom($messages);
            var previousScroll = $messages.scrollTop();

            $messages.html(html || '<p class="chat-hint">Aún no hay mensajes.</p>');

            paintPending();
            restoreOpenTexts($messages);

            if (wasAtBottom) {
                $messages.scrollTop($messages[0].scrollHeight);
            } else {
                $messages.scrollTop(previousScroll);
            }

            renderPinned();
            drawWaveforms();
        }

        /**
         * Cintillo con el mensaje fijado. Se desfija con el clic derecho.
         */
        function renderPinned() {
            var $pin = $('#chat-pin');

            if (!$pin.length) {
                return;
            }

            var pinned = lastData && lastData.conversation ? lastData.conversation.pinned_message : null;

            if (!pinned) {
                $pin.prop('hidden', true);

                return;
            }

            $('#chat-pin-body').text(
                (pinned.sender ? pinned.sender + ': ' : '') + (pinned.body || '')
            );

            $pin.prop('hidden', false);
        }

        // Aplica una respuesta incremental: añade lo nuevo y actualiza en su
        // sitio lo que cambió, sin tocar el resto de la conversación.
        function patchMessages(response) {
            var $box = $('#chat-messages');

            if (!$box.children('.chat-message').length) {
                lastData = response;
                renderMessages();
                return;
            }

            var newMessages = response.messages || [];
            var recent = response.recent || [];
            var atBottom = isNearBottom($box);
            var previousHeight = $box[0].scrollHeight;
            var previousTop = $box.scrollTop();

            newMessages.forEach(appendMessage);
            recent.forEach(updateMessageNode);

            paintedLastId = Math.max(paintedLastId, parseInt(response.latest_id, 10) || 0);
            recentHash = response.recent_hash || recentHash;

            // Las burbujas en envío siempre quedan al final y las confirmadas se
            // retiran en cuanto el servidor devuelve el mensaje real.
            syncPending();

            if (!newMessages.length && !recent.length) {
                return;
            }

            if (atBottom) {
                $box.scrollTop($box[0].scrollHeight);
            } else if (newMessages.length) {
                // Si el usuario está leyendo más arriba, no se le mueve el texto.
                $box.scrollTop(previousTop + ($box[0].scrollHeight - previousHeight));
            }

            drawWaveforms();
        }

        // Vuelve a cargar la conversación desde cero. Necesario tras «vaciar»:
        // con el sondeo incremental las burbujas viejas no se retiraban solas.
        function reloadConversationMessages() {
            if (!currentId) {
                return;
            }

            forgetPaintedMessages();
            lastData = { messages: [], new_count: 0 };
            galleryItems = [];
            $('#chat-messages').html('<p class="chat-hint">Cargando mensajes...</p>');
            loadMessages();
        }

        // Refresca solo las burbujas indicadas (tras editar, borrar o reaccionar)
        // sin pedir el historial ni repintar el resto de la conversación.
        function refreshMessages(ids) {
            var list = (ids || []).filter(function (id) {
                return parseInt(id, 10) > 0;
            });

            if (!list.length || !currentId || !isPanelOpen()) {
                return;
            }

            $.getJSON(chatBase + '/' + currentId + '/mensajes', { ids: list })
                .done(function (response) {
                    (response.recent || []).forEach(updateMessageNode);
                });
        }

        function messageDelay() {
            // Sondeo rápido en reposo y con espera creciente si el servidor falla.
            return Math.min(15000, 1600 * Math.pow(2, messageFailures));
        }

        function summaryDelay() {
            return Math.min(30000, 4500 * Math.pow(2, summaryFailures));
        }

        function loadMessages() {
            if (!currentId || !isPanelOpen() || messageRequestPending) {
                return;
            }

            var conversationId = currentId;
            var painted = paintedLastId > 0 && $('#chat-messages .chat-message').length > 0;
            var data = painted ? { after_id: paintedLastId } : {};

            messageRequestPending = true;

            $.getJSON(chatBase + '/' + conversationId + '/mensajes', data)
                .done(function (response) {
                    messageFailures = 0;

                    if (conversationId !== currentId) {
                        return;
                    }

                    if (!painted) {
                        lastData = response;
                        applyReadOnly(response.conversation);
                        setMentionCatalog(response.mentionables || []);
                        renderMessages();
                        return;
                    }

                    // Sin novedad ni cambios en la ventana reciente: no se toca el DOM.
                    if (!(response.messages || []).length &&
                        response.recent_hash &&
                        response.recent_hash === recentHash) {
                        paintedLastId = Math.max(paintedLastId, parseInt(response.latest_id, 10) || 0);
                        return;
                    }

                    patchMessages(response);
                })
                .fail(function () {
                    messageFailures += 1;

                    if (!painted) {
                        $('#chat-messages').html('<p class="chat-hint">No se pudieron cargar los mensajes.</p>');
                    }
                })
                .always(function () {
                    messageRequestPending = false;
                });
        }

        function startMessagePolling() {
            window.clearTimeout(messageTimer);
            messageTimer = null;

            if (!currentId || !isPanelOpen()) {
                return;
            }

            messageTimer = window.setTimeout(function poll() {
                if (!currentId || !isPanelOpen() || !canPoll()) {
                    return;
                }

                loadMessages();
                messageTimer = window.setTimeout(poll, messageDelay());
            }, messageDelay());
        }

        function startSummaryPolling() {
            window.clearTimeout(pollTimer);
            pollTimer = null;

            if (!isPanelOpen()) {
                return;
            }

            pollTimer = window.setTimeout(function poll() {
                if (!isPanelOpen() || !canPoll()) {
                    return;
                }

                refresh();
                pollTimer = window.setTimeout(poll, summaryDelay());
            }, summaryDelay());
        }

        function stopChatPolling() {
            window.clearTimeout(messageTimer);
            window.clearTimeout(pollTimer);
            messageTimer = null;
            pollTimer = null;
        }

        var lastSyncAt = 0;

        // Sincronización inmediata al volver a la pestaña, al recuperar la red o
        // al enfocar la ventana: el usuario no espera al siguiente sondeo.
        function syncNow() {
            if (!canPoll() || !isPanelOpen()) {
                return;
            }

            if (Date.now() - lastSyncAt < 800) {
                return;
            }

            lastSyncAt = Date.now();

            refresh();
            startSummaryPolling();

            if (currentId) {
                loadMessages();
                startMessagePolling();
            }
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

        // Chat del evento y demás canales de solo lectura: sin cuadro de
        // escritura, sin respuestas y sin reenvíos entrantes.
        function applyReadOnly(conversation) {
            var readOnly = !!(conversation && conversation.read_only);

            $('#chat-widget').toggleClass('is-read-only', readOnly);
            $('#chat-readonly').prop('hidden', !readOnly);

            if (readOnly) {
                clearReply();
            }
        }

        function isReadOnlyChat() {
            return $('#chat-widget').hasClass('is-read-only');
        }

        /* ------------------------------------------------------------------
           Responder: cita del mensaje sobre la barra de escritura
           ------------------------------------------------------------------ */

        function clearReply() {
            replyTarget = null;
            $('#chat-reply').prop('hidden', true);
            $('#chat-reply-sender').text('');
            $('#chat-reply-body').text('');
        }

        function showReply(message) {
            if (!message || !message.id) {
                return;
            }

            replyTarget = message;
            $('#chat-reply-sender').text(message.sender || '');
            $('#chat-reply-body').text(message.body || '');
            $('#chat-reply').prop('hidden', false);
            $('#chat-input').trigger('focus');
        }

        /**
         * Resumen legible de una burbuja para citarla en la respuesta o en el
         * modal de reenvío.
         */
        function messageSummary($message) {
            var text = $.trim($message.find('.chat-message__body').first().text());

            if (!text) {
                var $media = $message.find('.chat-media').first();

                if ($media.length) {
                    text = $media.data('type') === 'video' ? 'Video' : 'Foto';
                } else if ($message.find('.chat-attachment__audio').length) {
                    text = 'Audio';
                } else if ($message.find('.chat-attachment__pdf').length) {
                    text = 'PDF';
                } else if ($message.find('.chat-attachment__location').length) {
                    text = 'Ubicación';
                } else if ($message.find('.chat-attachment__contact').length) {
                    text = 'Contacto';
                } else if ($message.find('.chat-attachment__file').length) {
                    text = 'Archivo';
                } else {
                    text = 'Mensaje adjunto';
                }
            }

            return text;
        }

        function replyPayloadFor($message) {
            var id = parseInt($message.data('message-id'), 10);

            return {
                id: isNaN(id) ? null : id,
                sender: $message.attr('data-sender') || '',
                body: messageSummary($message)
            };
        }

        // Pulsar la cita lleva al mensaje original y lo destaca un instante.
        function goToMessage(id) {
            if (!id) {
                return;
            }

            var $target = $('#chat-messages .chat-message[data-message-id="' + id + '"]');

            if (!$target.length) {
                return;
            }

            if ($target[0].scrollIntoView) {
                $target[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
            }

            $target.addClass('is-highlight');
            window.setTimeout(function () {
                $target.removeClass('is-highlight');
            }, 1400);
        }

        function openConversation(id, title, subtitle, moderation) {
            currentId = id;
            currentModeration = moderation === true;
            closeChatProfile();
            // La respuesta en curso es de la conversación anterior: no viaja.
            applyReadOnly(null);
            forgetPaintedMessages();
            setMentionCatalog([]);
            hideMentionSuggestions();
            $('#chat-title').text(title || '');
            $('#chat-subtitle').text(subtitle || '');
            $('#chat-status').attr('class', 'chat-status chat-status--' + statusKind(subtitle || ''));
            $('#chat-body').hide();
            $('#chat-panel > .chat-tabs').hide();
            $('#chat-conversation').prop('hidden', false).removeClass('is-gallery');
            $('#chat-gallery-panel').prop('hidden', true);
            $('#chat-messages').html('<p class="chat-hint">Cargando mensajes...</p>');

            loadMessages();

            startMessagePolling();
        }

        // Firmas de lo último pintado en cada lista: si el servidor devuelve lo
        // mismo, no se vuelve a escribir nada en el DOM.
        var conversationRows = {};
        var usersSignature = null;
        var moderationSignature = null;
        var groupUsersSignature = null;

        function renderUsers(items) {
            var list = items || [];
            lastUsers = list;

            var signature = JSON.stringify(list);

            if (signature === usersSignature) {
                return;
            }

            usersSignature = signature;

            if (!list.length) {
                $('#chat-users').html('<p class="chat-hint">No hay otros usuarios disponibles.</p>');
                return;
            }

            $('#chat-users').html(list.map(function (user) {
                var states = [];

                if (user.blocked) {
                    states.push('Bloqueado');
                }

                if (user.blocked_by) {
                    states.push('Te bloqueó');
                }

                if (user.muted) {
                    states.push('Silenciado');
                }

                return '<button type="button" class="chat-item chat-user-open" data-id="' + user.id + '"' +
                    ' data-name="' + escape(user.name) + '"' +
                    ' data-muted="' + (user.muted ? '1' : '0') + '"' +
                    ' data-blocked="' + (user.blocked ? '1' : '0') + '"' +
                    ' data-blocked-by="' + (user.blocked_by ? '1' : '0') + '"' +
                    ' data-is-admin="' + (user.is_admin ? '1' : '0') + '">' +
                    '<span class="chat-item__title">' + escape(user.name) +
                    ' <span class="' + (user.online ? 'chat-online' : 'chat-offline') + '"' +
                    ' title="' + (user.online ? 'En línea' : 'Desconectado') + '"></span></span>' +
                    '<span class="chat-item__meta">' + escape(user.role) +
                    (user.online ? ' · En línea' : ' · Desconectado') +
                    (states.length ? ' · ' + escape(states.join(' · ')) : '') +
                    '</span>' +
                    '</button>';
            }).join(''));
        }

        function conversationItemHtml(item) {
            var label = item.subtitle || (item.is_group ? 'Grupo' : 'Conversación directa');
            var muted = item.muted ? '1' : '0';
            var blocked = item.blocked ? '1' : '0';
            var moderation = item.is_moderation ? '1' : '0';
            var flags = '';

            if (item.pinned) {
                flags += ' <i class="fa-solid fa-thumbtack chat-item__flag chat-item__flag--pin"' +
                    ' title="Conversación fijada" aria-label="Conversación fijada"></i>';
            }

            if (item.favorite) {
                flags += ' <i class="fa-solid fa-star chat-item__flag chat-item__flag--favorite"' +
                    ' title="Favorito" aria-label="Favorito"></i>';
            }

            var muteIcon = item.muted
                ? ' <i class="fa-solid fa-bell-slash chat-item__mute"' +
                    ' title="Conversación silenciada" aria-label="Conversación silenciada"></i>'
                : '';

            return '<button type="button" class="chat-item chat-conversation-open" data-id="' + item.id + '"' +
                ' data-title="' + escape(item.title) + '"' +
                ' data-subtitle="' + escape(label) + '"' +
                ' data-muted="' + muted + '"' +
                ' data-blocked="' + blocked + '"' +
                ' data-owner="' + (item.is_owner ? '1' : '0') + '"' +
                ' data-user-id="' + escape(item.other_user_id || '') + '"' +
                ' data-is-group="' + (item.is_group ? '1' : '0') + '"' +
                ' data-moderation="' + moderation + '"' +
                ' data-favorite="' + (item.favorite ? '1' : '0') + '"' +
                ' data-pinned="' + (item.pinned ? '1' : '0') + '"' +
                ' data-archived="' + (item.archived ? '1' : '0') + '"' +
                ' data-can-archive="' + (item.can_archive ? '1' : '0') + '"' +
                ' data-can-unarchive="' + (item.can_unarchive ? '1' : '0') + '"' +
                ' data-unread="' + (item.unread || 0) + '"' +
                ' data-has-messages="' + (item.last_message ? '1' : '0') + '">' +
                '<span class="chat-item__title">' + escape(item.title) + flags + muteIcon + '</span>' +
                '<span class="chat-item__foot">' +
                '<span class="chat-item__meta">' + escape(item.last_message || 'Sin mensajes') + '</span>' +
                (item.unread > 0
                    ? '<span class="chat-item__new">' +
                        (item.unread === 1 ? '1 mensaje nuevo' : item.unread + ' mensajes nuevos') +
                        '</span>'
                    : '') +
                '</span>' +
                '</button>';
        }

        // Lista de archivadas: se repinta completa porque cambia poco.
        function renderGroups(items) {
            var $list = $('#chat-groups');

            if (!$list.length) {
                return;
            }

            var groups = (items || []).filter(function (item) {
                return item.is_group;
            });

            if (!groups.length) {
                $list.html('<p class="chat-hint">Aún no perteneces a ningún grupo.</p>');

                return;
            }

            $list.html(groups.map(conversationItemHtml).join(''));
        }

        function renderArchived(items) {            var $list = $('#chat-archived');
            var list = items || [];

            if (!list.length) {
                $list.html('<p class="chat-hint">No tienes conversaciones archivadas.</p>');

                return;
            }

            $list.html(list.map(conversationItemHtml).join(''));
        }

        function renderArchivedCount(count) {
            var $count = $('#chat-archived-count');

            if (!$count.length) {
                return;
            }

            if (count > 0) {
                $count.prop('hidden', false).text(count > 99 ? '+99' : count);
            } else {
                $count.prop('hidden', true).text('0');
            }
        }

        // Parchea la lista de conversaciones: reutiliza las filas que no
        // cambiaron, reemplaza solo las que sí y las reordena sin recrearlas.
        function renderConversations(items) {
            var $list = $('#chat-conversations');
            var list = items || [];

            if (!list.length) {
                if (Object.keys(conversationRows).length || !$list.children('.chat-hint').length) {
                    conversationRows = {};
                    $list.html('<p class="chat-hint">Aún no tienes conversaciones.</p>');
                }

                return;
            }

            var rows = {};
            var wanted = [];

            list.forEach(function (item) {
                var key = String(item.id);
                var signature = JSON.stringify(item);
                var $node = $list.children('[data-id="' + key + '"]');

                rows[key] = signature;

                if ($node.length && conversationRows[key] === signature) {
                    wanted.push($node[0]);
                    return;
                }

                var $fresh = $(conversationItemHtml(item));

                // Se reemplaza solo la fila afectada, conservando su posición.
                if ($node.length) {
                    $node.replaceWith($fresh);
                }

                wanted.push($fresh[0]);
            });

            $list.children('[data-id]').each(function () {
                var key = String($(this).attr('data-id'));

                if (!rows.hasOwnProperty(key)) {
                    $(this).remove();
                }
            });

            // Solo se toca el DOM cuando el resultado difiere de lo que ya hay
            // (filas nuevas, cambiadas o en otro orden).
            var current = $list.children('[data-id]').toArray();
            var same = current.length === wanted.length && current.every(function (node, index) {
                return node === wanted[index];
            });

            if (!same) {
                wanted.forEach(function (node) {
                    $list.append(node);
                });
            }

            conversationRows = rows;
        }

        function renderModeration(items) {
            if (!isAdmin) {
                return;
            }

            var list = items || [];
            var signature = JSON.stringify(list);

            if (signature === moderationSignature) {
                return;
            }

            moderationSignature = signature;

            if (!list.length) {
                $('#chat-moderation').html('<p class="chat-hint">No hay conversaciones para moderar.</p>');
                return;
            }

            $('#chat-moderation').html(list.map(function (item) {
                var nuevos = item.unread > 0
                    ? ' · ' + (item.unread === 1 ? '1 nuevo' : item.unread + ' nuevos')
                    : '';

                return '<button type="button" class="chat-item chat-moderation-open" data-id="' + item.id + '"' +
                    ' data-title="' + escape(item.title) + '"' +
                    ' data-subtitle="' + escape(item.subtitle || 'Moderación') + '">' +
                    '<span class="chat-item__title">' + escape(item.title) + '</span>' +
                    '<span class="chat-item__meta">' + escape(item.is_group ? 'Grupo' : 'Conversación directa') + nuevos + '</span>' +
                    '</button>';
            }).join(''));
        }

        function renderGroupUsers(users) {
            var list = users || [];
            var signature = JSON.stringify(list.map(function (user) { return [user.id, user.name]; }));

            if (signature === groupUsersSignature) {
                return;
            }

            groupUsersSignature = signature;

            $('#chat-group-users').html(list.map(function (user) {
                return '<label class="chat-group__user">' +
                    '<input type="checkbox" class="form-check-input chat-group-user" value="' + user.id + '"> ' +
                    escape(user.name) + '</label>';
            }).join(''));
        }

        function refresh() {
            return $.getJSON(resumenUrl)
                .done(function (response) {
                    summaryFailures = 0;

                    renderConversations(response.conversations);
                    renderGroups(response.conversations);
                    renderArchived(response.archived);
                    renderArchivedCount((response.archived || []).length);
                    renderUsers(response.users);
                    renderModeration(response.moderation);

                    if (response.users) {
                        renderGroupUsers(response.users);
                    }

                    if (response.unread > lastUnread && lastUnread > 0) {
                        beep();
                    }

                    lastUnread = response.unread || 0;
                    setBadge(lastUnread);
                    renderModerationCount(response.moderation_unread || 0);
                    openPendingConversation(response);
                })
                .fail(function () {
                    summaryFailures += 1;
                    $('#chat-users').html('<p class="chat-hint">No se pudieron cargar los usuarios. Intenta de nuevo.</p>');
                });
        }

        function showList() {
            currentId = null;
            currentModeration = false;
            closeChatProfile();
            clearReply();
            applyReadOnly(null);
            $('#chat-conversation').prop('hidden', true).removeClass('is-gallery');
            $('#chat-gallery-panel').prop('hidden', true);
            $('#chat-panel > .chat-tabs').show();
            $('#chat-body').show();
            stopChatPolling();
            startSummaryPolling();
        }

        function hideContextMenu() {
            $('#chat-context-menu').prop('hidden', true).css({ top: '', left: '' });
            activeMessage = null;
            pendingActive = null;
        }

        function hideListMenu() {
            $('#chat-list-menu').prop('hidden', true).css({ top: '', left: '' });
            activeConversation = null;
        }

        function hideUserMenu() {
            $('#chat-user-menu').prop('hidden', true).css({ top: '', left: '' });
            activeUser = null;
        }

        /**
         * Coloca un menú contextual junto al puntero sin que se salga de la
         * pantalla. Si no cabe hacia abajo, se abre hacia arriba del clic.
         */
        function placeMenu($menu, event) {
            var margin = 8;
            var width = $menu.outerWidth();
            var height = $menu.outerHeight();
            var maxLeft = Math.max(margin, window.innerWidth - width - margin);
            var left = Math.max(margin, Math.min(event.clientX, maxLeft));
            var top = event.clientY;

            if (top + height + margin > window.innerHeight) {
                top = event.clientY - height;
            }

            top = Math.max(margin, Math.min(top, window.innerHeight - height - margin));

            $menu.css({
                top: top + 'px',
                left: left + 'px'
            });
        }

        /**
         * Menú de una persona de la pestaña Usuarios:
         * silenciar, bloquear/desbloquear y reportar.
         */
        function showUserMenu(event, $item) {
            event.preventDefault();
            event.stopPropagation();

            hideContextMenu();
            hideListMenu();

            activeUser = {
                id: parseInt($item.data('id'), 10) || null,
                name: $item.data('name') || '',
                muted: $item.data('muted') === 1,
                blocked: $item.data('blocked') === 1,
                blockedBy: $item.data('blocked-by') === 1,
                isAdmin: $item.data('is-admin') === 1
            };

            var $menu = $('#chat-user-menu');

            $menu.find('[data-user-action="mute"]').prop('hidden', activeUser.muted);
            $menu.find('[data-user-action="unmute"]').prop('hidden', !activeUser.muted);
            $menu.find('[data-user-action="block"]')
                .prop('hidden', activeUser.blocked || activeUser.isAdmin);
            $menu.find('[data-user-action="unblock"]').prop('hidden', !activeUser.blocked);

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
        }

        function showListMenu(event, $item) {
            event.preventDefault();
            event.stopPropagation();

            hideContextMenu();

            activeConversation = {
                id: parseInt($item.data('id'), 10) || null,
                title: $item.data('title') || '',
                muted: $item.data('muted') === 1,
                blocked: $item.data('blocked') === 1,
                userId: parseInt($item.data('user-id'), 10) || null,
                isGroup: $item.data('is-group') === 1,
                isOwner: $item.data('owner') === 1,
                favorite: $item.data('favorite') === 1,
                pinned: $item.data('pinned') === 1,
                archived: $item.data('archived') === 1,
                canArchive: $item.data('can-archive') === 1,
                canUnarchive: $item.data('can-unarchive') === 1,
                unread: parseInt($item.data('unread'), 10) || 0,
                hasMessages: $item.data('has-messages') === 1
            };

            var $menu = $('#chat-list-menu');

            var isModeration = $item.hasClass('chat-moderation-open');
            var isAdmin = $widget.attr('data-is-admin') === '1';
            var canManage = activeConversation.isGroup && (activeConversation.isOwner || isAdmin);
            var mine = !isModeration;

            $menu.find('[data-list-action="mute"]').prop('hidden', !mine || activeConversation.muted);
            $menu.find('[data-list-action="unmute"]').prop('hidden', !mine || !activeConversation.muted);

            var canBlock = !activeConversation.isGroup && activeConversation.userId !== null;

            $menu.find('[data-list-action="block"]').prop('hidden', !mine || !canBlock || activeConversation.blocked);
            $menu.find('[data-list-action="unblock"]').prop('hidden', !mine || !canBlock || !activeConversation.blocked);

            $menu.find('[data-list-action="favorite"]').prop('hidden', !mine || activeConversation.favorite);
            $menu.find('[data-list-action="unfavorite"]').prop('hidden', !mine || !activeConversation.favorite);
            $menu.find('[data-list-action="pin"]').prop('hidden', !mine || activeConversation.pinned);
            $menu.find('[data-list-action="unpin"]').prop('hidden', !mine || !activeConversation.pinned);
            $menu.find('[data-list-action="read"]').prop('hidden', !mine || activeConversation.unread === 0);
            $menu.find('[data-list-action="unread"]')
                .prop('hidden', !mine || !activeConversation.hasMessages);
            $menu.find('[data-list-action="archive"]')
                .prop('hidden', !mine || activeConversation.archived || !activeConversation.canArchive);
            $menu.find('[data-list-action="unarchive"]')
                .prop('hidden', !mine || !activeConversation.archived || !activeConversation.canUnarchive);
            $menu.find('[data-list-action="vaciar"]').prop('hidden', !mine);
            $menu.find('[data-list-action="clear"]').prop('hidden', !mine);

            $menu.find('[data-list-action="leave"]').prop('hidden', !activeConversation.isGroup);
            $menu.find('[data-list-action="report"]').prop('hidden', isModeration);
            $menu.find('[data-list-action="delete"]').prop('hidden', !isModeration);
            $menu.find('[data-list-action="invite"]').prop('hidden', !canManage);
            $menu.find('[data-list-action="members"]').prop('hidden', !canManage);
            $menu.find('[data-list-action="banned"]').prop('hidden', !canManage);

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
        }

        function showContextMenu(event, $message) {
            event.preventDefault();
            event.stopPropagation();

            hideListMenu();

            activeMessage = $message;

            var $menu = $('#chat-context-menu');
            var deleted = $message.attr('data-deleted') === '1';
            var readOnly = isReadOnlyChat();
            var pendingId = $message.data('pending-id');

            // Responder: en cualquier chat con escritura, nunca en los de solo
            // lectura ni sobre un mensaje en envío o eliminado.
            $menu.find('[data-action="reply"]').prop('hidden', readOnly || !!pendingId || deleted);

            // Reenviar: también desde los chats de solo lectura (lo que se lee
            // se puede reenviar), pero no desde un mensaje eliminado.
            $menu.find('[data-action="forward"]').prop('hidden', !!pendingId || deleted);

            $menu.find('[data-action="edit"]').prop('hidden', $message.data('can-edit') !== 1);
            $menu.find('[data-action="delete"]').prop('hidden', $message.data('can-delete') !== 1);
            $menu.find('[data-action="report"]').prop('hidden',
                $message.data('can-edit') === 1 || $message.data('reported') === 1);

            pendingActive = pendingId
                ? currentPending().find(function (item) { return item.id === pendingId; })
                : null;

            $menu.find('[data-action="resend"]').prop('hidden', !pendingActive || pendingActive.status !== 'failed');

            if (pendingId) {
                $menu.find('[data-action="reaction"], [data-action="edit"], [data-action="delete"], [data-action="report"]')
                    .prop('hidden', true);
            }

            // Fijar/desfijar el mensaje: solo para participantes, nunca moderando.
            var pinned = lastData && lastData.conversation ? lastData.conversation.pinned_message : null;
            var pinnedId = pinned ? parseInt(pinned.id, 10) : null;
            var messageId = parseInt($message.data('message-id'), 10) || null;
            var canPin = !currentModeration
                && !pendingId
                && messageId !== null
                && !deleted;

            $menu.find('[data-action="pin"]').prop('hidden', !canPin || messageId === pinnedId);
            $menu.find('[data-action="unpin"]').prop('hidden', !canPin || messageId !== pinnedId);

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
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
                startSummaryPolling();

                if (currentId) {
                    startMessagePolling();
                }
            } else {
                stopChatPolling();
                closeChatProfile();
            }
        });

        $('#chat-close').on('click', function () {
            $('#chat-panel').prop('hidden', true);
            $('#chat-toggle').attr('aria-expanded', 'false');
            stopChatPolling();
            closeChatProfile();
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
            var target = event.target;

            if ($panel.prop('hidden')) {
                return;
            }

            // Un clic que repinta el interior del chat (carrusel, miniaturas, mensajes)
            // deja el nodo pulsado fuera del DOM. Ese clic no es «fuera del widget».
            if (!target || (target.nodeType === 1 && !document.documentElement.contains(target))) {
                return;
            }

            // Los modales del chat (reenviar, crear grupo, avisos) viven fuera
            // del widget para quedar por encima: usarlos no cierra el panel.
            if ($(target).closest('#chat-widget, .modal, .modal-backdrop, .bootbox').length) {
                return;
            }

            $panel.prop('hidden', true);
            $('#chat-toggle').attr('aria-expanded', 'false');
            stopChatPolling();
        });

        $('.chat-tab').on('click', function () {
            var tab = $(this).data('tab');

            $('.chat-tab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.chat-tab-panel').prop('hidden', true);
            $('.chat-tab-panel[data-panel="' + tab + '"]').prop('hidden', false);

            if (tab === 'users' || tab === 'groups') {
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
                    var label = item.label || (item.role === 'giver' ? 'Tu asignado' : 'Incógnito');

                    return '<button type="button" class="chat-item chat-secret-open" data-id="' + item.conversation_id + '"' +
                        ' data-title="' + escape(item.title) + '"' +
                        ' data-label="' + escape(label) + '"' +
                        ' data-online="' + (item.online ? '1' : '0') + '">' +
                        '<span class="chat-item__title">' + escape(item.title) +
                        ' <span class="' + (item.online ? 'chat-online' : 'chat-offline') + '"></span></span>' +
                        '<span class="chat-item__meta">' + escape(label) + ' · ' +
                        (item.online ? 'En línea' : 'Desconectado') + '</span></button>';
                }).join('') : '<p class="chat-hint">Aún no tienes amigo secreto asignado.</p>');
            }).fail(function () {
                $('#chat-secret').html('<p class="chat-hint">No se pudo cargar el amigo secreto.</p>');
            });
        }

        $widget.on('click', '.chat-secret-open', function () {
            var online = $(this).data('online') === 1;

            openConversation(
                $(this).data('id'),
                $(this).data('title') || 'Amigo secreto',
                ($(this).data('label') || 'Amigo secreto') + ' · ' + (online ? 'En línea' : 'Desconectado')
            );
        });

        $widget.on('click', '.chat-conversation-open, .chat-moderation-open', function () {
            openConversation(
                $(this).data('id'),
                $(this).data('title'),
                $(this).data('subtitle'),
                $(this).hasClass('chat-moderation-open')
            );
        });

        $widget.on('click', '.chat-user-open', function () {
            post(abrirUrl, { user_id: $(this).data('id') })
                .done(function (response) {
                    openConversation(response.conversation.id, response.conversation.title, 'Conversación directa');
                })
                .fail(function (xhr) {
                    alertDialog((xhr.responseJSON || {}).message || 'No se pudo abrir el chat.');
                });
        });

        $widget.on('contextmenu', '.chat-user-open', function (event) {
            showUserMenu(event, $(this));
        });

        $('#chat-back').on('click', function () {
            showList();
        });

        $('#chat-messages').on('contextmenu', '.chat-message', function (event) {
            showContextMenu(event, $(this));
        });

        $widget.on('contextmenu', '.chat-conversation-open', function (event) {
            showListMenu(event, $(this));
        });

        // El cintillo del mensaje fijado se desfija con el clic derecho.
        $('#chat-pin').on('contextmenu', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (currentModeration) {
                return;
            }

            hideContextMenu();
            hideListMenu();

            var $menu = $('#chat-pin-menu');

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
        });

        $('#chat-pin-menu').on('click', '[data-pin-action="unpin"]', function () {
            $('#chat-pin-menu').prop('hidden', true);

            if (!currentId || currentModeration) {
                return;
            }

            remove(chatBase + '/' + currentId + '/mensaje-fijado', {})
                .done(function () {
                    if (lastData && lastData.conversation) {
                        lastData.conversation.pinned_message = null;
                    }

                    renderPinned();
                })
                .fail(function (xhr) {
                    alertDialog((xhr.responseJSON || {}).message || 'No se pudo desfijar el mensaje.');
                });
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-context-menu').length) {
                hideContextMenu();
            }

            if (!$(event.target).closest('#chat-list-menu').length) {
                hideListMenu();
            }

            if (!$(event.target).closest('#chat-pin-menu').length) {
                $('#chat-pin-menu').prop('hidden', true);
            }

            if (!$(event.target).closest('#chat-user-menu').length) {
                hideUserMenu();
            }
        });

        $('#chat-list-menu').on('click', '.chat-context-item', function () {
            var action = $(this).data('list-action');
            var target = activeConversation;

            hideListMenu();

            if (!target || !target.id) {
                return;
            }

            if (action === 'mute' || action === 'unmute') {
                post(chatBase + '/' + target.id + '/silenciar', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo cambiar el silencio.');
                    });

                return;
            }

            if (action === 'favorite' || action === 'unfavorite') {
                post(chatBase + '/' + target.id + '/favorito', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo cambiar el favorito.');
                    });

                return;
            }

            if (action === 'pin' || action === 'unpin') {
                post(chatBase + '/' + target.id + '/fijar', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo fijar la conversación.');
                    });

                return;
            }

            if (action === 'read') {
                post(chatBase + '/' + target.id + '/leer', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo marcar como leída.');
                    });

                return;
            }

            if (action === 'unread') {
                post(chatBase + '/' + target.id + '/no-leido', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo marcar como no leída.');
                    });

                return;
            }

            if (action === 'archive') {
                confirmDialog(
                    '¿Archivar esta conversación? Mientras esté archivada no aparece en la moderación.',
                    function () {
                        post(chatBase + '/' + target.id + '/archivar', {})
                            .done(function () {
                                if (currentId === target.id) {
                                    showList();
                                }

                                refresh();
                            })
                            .fail(function (xhr) {
                                alertDialog((xhr.responseJSON || {}).message || 'No se pudo archivar.');
                            });
                    }
                );

                return;
            }

            if (action === 'unarchive') {
                remove(chatBase + '/' + target.id + '/archivar', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo deshacer el archivo.');
                    });

                return;
            }

            if (action === 'vaciar') {
                confirmDialog(
                    '¿Vaciar este chat? Se borran los mensajes, enlaces y adjuntos de tu lado.',
                    function () {
                        post(chatBase + '/' + target.id + '/vaciar', {})
                            .done(function () {
                                if (currentId === target.id) {
                                    reloadConversationMessages();
                                }

                                refresh();
                            })
                            .fail(function (xhr) {
                                alertDialog((xhr.responseJSON || {}).message || 'No se pudo vaciar el chat.');
                            });
                    }
                );

                return;
            }

            if (action === 'clear') {
                confirmDialog(
                    '¿Eliminar esta conversación de tu lista? Si vuelves a abrirla, empieza vacía.',
                    function () {
                        post(chatBase + '/' + target.id + '/eliminar', {})
                            .done(function () {
                                if (currentId === target.id) {
                                    showList();
                                }

                                refresh();
                            })
                            .fail(function (xhr) {
                                alertDialog((xhr.responseJSON || {}).message || 'No se pudo eliminar la conversación.');
                            });
                    }
                );

                return;
            }

            if (action === 'leave') {
                confirmDialog('¿Salir de «' + escape(target.title) + '»?', function () {
                    post(chatBase + '/' + target.id + '/salir', {})
                        .done(function () {
                            if (currentId === target.id) {
                                showList();
                            }

                            refresh();
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo salir del grupo.');
                        });
                });

                return;
            }

            if (action === 'report') {
                confirmDialog('¿Reportar esta conversación a la administración?', function () {
                    post(chatBase + '/' + target.id + '/reportar-conversacion', {})
                        .done(function () {
                            alertDialog('Reporte enviado a la administración.');
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo reportar.');
                        });
                });

                return;
            }

            if (action === 'delete') {
                confirmDialog('¿Eliminar esta conversación para todos? No se puede deshacer.', function () {
                    remove(chatBase + '/' + target.id, {})
                        .done(function () {
                            if (currentId === target.id) {
                                showList();
                            }

                            refresh();
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo eliminar.');
                        });
                });

                return;
            }

            if (action === 'invite') {
                post(chatBase + '/grupo/' + target.id + '/invitar', {})
                    .done(function (response) {
                        var url = response && response.url ? response.url : '';

                        if (navigator.clipboard && url) {
                            navigator.clipboard.writeText(url);
                        }

                        alertDialog('Enlace de invitación copiado:\n' + url);
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo crear la invitación.');
                    });

                return;
            }

            if (action === 'members') {
                manageMembers(target.id);
                return;
            }

            if (action === 'banned') {
                $.getJSON(chatBase + '/grupo/' + target.id + '/baneados')
                    .done(function (response) {
                        var items = (response && response.items) || [];

                        alertDialog(items.length
                            ? items.map(function (item) {
                                return '• ' + item.name + (item.reason ? ' — ' + item.reason : '');
                            }).join('\n')
                            : 'No hay usuarios vetados en este grupo.');
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo cargar la lista de vetados.');
                    });

                return;
            }

            if (target.userId === null) {
                return;
            }

            if (action === 'block') {
                confirmDialog(
                    '¿Bloquear a «' + escape(target.title) + '»? No podrán enviarse mensajes entre ustedes.',
                    function () {
                        post(chatBase + '/usuarios/' + target.userId + '/bloquear', {})
                            .done(refresh)
                            .fail(function (xhr) {
                                alertDialog((xhr.responseJSON || {}).message || 'No se pudo bloquear al usuario.');
                            });
                    }
                );

                return;
            }

            if (action === 'unblock') {
                remove(chatBase + '/usuarios/' + target.userId + '/bloquear', {})
                    .done(refresh)
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo desbloquear al usuario.');
                    });
            }
        });

        // Acciones sobre una persona de la pestaña Usuarios.
        $('#chat-user-menu').on('click', '.chat-context-item', function () {
            var action = $(this).data('user-action');
            var target = activeUser;

            hideUserMenu();

            if (!target || !target.id) {
                return;
            }

            if (action === 'mute' || action === 'unmute') {
                post(chatBase + '/usuarios/' + target.id + '/silenciar', {})
                    .done(function () {
                        refresh();
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo cambiar el silencio.');
                    });

                return;
            }

            if (action === 'block') {
                confirmDialog(
                    '¿Bloquear a «' + escape(target.name) + '»? Mientras esté bloqueado no podrás abrir el chat.',
                    function () {
                        post(chatBase + '/usuarios/' + target.id + '/bloquear', {})
                            .done(function () {
                                if (currentId) {
                                    loadMessages();
                                }

                                refresh();
                            })
                            .fail(function (xhr) {
                                alertDialog((xhr.responseJSON || {}).message || 'No se pudo bloquear.');
                            });
                    }
                );

                return;
            }

            if (action === 'unblock') {
                remove(chatBase + '/usuarios/' + target.id + '/bloquear', {})
                    .done(function () {
                        refresh();
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo desbloquear.');
                    });

                return;
            }

            if (action === 'report') {
                promptDialog('Reportar usuario', 'Escribe el motivo del reporte:', function (value) {
                    post(chatBase + '/usuarios/' + target.id + '/reportar', { reason: value })
                        .done(function () {
                            alertDialog('Reporte enviado a la administración.');
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo enviar el reporte.');
                        });
                });
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

            if (action === 'reply') {
                showReply(replyPayloadFor($message));
                return;
            }

            if (action === 'forward') {
                openForwardModal($message);
                return;
            }

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

            if (action === 'pin' || action === 'unpin') {
                if (!currentId || id === null || id === undefined) {
                    return;
                }

                var pinRequest = action === 'pin'
                    ? post(chatBase + '/' + currentId + '/fijar-mensaje/' + id, {})
                    : remove(chatBase + '/' + currentId + '/mensaje-fijado', {});

                pinRequest
                    .done(function (response) {
                        if (lastData && lastData.conversation) {
                            lastData.conversation.pinned_message = response.pinned_message || null;
                        }

                        renderPinned();
                    })
                    .fail(function (xhr) {
                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo cambiar el mensaje fijado.');
                    });

                return;
            }

            if (action === 'edit') {
                promptDialog('Editar mensaje', 'Escribe el nuevo texto:', function (value) {
                    if (!value) {
                        return;
                    }

                    post(chatBase + '/mensajes/' + id + '/editar', { body: value })
                        .done(function () {
                            refreshMessages([id]);
                            refresh();
                        })
                        .fail(function (xhr) {
                            alertDialog((xhr.responseJSON || {}).message || 'No se pudo editar.');
                        });
                });
                return;
            }

            if (action === 'delete') {
                confirmDialog('¿Desea eliminar el mensaje?', function () {
                    post(chatBase + '/mensajes/' + id + '/eliminar', {})
                        .done(function () {
                            refreshMessages([id]);
                            refresh();
                        })
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

        // Cancelar la respuesta: se cierra la cita y no se envía nada.
        $('#chat-reply-cancel').on('click', function () {
            clearReply();
            $('#chat-input').trigger('focus');
        });

        // Pulsar la cita de una respuesta o de un reenvío lleva al original.
        $(document).on('click', '.chat-quote[data-reply-to]', function (event) {
            event.stopPropagation();
            goToMessage(parseInt($(this).attr('data-reply-to'), 10));
        });

        $(document).on('keydown', '.chat-quote[data-reply-to]', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                goToMessage(parseInt($(this).attr('data-reply-to'), 10));
            }
        });

        $(document).on('click', '.chat-reaction', function (event) {
            event.stopPropagation();

            var $button = $(this);
            var $message = $button.closest('.chat-message');

            post(chatBase + '/mensajes/' + $message.data('message-id') + '/reaccionar', {
                emoji: $button.data('reaction')
            }).done(function () {
                refreshMessages([$message.data('message-id')]);
            }).fail(function (xhr) {
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
                        refreshMessages([mode.messageId]);
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

        // Pegar (Ctrl+V) archivos o capturas en la barra del mensaje: se
        // adjuntan a la bandeja con la misma vista previa que al elegirlos o
        // arrastrarlos, y admite varios de una sola vez.
        $('#chat-conversation').on('paste', function (event) {
            if (!currentId) {
                return;
            }

            var $target = $(event.target);

            // Los demás campos del panel conservan el pegado normal.
            if ($target.is('input, textarea') && !$target.is('#chat-input')) {
                return;
            }

            var files = normalizePastedFiles(clipboardFiles(event));

            if (!files.length) {
                return;
            }

            // Evita que el navegador inserte la imagen dentro del editor.
            event.preventDefault();
            addTrayFiles(files);
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

        /* ------------------------------------------------------------------
           Crear grupo en un modal (integrantes y enlace de invitación)
           ------------------------------------------------------------------ */

        var groupModalElement = document.getElementById('modal-chat-group');
        var groupModal = groupModalElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(groupModalElement)
            : null;
        var groupInviteUrl = null;

        function resetGroupModal() {
            groupInviteUrl = null;
            $('#chat-group-name').val('').prop('hidden', false);
            $('#chat-group-users').prop('hidden', false);
            $('#chat-group-error').text('');
            $('#chat-group-invite').prop('hidden', true);
            $('#chat-group-invite-url').val('');
            $('#chat-group-create').prop('hidden', false).prop('disabled', false);

            renderGroupUsers(lastUsers);
        }

        $('#chat-group-open').on('click', function () {
            resetGroupModal();

            // Se refresca la lista de personas para el modal; si ya estaba
            // cargada se usa la copia en memoria.
            refresh().done(function (response) {
                if (response && response.users) {
                    renderGroupUsers(response.users);
                }
            });

            if (groupModal) {
                groupModal.show();
            }
        });

        $('#chat-group-create').on('click', function () {
            var name = $.trim($('#chat-group-name').val());
            var ids = $('.chat-group-user:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();

            if (!name) {
                $('#chat-group-error').text('Escribe el nombre del grupo.');
                return;
            }

            var $button = $(this).prop('disabled', true);
            $('#chat-group-error').text('');

            post(grupoUrl, { name: name, user_ids: ids })
                .done(function (response) {
                    var conversation = response.conversation || {};

                    refresh();

                    // El enlace se crea al vuelo y se muestra para copiarlo.
                    post(chatBase + '/grupo/' + conversation.id + '/invitar', {})
                        .done(function (invite) {
                            groupInviteUrl = (invite && invite.url) ? invite.url : '';

                            $('#chat-group-invite-url').val(groupInviteUrl);
                            $('#chat-group-invite').prop('hidden', groupInviteUrl === '');
                            $('#chat-group-create').prop('hidden', true);

                            if (groupInviteUrl === '') {
                                $('#chat-group-error').text('El grupo se creó, pero no se pudo obtener el enlace.');
                            }
                        })
                        .fail(function (xhr) {
                            $('#chat-group-error').text(
                                (xhr.responseJSON || {}).message
                                || 'El grupo se creó, pero no se pudo obtener el enlace de invitación.'
                            );
                            $('#chat-group-create').prop('hidden', true);
                        });
                })
                .fail(function (xhr) {
                    $('#chat-group-error').text((xhr.responseJSON || {}).message || 'No se pudo crear el grupo.');
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        $('#chat-group-invite-copy').on('click', function () {
            if (!groupInviteUrl) {
                return;
            }

            copyLightboxText(groupInviteUrl, 'Enlace de invitación copiado.');
        });

        /* ------------------------------------------------------------------
           Reenviar: modal para elegir uno o varios destinatarios
           ------------------------------------------------------------------ */

        var forwardModalElement = document.getElementById('modal-chat-forward');
        var forwardModal = forwardModalElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(forwardModalElement)
            : null;

        var FORWARD_SECTIONS = [
            { key: 'groups', title: 'Grupos' },
            { key: 'admins', title: 'Administradores' },
            { key: 'secrets', title: 'Amigos secretos' },
            { key: 'users', title: 'Personas' }
        ];

        // Número de la última carga de destinos: si se abre el modal dos veces
        // seguidas, la respuesta vieja no pinta sobre la nueva.
        var forwardRequest = 0;

        function forwardItemHtml(item) {
            return '<label class="chat-forward__item">' +
                '<input type="checkbox" class="chat-forward-target"' +
                ' data-target="' + escape(item.target) + '"' +
                ' data-id="' + (parseInt(item.id, 10) || 0) + '">' +
                '<span class="chat-forward__text">' +
                '<span class="chat-forward__name">' + escape(item.label) + '</span>' +
                '<span class="chat-forward__meta">' + escape(item.meta || '') + '</span>' +
                '</span></label>';
        }

        function renderForwardDestinations() {
            var data = forwardDestinations || {};
            var html = '';

            FORWARD_SECTIONS.forEach(function (section) {
                var items = data[section.key] || [];

                if (!items.length) {
                    return;
                }

                html += '<p class="chat-forward__group">' + escape(section.title) + '</p>' +
                    items.map(forwardItemHtml).join('');
            });

            $('#chat-forward-list').html(
                html || '<p class="chat-hint">No hay destinatarios disponibles.</p>'
            );

            filterForwardDestinations($('#chat-forward-search').val());
            updateForwardCount();
        }

        // Filtra sin volver a pintar: las casillas ya marcadas se conservan.
        function filterForwardDestinations(term) {
            var needle = $.trim((term || '').toLowerCase());
            var $items = $('#chat-forward-list .chat-forward__item');

            $items.each(function () {
                var haystack = $(this).text().toLowerCase();
                $(this).toggleClass('is-hidden', needle !== '' && haystack.indexOf(needle) === -1);
            });

            $('#chat-forward-list .chat-forward__group').each(function () {
                var visible = $(this)
                    .nextUntil('.chat-forward__group')
                    .filter('.chat-forward__item')
                    .not('.is-hidden')
                    .length;

                $(this).toggleClass('is-hidden', visible === 0);
            });
        }

        function forwardSelection() {
            var conversationIds = [];
            var userIds = [];

            $('#chat-forward-list .chat-forward-target:checked').each(function () {
                var id = parseInt($(this).attr('data-id'), 10);

                if (!id) {
                    return;
                }

                if ($(this).attr('data-target') === 'conversation') {
                    conversationIds.push(id);
                } else {
                    userIds.push(id);
                }
            });

            return { conversationIds: conversationIds, userIds: userIds };
        }

        function updateForwardCount() {
            var selection = forwardSelection();
            var count = selection.conversationIds.length + selection.userIds.length;
            var $send = $('#chat-forward-send');

            $send.prop('disabled', count === 0)
                .text(count > 1 ? 'Reenviar a ' + count : 'Reenviar');
        }

        function openForwardModal($message) {
            var id = parseInt($message.data('message-id'), 10);

            if (!id) {
                return;
            }

            forwardSource = { id: id, text: messageSummary($message) };
            forwardDestinations = null;

            $('#chat-forward-preview').text(forwardSource.text);
            $('#chat-forward-search').val('');
            $('#chat-forward-error').text('');
            $('#chat-forward-list').html('<p class="chat-hint">Cargando destinatarios...</p>');
            updateForwardCount();

            if (forwardModal) {
                forwardModal.show();
            }

            var requestId = ++forwardRequest;

            $.getJSON(destinatariosUrl)
                .done(function (response) {
                    if (requestId !== forwardRequest) {
                        return;
                    }

                    forwardDestinations = response;
                    renderForwardDestinations();
                })
                .fail(function (xhr) {
                    if (requestId !== forwardRequest) {
                        return;
                    }

                    $('#chat-forward-list').html('<p class="chat-hint">No se pudieron cargar los destinatarios.</p>');
                    $('#chat-forward-error').text(
                        (xhr.responseJSON || {}).message || 'No se pudieron cargar los destinatarios.'
                    );
                });
        }

        $('#chat-forward-search').on('input', function () {
            filterForwardDestinations($(this).val());
        });

        $('#chat-forward-list').on('change', '.chat-forward-target', updateForwardCount);

        $('#chat-forward-send').on('click', function () {
            if (!forwardSource) {
                return;
            }

            var selection = forwardSelection();

            if (!selection.conversationIds.length && !selection.userIds.length) {
                $('#chat-forward-error').text('Elige al menos un destinatario.');
                return;
            }

            var $send = $(this).prop('disabled', true);
            $('#chat-forward-error').text('');

            post(chatBase + '/mensajes/' + forwardSource.id + '/reenviar', {
                conversation_ids: selection.conversationIds,
                user_ids: selection.userIds
            }).done(function (response) {
                if (forwardModal) {
                    forwardModal.hide();
                }

                var count = parseInt(response && response.count, 10) || 1;

                // Si el chat abierto está entre los destinos, se pinta lo nuevo.
                if (currentId && selection.conversationIds.indexOf(parseInt(currentId, 10)) !== -1) {
                    loadMessages();
                }

                refresh();
                alertDialog(count > 1 ? 'Mensaje reenviado a ' + count + ' destinos.' : 'Mensaje reenviado.');
            }).fail(function (xhr) {
                $('#chat-forward-error').text(
                    (xhr.responseJSON || {}).message || 'No se pudo reenviar el mensaje.'
                );
            }).always(function () {
                $send.prop('disabled', false);
                updateForwardCount();
            });
        });

        /* ------------------------------------------------------------------
           Invitación a un grupo: modal al entrar al sistema
           ------------------------------------------------------------------ */

        var invitationToken = $widget.attr('data-invitation') || '';
        var invitationNotice = $widget.attr('data-invitation-notice') || '';
        var inviteModalElement = document.getElementById('modal-chat-invite');
        var inviteModal = inviteModalElement && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(inviteModalElement)
            : null;
        var invitationGroup = 'Grupo';

        function showInvitationNotice(message) {
            invitationToken = '';

            $('#chat-invite-text').text(message || 'Este enlace ya no está disponible.');
            $('#chat-invite-group').text('');
            $('#chat-invite-error').text('');
            $('#chat-invite-accept').prop('hidden', true);
            $('#chat-invite-reject').text('Cerrar');

            if (inviteModal) {
                inviteModal.show();
            }
        }

        function openInvitation() {
            if (!invitationToken || !inviteModal) {
                return;
            }

            $.getJSON(chatBase + '/grupo/invitacion/' + encodeURIComponent(invitationToken) + '/datos')
                .done(function (response) {
                    invitationGroup = response.group || 'Grupo';

                    $('#chat-invite-group').text(invitationGroup);
                    $('#chat-invite-text').text(
                        (response.inviter ? response.inviter + ' te invitó' : 'Te invitaron') + ' al grupo:'
                    );
                    $('#chat-invite-error').text('');
                    $('#chat-invite-accept').prop('hidden', false);
                    $('#chat-invite-reject').text('Rechazar');

                    inviteModal.show();
                })
                .fail(function (xhr) {
                    // El enlace pudo expirar, usarse o eliminarse después de abrirlo.
                    showInvitationNotice((xhr.responseJSON || {}).message);
                });
        }

        $('#chat-invite-accept').on('click', function () {
            if (!invitationToken) {
                return;
            }

            var $button = $(this).prop('disabled', true);

            post(chatBase + '/grupo/invitacion/' + encodeURIComponent(invitationToken) + '/aceptar', {})
                .done(function (response) {
                    invitationToken = '';
                    inviteModal.hide();

                    // Se abre el chat y, dentro, la sala del grupo.
                    if ($('#chat-panel').prop('hidden')) {
                        $('#chat-toggle').trigger('click');
                    }

                    refresh();

                    if (response && response.conversation_id) {
                        openConversation(response.conversation_id, invitationGroup, 'Grupo');
                    }
                })
                .fail(function (xhr) {
                    $('#chat-invite-error').text(
                        (xhr.responseJSON || {}).message || 'No se pudo aceptar la invitación.'
                    );
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        $('#chat-invite-reject').on('click', function () {
            if (!invitationToken) {
                if (inviteModal) {
                    inviteModal.hide();
                }

                return;
            }

            post(chatBase + '/grupo/invitacion/' + encodeURIComponent(invitationToken) + '/rechazar', {})
                .always(function () {
                    invitationToken = '';
                    inviteModal.hide();
                });
        });

        if (invitationToken) {
            openInvitation();
        } else if (invitationNotice) {
            showInvitationNotice(invitationNotice);
        }

        function sendPending(pending) {
            if (!currentId) {
                return;
            }

            var conversationId = currentId;

            pending.status = 'sending';
            paintPending();

            var data = new FormData();
            data.append('_token', token);

            if (pending.body) {
                data.append('body', pending.body);
            }

            if (pending.reply && pending.reply.id) {
                data.append('reply_to_id', pending.reply.id);
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
                url: chatBase + '/' + conversationId + '/mensajes',
                method: 'POST',
                dataType: 'json',
                data: data,
                processData: false,
                contentType: false
            }).done(function () {
                // Se usa la conversación en la que se envió: el usuario pudo
                // haber vuelto a la lista mientras el servidor respondía.
                var list = pendingMessages[conversationId] || [];
                var index = list.indexOf(pending);

                if (index > -1) {
                    list.splice(index, 1);
                }

                // El sondeo pudo haber pintado ya el mensaje del servidor y
                // dejar esta burbuja marcada como «enviando»: se retira en el
                // acto y se pide la novedad para que no se vea duplicado.
                if (conversationId === currentId) {
                    syncPending();
                    recentHash = null;
                    loadMessages();
                }

                refresh();
            }).fail(function () {
                pending.status = 'failed';
                paintPending();
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
                // La respuesta viaja con la burbuja: si falla, «Volver a enviar»
                // mantiene la cita.
                reply: replyTarget,
                status: 'sending'
            };

            currentPending().push(pending);
            paintPending();
            sendPending(pending);

            // Ya está en la cola: la cita deja de ocupar la barra de escritura.
            clearReply();
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

            var capacity = 6 - pendingFiles.length;

            if (capacity <= 0) {
                alertDialog('Ya tienes 6 archivos adjuntos. Quita alguno para añadir otro.');
                return;
            }

            if (list.length > capacity) {
                list = list.slice(0, capacity);
                alertDialog('Se adjuntaron ' + capacity + ' archivos: el máximo por mensaje es 6.');
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

        /**
         * Archivos que trae el portapapeles (copiar archivos del explorador,
         * capturas de pantalla, imágenes copiadas de otra web...).
         */
        function clipboardFiles(event) {
            var clipboard = event.clipboardData || (event.originalEvent && event.originalEvent.clipboardData);

            if (!clipboard) {
                return [];
            }

            var files = clipboard.files && clipboard.files.length
                ? Array.prototype.slice.call(clipboard.files)
                : [];

            if (!files.length && clipboard.items && clipboard.items.length) {
                Array.prototype.forEach.call(clipboard.items, function (item) {
                    if (!item || item.kind !== 'file' || typeof item.getAsFile !== 'function') {
                        return;
                    }

                    var file = item.getAsFile();

                    if (file) {
                        files.push(file);
                    }
                });
            }

            return files.filter(function (file) {
                return file && file.size > 0;
            });
        }

        /**
         * Las capturas y las imágenes copiadas suelen llegar sin nombre útil
         * (o todas como «image.png»); se les da uno distinto a cada una.
         */
        function pastedFileName(file, index) {
            var name = String(file.name || '').trim();
            var base = name.replace(/\.[^.]+$/, '');

            if (base && !/^(image|imagen|blob|untitled|archivo|clipboard)$/i.test(base)) {
                return name;
            }

            var extension = String(file.type || '').split('/')[1] || '';

            extension = extension.split('+')[0].replace('jpeg', 'jpg').replace(/[^a-z0-9]/gi, '');

            return 'pegado-' + (index + 1) + '-' + Date.now().toString(36) + '.' + (extension || 'bin');
        }

        function normalizePastedFiles(files) {
            return (files || []).map(function (file, index) {
                var name = pastedFileName(file, index);

                if (name === file.name) {
                    return file;
                }

                try {
                    return new File([file], name, {
                        type: file.type,
                        lastModified: file.lastModified || Date.now()
                    });
                } catch (error) {
                    return file;
                }
            });
        }

        // Ítems del carrusel para los archivos aún sin enviar (bandeja).
        function trayCarouselItems() {
            return pendingFiles.map(function (file, position) {
                var kind = file.type.startsWith('image/')
                    ? 'image'
                    : (file.type.startsWith('video/')
                        ? 'video'
                        : (file.type.startsWith('audio/') ? 'audio' : 'file'));

                return {
                    url: URL.createObjectURL(file),
                    type: kind,
                    kind: kind,
                    name: file.name,
                    local: true,
                    file_index: position,
                    can_delete: true
                };
            });
        }

        $('#chat-tray-items').on('click', '.chat-tray__item', function () {
            var index = parseInt($(this).data('index'), 10) || 0;

            openCarousel(trayCarouselItems(), index);
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
            var name = String(file.name || 'foto').replace(/\.[^.]+$/, '') || 'foto';

            reader.onload = function () {
                var image = new Image();

                image.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = image.width;
                    canvas.height = image.height;
                    canvas.getContext('2d').drawImage(image, 0, 0);

                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            callback(file);

                            return;
                        }

                        callback(new File([blob], name + '.webp', {
                            type: 'image/webp',
                            lastModified: Date.now()
                        }));
                    }, 'image/webp', 0.82);
                };

                image.onerror = function () {
                    callback(file);
                };

                image.src = reader.result;
            };

            reader.onerror = function () {
                callback(file);
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
        var carousel = { items: [], index: 0 };
        var singleLightbox = null;

        function currentLightboxItem() {
            return singleLightbox || carousel.items[carousel.index] || null;
        }

        // ¿Se puede borrar el contenido que muestra el carrusel? Los archivos
        // todavía sin enviar (bandeja) también se pueden quitar.
        function lightboxCanDelete(item) {
            if (!item) {
                return false;
            }

            if (item.local) {
                return true;
            }

            return item.can_delete === true && Boolean(item.attachment_id || item.message_id);
        }

        function hideLightboxMenu() {
            $('#chat-lightbox-menu').prop('hidden', true).css({ top: '', left: '' });
        }

        function absoluteUrl(url) {
            try {
                return new URL(url, window.location.href).href;
            } catch (error) {
                return url;
            }
        }

        function paintLightboxItem() {
            var item = currentLightboxItem();

            if (!item) {
                return;
            }

            var url = item.url || '';
            var name = item.name || '';
            var $stage = $('#chat-lightbox-stage').empty();

            if (item.type === 'image') {
                $stage.append($('<img id="chat-lightbox-image" alt="">').attr({ src: url, alt: name }));
            } else if (item.type === 'video') {
                $stage.append($('<video controls></video>').attr('src', url));
            } else if (item.type === 'pdf') {
                $stage.append($('<iframe title="PDF"></iframe>').attr('src', url));
            } else {
                $stage.append(
                    '<div class="chat-audio chat-audio--carousel">' +
                    '<canvas class="chat-wave" width="320" height="60" data-url="' + escape(url) + '"></canvas>' +
                    '<audio class="chat-attachment__audio" controls src="' + escape(url) + '"></audio></div>'
                );
            }

            $('#chat-lightbox-name').text(name);
            $('#chat-lightbox-download').attr('href', url).attr('download', name || 'archivo');
            $('#chat-lightbox-delete').prop('hidden', !lightboxCanDelete(item));

            lightboxScale = 1;
            lightboxDrag = null;
            $('#chat-lightbox-image').css({ transform: '', marginLeft: '', marginTop: '' });

            paintLightboxControls();
            drawWaveforms();
        }

        function paintLightboxControls() {
            $('#chat-lightbox-stage .chat-carousel__arrow').remove();
            $('.chat-lightbox__footer .chat-carousel__thumbs').remove();

            if (carousel.items.length < 2) {
                return;
            }

            $('#chat-lightbox-stage').append(
                '<button type="button" class="chat-carousel__arrow chat-carousel__arrow--prev" aria-label="Anterior">&#8249;</button>' +
                '<button type="button" class="chat-carousel__arrow chat-carousel__arrow--next" aria-label="Siguiente">&#8250;</button>'
            );

            $('.chat-lightbox__footer').append(
                '<div class="chat-carousel__thumbs">' + carousel.items.map(function (entry, index) {
                    var thumb = entry.type === 'video'
                        ? '<video src="' + escape(entry.url) + '"></video>'
                        : (entry.type === 'image' ? '<img src="' + escape(entry.url) + '" alt="">' : '<i class="fa-regular fa-file"></i>');

                    return '<button type="button" class="chat-carousel__thumb' + (index === carousel.index ? ' is-active' : '') +
                        '" data-index="' + index + '" aria-label="Ver elemento ' + (index + 1) + '">' + thumb + '</button>';
                }).join('') + '</div>'
            );
        }

        function showCarouselItem(index) {
            if (!carousel.items.length) {
                return;
            }

            carousel.index = (index + carousel.items.length) % carousel.items.length;
            singleLightbox = null;
            hideLightboxMenu();
            paintLightboxItem();
        }

        function openCarousel(items, index) {
            var list = items || [];

            carousel.items = list;
            carousel.index = Math.max(0, Math.min(parseInt(index, 10) || 0, list.length - 1));
            singleLightbox = null;
            hideLightboxMenu();
            paintLightboxItem();
            $('#chat-lightbox').prop('hidden', false);
        }

        function openLightbox(type, src, name, meta) {
            carousel.items = [];
            carousel.index = 0;
            singleLightbox = $.extend({}, meta || {}, {
                url: src,
                type: type || 'audio',
                name: name
            });
            hideLightboxMenu();
            paintLightboxItem();
            $('#chat-lightbox').prop('hidden', false);
        }

        function closeLightbox() {
            $('#chat-lightbox').prop('hidden', true);
            $('#chat-lightbox-stage').empty();
            $('.chat-lightbox__footer .chat-carousel__thumbs').remove();
            hideLightboxMenu();
            carousel.items = [];
            carousel.index = 0;
            singleLightbox = null;
            lightboxScale = 1;
            lightboxDrag = null;
        }

        $('#chat-lightbox-close').on('click', closeLightbox);

        // Un único manejador para todo el carrusel: se detiene la propagación para que
        // el clic no llegue al manejador global que cierra el panel del chat (sus nodos
        // ya fueron repintados y parecían estar «fuera» del widget).
        $('#chat-lightbox').on('click', function (event) {
            var $target = $(event.target);

            event.stopPropagation();

            if ($target.closest('#chat-lightbox-menu').length) {
                return;
            }

            hideLightboxMenu();

            var $thumb = $target.closest('.chat-carousel__thumb');

            if ($thumb.length) {
                showCarouselItem(parseInt($thumb.attr('data-index'), 10) || 0);
                return;
            }

            var $arrow = $target.closest('.chat-carousel__arrow');

            if ($arrow.length) {
                showCarouselItem(carousel.index + ($arrow.hasClass('chat-carousel__arrow--next') ? 1 : -1));
                return;
            }

            if ($target.closest('.chat-lightbox__actions, .chat-lightbox__footer').length) {
                return;
            }

            if (event.target === this) {
                closeLightbox();
                return;
            }

            // Clic fuera de la imagen (fondo del escenario): sí cierra.
            if ($target.closest('#chat-lightbox-stage').length &&
                !$target.is('img, video, audio, iframe, canvas, button, a, input, .chat-audio')) {
                closeLightbox();
            }
        });

        $(document).on('click', '.chat-media-open', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var $grid = $(this).closest('.chat-media-grid');

            if ($grid.length) {
                var items = $grid.find('.chat-media-open').map(function () {
                    return lightboxItemFrom($(this));
                }).get();

                openCarousel(items, $(this).data('index') || 0);
                return;
            }

            openLightbox(
                $(this).data('lightbox') || $(this).data('type'),
                $(this).data('src'),
                $(this).data('name'),
                lightboxItemFrom($(this))
            );
        });

        // Metadatos del contenido que se abre en el carrusel: permiten
        // descargarlo, copiarlo o eliminarlo desde ahí.
        function lightboxItemFrom($node) {
            return {
                url: $node.data('src'),
                type: $node.data('type') || $node.data('lightbox'),
                name: $node.data('name'),
                message_id: parseInt($node.attr('data-message-id'), 10) || null,
                attachment_id: parseInt($node.attr('data-attachment-id'), 10) || null,
                can_delete: String($node.attr('data-can-delete')) === '1'
            };
        }

        $(document).on('keydown', function (event) {
            if ($('#chat-lightbox').prop('hidden')) {
                return;
            }

            if (event.key === 'Escape') {
                closeLightbox();
                return;
            }

            if (!carousel.items.length) {
                return;
            }

            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                showCarouselItem(carousel.index + (event.key === 'ArrowRight' ? 1 : -1));
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

        function copyLightboxText(text, okMessage) {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                alertDialog('Tu navegador no permite copiar al portapapeles.');
                return;
            }

            navigator.clipboard.writeText(text).then(function () {
                alertDialog(okMessage || 'Enlace copiado.');
            }).catch(function () {
                alertDialog('No se pudo copiar. Revisa el permiso del portapapeles.');
            });
        }

        function contentKind(item) {
            if (!item) {
                return 'file';
            }

            return String(item.kind || item.type || 'file');
        }

        function copyImageContent(item) {
            if (!item) {
                return;
            }

            if (contentKind(item) !== 'image') {
                copyLightboxText(absoluteUrl(item.url));
                return;
            }

            fetch(item.url)
                .then(function (response) { return response.blob(); })
                .then(function (blob) {
                    if (!navigator.clipboard || !window.ClipboardItem) {
                        throw new Error('sin portapapeles de imágenes');
                    }

                    return navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
                })
                .then(function () {
                    alertDialog('Imagen copiada.');
                })
                .catch(function () {
                    copyLightboxText(absoluteUrl(item.url));
                });
        }

        function downloadContent(item) {
            if (!item || !item.url) {
                return;
            }

            var link = document.createElement('a');

            link.href = item.url;
            link.download = item.name || 'archivo';
            link.rel = 'noopener';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function copyLightboxItem() {
            copyImageContent(currentLightboxItem());
        }

        function copyLightboxLink() {
            var item = currentLightboxItem();

            if (item) {
                copyLightboxText(absoluteUrl(item.url));
            }
        }

        function downloadLightboxItem() {
            downloadContent(currentLightboxItem());
        }

        // Acciones que ofrece el clic derecho según el tipo de contenido.
        var CONTENT_MENU_ACTIONS = {
            image: ['copy-image', 'copy-link', 'download', 'delete'],
            video: ['copy-link', 'download', 'delete'],
            audio: ['copy-link', 'download', 'delete'],
            pdf: ['download', 'delete'],
            file: ['download', 'delete'],
            link: ['copy-link', 'delete']
        };

        var contentTarget = null;

        function hideContentMenu() {
            $('#chat-content-menu').prop('hidden', true).css({ top: '', left: '' });
            contentTarget = null;
        }

        function contentCanDelete(item) {
            if (!item) {
                return false;
            }

            if (item.local) {
                return true;
            }

            return item.can_delete !== false && Boolean(item.attachment_id || item.message_id);
        }

        function showContentMenu(event, item) {
            var $menu = $('#chat-content-menu');

            if (!item || !$menu.length) {
                return;
            }

            var allowed = CONTENT_MENU_ACTIONS[contentKind(item)] || CONTENT_MENU_ACTIONS.file;

            contentTarget = item;

            $menu.find('[data-content-action]').each(function () {
                var action = $(this).attr('data-content-action');
                var visible = allowed.indexOf(action) !== -1;

                if (action === 'delete') {
                    visible = visible && contentCanDelete(item);
                }

                $(this).prop('hidden', !visible);
            });

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
        }

        // Quita de la bandeja un archivo todavía sin enviar.
        function removeLocalContent(item) {
            var index = parseInt(item.file_index, 10);

            if (isNaN(index) || !pendingFiles[index]) {
                return;
            }

            pendingFiles.splice(index, 1);
            renderTray();

            var items = trayCarouselItems();

            if (!items.length) {
                closeLightbox();
                return;
            }

            openCarousel(items, Math.min(index, items.length - 1));
        }

        function afterContentDeleted(item, response) {
            var messageDeleted = response.message_deleted === true
                || (!item.attachment_id && Boolean(item.message_id));

            function belongs(entry) {
                if (!entry) {
                    return false;
                }

                if (messageDeleted && item.message_id) {
                    return String(entry.message_id) === String(item.message_id);
                }

                if (item.attachment_id) {
                    return String(entry.id) === String(item.attachment_id);
                }

                return String(entry.message_id) === String(item.message_id) && entry.url === item.url;
            }

            galleryItems = galleryItems.filter(function (entry) { return !belongs(entry); });
            profileMedia = profileMedia.filter(function (entry) { return !belongs(entry); });

            if (profileData) {
                renderProfileMedia();
            }

            if (!$('#chat-gallery-panel').prop('hidden')) {
                var tab = $('#chat-gallery-panel [data-gallery].is-active').attr('data-gallery') || 'multimedia';

                renderGallery(tab);
            }

            if (item.message_id) {
                refreshMessages([item.message_id]);
            }

            refresh();

            if (!$('#chat-lightbox').prop('hidden') && carousel.items.length) {
                carousel.items = carousel.items.filter(function (entry) { return !belongs(entry); });

                if (!carousel.items.length) {
                    closeLightbox();
                    return;
                }

                carousel.index = Math.min(carousel.index, carousel.items.length - 1);
                paintLightboxItem();
            }
        }

        // Borra el contenido (adjunto o enlace) y, si el servidor lo indica,
        // también el mensaje que lo contenía.
        function deleteContentItem(item) {
            if (!item) {
                return;
            }

            if (item.local) {
                removeLocalContent(item);
                return;
            }

            var endpoint = item.attachment_id
                ? chatBase + '/adjuntos/' + item.attachment_id + '/eliminar'
                : (item.message_id ? chatBase + '/mensajes/' + item.message_id + '/eliminar' : null);

            if (!endpoint) {
                return;
            }

            var question = item.attachment_id
                ? '¿Eliminar este contenido? Si es el último adjunto del mensaje, también se eliminará el mensaje.'
                : '¿Eliminar el mensaje con este contenido?';

            confirmDialog(question, function () {
                post(endpoint, {}).done(function (response) {
                    hideContentMenu();
                    afterContentDeleted(item, response || {});
                }).fail(function (xhr) {
                    alertDialog((xhr.responseJSON || {}).message || 'No se pudo eliminar el contenido.');
                });
            });
        }

        function showLightboxMenu(event) {
            var item = currentLightboxItem();
            var $menu = $('#chat-lightbox-menu');

            if (!item || !$menu.length) {
                return;
            }

            $menu.find('[data-lightbox-action="copy"]').text(item.type === 'image' ? 'Copiar imagen' : 'Copiar');
            $menu.find('[data-lightbox-action="delete"]').prop('hidden', !lightboxCanDelete(item));
            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
        }

        $('#chat-lightbox').on('contextmenu', function (event) {
            if (!currentLightboxItem() || $(event.target).closest('#chat-lightbox-menu').length) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            showLightboxMenu(event);
        });

        $('#chat-lightbox-menu').on('click', '.chat-context-item', function (event) {
            var action = $(this).attr('data-lightbox-action');

            event.preventDefault();
            event.stopPropagation();
            hideLightboxMenu();

            if (action === 'copy') {
                copyLightboxItem();
            } else if (action === 'download') {
                downloadLightboxItem();
            } else if (action === 'link') {
                copyLightboxLink();
            } else if (action === 'delete') {
                deleteContentItem(currentLightboxItem());
            }
        });

        $('#chat-lightbox-copy').on('click', copyLightboxItem);

        $('#chat-lightbox-delete').on('click', function () {
            deleteContentItem(currentLightboxItem());
        });

        // Clic derecho en cualquier contenido de las galerías.
        $(document).on('contextmenu', '[data-content-menu]', function (event) {
            var item = $(this).data('content');

            if (!item) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            showContentMenu(event, item);
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-content-menu').length) {
                hideContentMenu();
            }
        });

        $('#chat-content-menu').on('click', '.chat-context-item', function (event) {
            var action = $(this).attr('data-content-action');
            var item = contentTarget;

            event.preventDefault();
            event.stopPropagation();
            hideContentMenu();

            if (!item) {
                return;
            }

            if (action === 'copy-image') {
                copyImageContent(item);
            } else if (action === 'copy-link') {
                copyLightboxText(absoluteUrl(item.url));
            } else if (action === 'download') {
                downloadContent(item);
            } else if (action === 'delete') {
                deleteContentItem(item);
            }
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
        var profileData = null;
        var profileMedia = [];
        var profileView = 'main';

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

            openCarousel([$.extend(lightboxItemFrom($(this)), {
                url: $(this).data('url'),
                type: 'audio',
                name: $(this).data('name')
            })], 0);
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

        // Pinta la galería y asocia cada nodo con su contenido para el clic
        // derecho (copiar, descargar, eliminar).
        function paintGalleryList(html, list) {
            var $list = $('#chat-gallery-list');

            $list.html(html);

            $list.find('[data-content-index]').each(function () {
                var item = list[parseInt($(this).attr('data-content-index'), 10)];

                if (item) {
                    $(this).data('content', $.extend({}, item, {
                        kind: item.kind,
                        attachment_id: item.id || null,
                        message_id: item.message_id || null,
                        can_delete: item.can_delete !== false
                    }));
                }
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
                paintGalleryList('<div class="chat-gallery-grid">' + items.map(function (item, index) {
                    var preview = item.kind === 'video'
                        ? '<video src="' + item.url + '"></video>'
                        : '<img src="' + item.url + '" alt="">';

                    return '<button type="button" class="chat-gallery-thumb gallery-item" title="' + escape(item.name || '') + '"' +
                        ' data-content-menu="1" data-content-index="' + index + '"' +
                        ' data-index="' + index + '" data-kind="' + item.kind + '" data-src="' + item.url + '"' +
                        ' data-name="' + escape(item.name || '') + '">' + preview + '</button>';
                }).join('') + '</div>', items);

                return;
            }

            if (filter === 'audios') {
                paintGalleryList('<div class="chat-gallery-grid">' + items.map(function (item, index) {
                    return '<button type="button" class="chat-gallery-audio gallery-item" title="' + escape(item.name || '') + '"' +
                        ' data-content-menu="1" data-content-index="' + index + '"' +
                        ' data-index="' + index + '" data-kind="audio" data-src="' + item.url + '"' +
                        ' data-name="' + escape(item.name || '') + '">' +
                        '<i class="fa-solid fa-microphone-lines" aria-hidden="true"></i></button>';
                }).join('') + '</div>', items);

                return;
            }

            paintGalleryList(items.map(function (item, index) {
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

                return '<div class="chat-gallery-row" data-content-menu="1" data-content-index="' + index + '">' +
                    '<div class="chat-gallery-row__main">' +
                    '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>' +
                    nameLink +
                    '<span class="chat-gallery-row__meta">' + escape(meta) + '</span>' +
                    '</div></div>';
            }).join(''), items);
        }

        function profileInitials(name) {
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            var first = parts.length ? parts[0].charAt(0) : '?';
            var second = parts.length > 1 ? parts[1].charAt(0) : '';

            return (first + second).toUpperCase();
        }

        function profileMemberRow(member) {
            var name = escape(member.name || '');
            var meta = member.username ? '@' + escape(member.username) : '';

            if (member.online) {
                meta += (meta ? ' · ' : '') + 'En línea';
            }

            var badge = '';

            if (member.is_owner) {
                badge = '<span class="chat-profile__member-badge chat-profile__member-badge--owner">Dueño</span>';
            } else if (member.status && member.status !== 'Activo') {
                badge = '<span class="chat-profile__member-badge chat-profile__member-badge--danger">' + escape(member.status) + '</span>';
            }

            return '<div class="chat-profile__member" data-search="'
                + escape((String(member.name || '') + ' ' + String(member.username || '')).toLowerCase()) + '">'
                + '<span class="chat-profile__member-avatar">' + escape(profileInitials(member.name)) + '</span>'
                + '<div class="chat-profile__member-main">'
                + '<span class="chat-profile__member-name">' + name + '</span>'
                + '<span class="chat-profile__member-meta">' + meta + '</span>'
                + '</div>'
                + badge
                + '</div>';
        }

        function renderProfileMembers(members) {
            var list = members || [];

            $('#chat-profile-member-list').html(
                list.length
                    ? list.map(profileMemberRow).join('')
                    : '<p class="chat-profile__empty">El grupo no tiene miembros.</p>'
            );
        }

        function renderProfileAudit(entries) {
            var list = entries || [];

            if (!list.length) {
                $('#chat-profile-audit-list').html('<p class="chat-profile__empty">Todavía no hay cambios registrados.</p>');
                return;
            }

            $('#chat-profile-audit-list').html(list.map(function (entry) {
                var meta = [];

                if (entry.actor) {
                    meta.push('Por ' + entry.actor);
                }

                if (entry.target) {
                    meta.push('Sobre ' + entry.target);
                }

                if (entry.status) {
                    meta.push('Estado: ' + entry.status);
                }

                return '<article class="chat-profile__audit-entry">'
                    + '<div class="chat-profile__audit-head">'
                    + '<p class="chat-profile__audit-action">' + escape(entry.label || entry.action || '') + '</p>'
                    + '<span class="chat-profile__audit-date">' + escape(entry.created_at || '') + '</span>'
                    + '</div>'
                    + (meta.length ? '<p class="chat-profile__audit-meta">' + escape(meta.join(' · ')) + '</p>' : '')
                    + (entry.details ? '<p class="chat-profile__audit-meta">' + escape(entry.details) + '</p>' : '')
                    + '</article>';
            }).join(''));
        }

        function renderProfileMedia() {
            var $grid = $('#chat-profile-media-grid');
            var $link = $('#chat-profile-main .chat-profile__section-head [data-profile-action="gallery"]');

            var media = (profileMedia || []).filter(function (item) {
                return item && (item.kind === 'image' || item.kind === 'video' || item.kind === 'audio');
            }).sort(function (first, second) {
                // Orden cronológico por el mensaje en que se envió, sin
                // agrupar por tipo: imágenes, vídeos y audios se mezclan.
                var byMessage = Number(first.message_id || 0) - Number(second.message_id || 0);

                if (byMessage !== 0) {
                    return byMessage;
                }

                return Number(first.position || 0) - Number(second.position || 0);
            }).slice(-5);

            // Sin contenido multimedia la sección se queda solo con el título:
            // nada de recuadros vacíos ni acceso a una galería vacía.
            if (!media.length) {
                $grid.prop('hidden', true).empty();
                $link.prop('hidden', true);

                return;
            }

            $grid.prop('hidden', false);
            $link.prop('hidden', false);

            $grid.html(media.map(function (item, index) {
                var tab = item.kind === 'audio' ? 'audios' : 'multimedia';
                var inner = '';

                if (item.kind === 'image') {
                    inner = '<img src="' + escape(item.url) + '" alt="' + escape(item.name || '') + '" loading="lazy">';
                } else if (item.kind === 'video') {
                    inner = '<video src="' + escape(item.url) + '" muted preload="metadata" playsinline></video>';
                } else {
                    inner = '<i class="fa-solid fa-music" aria-hidden="true"></i>';
                }

                return '<button type="button" class="chat-profile__tile'
                    + (item.kind === 'audio' ? ' chat-profile__tile--audio' : '')
                    + '" data-profile-action="gallery" data-gallery-tab="' + tab + '"'
                    + ' data-content-menu="1" data-content-index="' + index + '"'
                    + ' title="' + escape(item.name || 'Contenido') + '">' + inner + '</button>';
            }).join(''));

            // Cada miniatura guarda su contenido para el clic derecho.
            $grid.find('[data-content-index]').each(function () {
                var item = media[parseInt($(this).attr('data-content-index'), 10)];

                if (item) {
                    $(this).data('content', $.extend({}, item, {
                        kind: item.kind,
                        attachment_id: item.id || null,
                        message_id: item.message_id || null,
                        can_delete: item.can_delete !== false
                    }));
                }
            });
        }

        function profileOption(action, icon, label, variant) {
            return '<button type="button" class="chat-profile__option'
                + (variant === 'danger' ? ' chat-profile__option--danger' : '')
                + '" data-profile-action="' + action + '">'
                + '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>'
                + '<span>' + escape(label) + '</span>'
                + '</button>';
        }

        function renderChatProfile(data) {
            var name = data.other && data.other.name ? data.other.name : (data.name || data.title || 'Conversación');
            var html = '';

            if (data.is_secret && data.secret_role === 'receiver') {
                name = 'Amigo secreto';
            }

            $('#chat-profile-title').text(data.is_group ? (data.name || 'Grupo') : name);
            $('#chat-profile-eyebrow').text(data.is_group ? 'Grupo' : (data.is_moderating ? 'Moderación' : 'Perfil'));

            html += '<div class="chat-profile__identity">'
                + '<span class="chat-profile__avatar">' + escape(profileInitials(name)) + '</span>'
                + '<p class="chat-profile__name">' + escape(name) + '</p>';

            if (data.is_group) {
                html += '<p class="chat-profile__username">' + Number(data.members_count || 0) + ' miembros</p>';
            } else if (data.is_secret && data.secret_role === 'receiver') {
                html += '<p class="chat-profile__username">Identidad protegida hasta el final del evento</p>';
            } else if (data.other) {
                html += '<p class="chat-profile__username">@' + escape(data.other.username || '') + '</p>'
                    + '<p class="chat-profile__email">' + escape(data.other.email || '') + '</p>';
            }

            if (data.is_moderating) {
                html += '<p class="chat-profile__moderating">Estás moderando esta conversación</p>';
            }

            html += '</div>';

            if (data.is_group) {
                var members = data.members || [];
                var visible = members.slice(0, 5);

                html += '<div class="chat-profile__card">'
                    + '<div class="chat-profile__section-head">'
                    + '<span class="chat-profile__section-title">Miembros (' + Number(data.members_count || 0) + ')</span>'
                    + '<button type="button" class="chat-profile__link" data-profile-action="members">'
                    + 'Ver todos <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>'
                    + '</div>'
                    + '<div class="chat-profile__member-summary">'
                    + (visible.length ? visible.map(profileMemberRow).join('') : '<p class="chat-profile__empty">Sin miembros.</p>')
                    + '</div></div>';

                if (data.can_manage) {
                    html += '<div class="chat-profile__card">'
                        + '<div class="chat-profile__section-head">'
                        + '<span class="chat-profile__section-title">Auditoría del grupo</span>'
                        + '<button type="button" class="chat-profile__link" data-profile-action="audit">'
                        + 'Ver auditoría <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>'
                        + '</div></div>';
                }
            }

            html += '<div class="chat-profile__card">'
                + '<div class="chat-profile__section-head">'
                + '<span class="chat-profile__section-title">Contenido multimedia, documentos y enlaces</span>'
                + '<button type="button" class="chat-profile__link" data-profile-action="gallery" data-gallery-tab="multimedia"'
                + ' aria-label="Ver todo el contenido">'
                + '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>'
                + '</div>'
                + '<div class="chat-profile__media-grid" id="chat-profile-media-grid">'
                + '<p class="chat-profile__media-empty">Cargando contenido...</p>'
                + '</div></div>';

            var options = [];

            if (data.is_participant) {
                options.push(profileOption('favorite', 'fa-star', data.favorite ? 'Quitar de favoritos' : 'Añadir a favoritos'));
                options.push(profileOption('vaciar', 'fa-broom', data.is_group ? 'Vaciar sala' : 'Vaciar chat'));
            }

            if (data.can_block && data.other) {
                options.push(profileOption(
                    data.blocked ? 'unblock' : 'block',
                    data.blocked ? 'fa-unlock' : 'fa-ban',
                    (data.blocked ? 'Desbloquear ' : 'Bloquear ') + data.other.name
                ));
            }

            if (data.can_leave) {
                options.push(profileOption('leave', 'fa-right-from-bracket', 'Salir del grupo'));
            }

            if (data.can_delete) {
                options.push(profileOption('delete', 'fa-trash', 'Eliminar grupo', 'danger'));
            }

            if (data.can_report && (!data.is_secret || data.secret_role === 'giver' || data.is_group)) {
                options.push(profileOption(
                    data.is_group ? 'report-group' : 'report-user',
                    'fa-flag',
                    data.is_group ? 'Reportar grupo' : 'Reportar ' + (data.other ? data.other.name : 'usuario')
                ));
            }

            if (options.length) {
                html += '<div class="chat-profile__options">' + options.join('') + '</div>';
            }

            $('#chat-profile-main').html(html).prop('hidden', false);
            $('#chat-profile-loading').prop('hidden', true);
            showProfileSection('main');
            renderProfileMedia();
        }

        function showProfileSection(name) {
            profileView = name;
            $('#chat-profile [data-profile-section]').prop('hidden', true);
            $('#chat-profile [data-profile-section="' + name + '"]').prop('hidden', false);
            $('#chat-gallery-panel').prop('hidden', name !== 'gallery');
            $('#chat-profile-back').attr('aria-label', name === 'main' ? 'Volver a la conversación' : 'Volver al perfil');
            $('#chat-profile-body').scrollTop(0);
        }

        function openChatProfile() {
            if (!currentId) {
                return;
            }

            var conversationId = currentId;

            profileData = null;
            profileMedia = [];
            profileView = 'main';

            $('#chat-profile').prop('hidden', false);
            $('#chat-profile-loading').prop('hidden', false).text('Cargando perfil...');
            $('#chat-profile-main').empty().prop('hidden', true);
            $('#chat-profile [data-profile-section]').prop('hidden', true);
            $('#chat-profile [data-profile-section="main"]').prop('hidden', false);
            $('#chat-profile-title').text('Perfil');
            $('#chat-profile-eyebrow').text(currentModeration ? 'Moderación' : 'Perfil');

            $.getJSON(chatBase + '/' + conversationId + '/perfil')
                .done(function (response) {
                    if (currentId !== conversationId) {
                        return;
                    }

                    profileData = response;
                    renderChatProfile(response);

                    $.getJSON(chatBase + '/' + conversationId + '/media')
                        .done(function (mediaResponse) {
                            if (currentId !== conversationId || !profileData) {
                                return;
                            }

                            profileMedia = mediaResponse.items || [];
                            renderProfileMedia();
                        })
                        .fail(function () {
                            if (currentId === conversationId && profileData) {
                                profileMedia = [];
                                renderProfileMedia();
                            }
                        });
                })
                .fail(function (xhr) {
                    if (currentId !== conversationId) {
                        return;
                    }

                    $('#chat-profile-loading')
                        .prop('hidden', false)
                        .text((xhr.responseJSON || {}).message || 'No se pudo cargar el perfil.');
                });
        }

        function closeChatProfile() {
            $('#chat-profile').prop('hidden', true);
            $('#chat-gallery-panel').prop('hidden', true);
            profileData = null;
            profileMedia = [];
            profileView = 'main';
            $('#chat-profile-member-search').val('');
        }

        function openProfileGallery(tab) {
            if (!profileData) {
                return;
            }

            var selected = tab === 'documents' || tab === 'audios' || tab === 'links' ? tab : 'multimedia';

            showProfileSection('gallery');
            $('#chat-gallery-panel [data-gallery]').removeClass('is-active');
            $('#chat-gallery-panel [data-gallery="' + selected + '"]').addClass('is-active');

            if (profileMedia.length) {
                galleryItems = profileMedia;
                renderGallery(selected);
                return;
            }

            $.getJSON(chatBase + '/' + currentId + '/media')
                .done(function (response) {
                    profileMedia = response.items || [];
                    galleryItems = profileMedia;
                    renderGallery(selected);
                })
                .fail(function (xhr) {
                    alertDialog((xhr.responseJSON || {}).message || 'No se pudo cargar el contenido.');
                });
        }

        function openProfileMembers() {
            if (!profileData) {
                return;
            }

            $('#chat-profile-members-title').text('Miembros (' + Number(profileData.members_count || 0) + ')');
            renderProfileMembers(profileData.members || []);
            $('#chat-profile-member-search').val('');
            showProfileSection('members');
        }

        function openProfileAudit() {
            if (!profileData) {
                return;
            }

            renderProfileAudit(profileData.audit || []);
            showProfileSection('audit');
        }

        function profileError(xhr) {
            alertDialog((xhr.responseJSON || {}).message || 'No se pudo completar la operación.');
        }

        function handleProfileAction(action, $trigger) {
            if (!profileData) {
                return;
            }

            var other = profileData.other;

            if (action === 'gallery') {
                openProfileGallery($trigger.data('gallery-tab'));
                return;
            }

            if (action === 'members') {
                openProfileMembers();
                return;
            }

            if (action === 'audit') {
                openProfileAudit();
                return;
            }

            if (action === 'favorite') {
                post(chatBase + '/' + currentId + '/favorito', {})
                    .done(function (response) {
                        if (profileData) {
                            profileData.favorite = !!response.favorite;
                        }

                        var $button = $('#chat-profile [data-profile-action="favorite"]');

                        $button.find('span').text(response.favorite ? 'Quitar de favoritos' : 'Añadir a favoritos');
                        refresh();
                    })
                    .fail(profileError);
                return;
            }

            if (action === 'vaciar') {
                confirmDialog(
                    profileData.is_group ? '¿Vaciar esta sala de tu lado?' : '¿Vaciar este chat de tu lado?',
                    function () {
                        post(chatBase + '/' + currentId + '/vaciar', {})
                            .done(function () {
                                closeChatProfile();
                                reloadConversationMessages();
                                refresh();
                            })
                            .fail(profileError);
                    }
                );
                return;
            }

            if (action === 'block' || action === 'unblock') {
                if (!other) {
                    return;
                }

                var blocking = action === 'block';

                confirmDialog(
                    blocking ? '¿Bloquear a ' + other.name + '?' : '¿Desbloquear a ' + other.name + '?',
                    function () {
                        var blockCall = blocking
                            ? post(chatBase + '/usuarios/' + other.id + '/bloquear', {})
                            : remove(chatBase + '/usuarios/' + other.id + '/bloquear', {});

                        blockCall
                            .done(function () {
                                if (profileData) {
                                    profileData.blocked = blocking;
                                    renderChatProfile(profileData);
                                }

                                refresh();
                            })
                            .fail(profileError);
                    }
                );
                return;
            }

            if (action === 'report-user') {
                if (!other) {
                    return;
                }

                promptDialog('Reportar usuario', 'Escribe el motivo del reporte:', function (value) {
                    post(chatBase + '/usuarios/' + other.id + '/reportar', { reason: value })
                        .done(function () {
                            alertDialog('Reporte enviado a la administración.');
                        })
                        .fail(profileError);
                });
                return;
            }

            if (action === 'report-group') {
                confirmDialog('¿Reportar este grupo a la administración?', function () {
                    post(chatBase + '/' + currentId + '/reportar-conversacion', {})
                        .done(function () {
                            alertDialog('Reporte enviado a la administración.');
                        })
                        .fail(profileError);
                });
                return;
            }

            if (action === 'leave') {
                confirmDialog('¿Salir de «' + (profileData.name || 'este grupo') + '»?', function () {
                    post(chatBase + '/' + currentId + '/salir', {})
                        .done(function () {
                            closeChatProfile();
                            showList();
                            refresh();
                        })
                        .fail(profileError);
                });
                return;
            }

            if (action === 'delete') {
                confirmDialog('¿Eliminar este grupo para todos? No se puede deshacer.', function () {
                    remove(chatBase + '/' + currentId, {})
                        .done(function () {
                            closeChatProfile();
                            showList();
                            refresh();
                        })
                        .fail(profileError);
                });
            }
        }

        $('#chat-profile-open').on('click', openChatProfile);

        $('#chat-profile-back').on('click', function () {
            if (profileView !== 'main') {
                showProfileSection('main');
                return;
            }

            closeChatProfile();
        });

        $('#chat-profile').on('click', '[data-profile-action]', function () {
            handleProfileAction($(this).data('profile-action'), $(this));
        });

        $('#chat-profile-member-search').on('input', function () {
            var term = String($(this).val() || '').toLowerCase().trim();

            $('#chat-profile-member-list .chat-profile__member').each(function () {
                var haystack = String($(this).data('search') || '');
                $(this).toggle(term === '' || haystack.indexOf(term) !== -1);
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
                            kind: item.kind,
                            name: item.name,
                            message_id: item.message_id || null,
                            attachment_id: item.id || null,
                            can_delete: item.can_delete !== false
                        };
                    });

                openCarousel(list, parseInt($(this).data('index'), 10) || 0);
                return;
            }

            var content = $(this).data('content') || {};

            openLightbox($(this).data('kind'), $(this).data('src'), $(this).data('name'), content);
        });

        $('#chat-input').on('contextmenu', function (event) {
            event.preventDefault();
            event.stopPropagation();

            hideContextMenu();
            hideListMenu();
            hideUserMenu();
            hideContentMenu();

            var $menu = $('#chat-text-menu');

            $menu.prop('hidden', false).css({ top: '0px', left: '0px' });
            placeMenu($menu, event);
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
                pasteFromClipboard();

                return;
            }

            document.execCommand(action, false, null);
        });

        /**
         * «Pegar» del menú contextual: si el portapapeles trae imágenes o
         * archivos los adjunta a la bandeja (con su vista previa) y, si trae
         * texto, lo escribe en la barra.
         */
        function pasteFromClipboard() {
            function pasteText() {
                if (!navigator.clipboard || !navigator.clipboard.readText) {
                    alertDialog('Usa Ctrl+V para pegar.');

                    return;
                }

                navigator.clipboard.readText().then(function (text) {
                    if (text) {
                        document.execCommand('insertText', false, text);
                    }
                }).catch(function () {
                    alertDialog('No se pudo pegar. Usa Ctrl+V.');
                });
            }

            if (!navigator.clipboard || !navigator.clipboard.read) {
                pasteText();

                return;
            }

            navigator.clipboard.read().then(function (items) {
                var files = [];
                var text = null;
                var tasks = [];

                items.forEach(function (item) {
                    var types = item.types || [];
                    var imageType = types.filter(function (type) {
                        return type.indexOf('image/') === 0;
                    })[0];

                    if (imageType) {
                        tasks.push(item.getType(imageType).then(function (blob) {
                            files.push(new File([blob], 'pegado-' + (files.length + 1) + '.' + (imageType.split('/')[1] || 'png'), {
                                type: imageType
                            }));
                        }));

                        return;
                    }

                    if (types.indexOf('text/plain') !== -1) {
                        tasks.push(item.getType('text/plain').then(function (blob) {
                            return blob.text();
                        }).then(function (value) {
                            if (text === null && value) {
                                text = value;
                            }
                        }));
                    }
                });

                return Promise.all(tasks).then(function () {
                    if (files.length) {
                        addTrayFiles(normalizePastedFiles(files));
                    }

                    if (text) {
                        document.execCommand('insertText', false, text);
                    }

                    if (!files.length && !text) {
                        alertDialog('No hay nada que pegar.');
                    }
                });
            }).catch(function () {
                pasteText();
            });
        }

        $(document).on('click', function (event) {
            if (!$(event.target).closest('#chat-text-menu').length) {
                $('#chat-text-menu').prop('hidden', true);
            }
        });

        /* ------------------------------------------------------------------
           Etiquetas @: sugerencias mientras se escribe
           ------------------------------------------------------------------ */

        var $mentionList = $('#chat-mentions');

        function hideMentionSuggestions() {
            mentionMatches = [];
            mentionActive = 0;
            mentionRange = null;

            if ($mentionList.length) {
                $mentionList.prop('hidden', true).empty();
            }
        }

        /**
         * Rango del texto «@algo» que se está escribiendo antes del cursor.
         * Devuelve null si el cursor no está dentro de una etiqueta.
         */
        function mentionContext() {
            var selection = window.getSelection();

            if (!selection || !selection.rangeCount) {
                return null;
            }

            var range = selection.getRangeAt(0);

            if (!range.collapsed) {
                return null;
            }

            var node = range.startContainer;

            if (node.nodeType !== 3) {
                return null;
            }

            var before = node.nodeValue.slice(0, range.startOffset);
            var match = before.match(/(^|[\s\u00A0])@([\p{L}\p{N}_.-]*)$/u);

            if (!match) {
                return null;
            }

            var start = range.startOffset - match[2].length - 1;
            var tokenRange = document.createRange();

            tokenRange.setStart(node, start);
            tokenRange.setEnd(node, range.startOffset);

            return { range: tokenRange, query: match[2].toLowerCase() };
        }

        function renderMentionSuggestions() {
            $mentionList.empty();

            mentionMatches.forEach(function (item, index) {
                var $option = $('<button type="button" class="chat-mention-option">')
                    .attr('role', 'option')
                    .attr('data-index', index)
                    .attr('aria-selected', index === mentionActive ? 'true' : 'false')
                    .addClass(index === mentionActive ? 'is-active' : '')
                    .addClass('is-' + (item.kind || 'member'));

                $('<span class="chat-mention-option__token">').text('@' + (item.text || item.token)).appendTo($option);
                $('<span class="chat-mention-option__name">').text(item.name || '').appendTo($option);
                $('<span class="chat-mention-option__label">')
                    .addClass('is-' + (item.kind || 'member'))
                    .text(item.label || '')
                    .appendTo($option);

                $option.appendTo($mentionList);
            });
        }

        function updateMentionSuggestions() {
            if (!currentId || !$mentionList.length) {
                return;
            }

            var context = mentionContext();

            if (!context) {
                hideMentionSuggestions();
                return;
            }

            var query = context.query;
            var matches = [];

            Object.keys(mentionIndex).forEach(function (token) {
                var item = mentionIndex[token];
                var name = String(item.name || '').toLowerCase();

                if (query === '' || token.indexOf(query) !== -1 || name.indexOf(query) !== -1) {
                    matches.push(item);
                }
            });

            matches.sort(function (first, second) {
                var firstStarts = String(first.token).indexOf(query) === 0 ? 0 : 1;
                var secondStarts = String(second.token).indexOf(query) === 0 ? 0 : 1;

                if (firstStarts !== secondStarts) {
                    return firstStarts - secondStarts;
                }

                return String(first.name || '').localeCompare(String(second.name || ''), 'es');
            });

            matches = matches.slice(0, 8);

            if (!matches.length) {
                hideMentionSuggestions();
                return;
            }

            mentionMatches = matches;
            mentionActive = 0;
            mentionRange = context.range;

            renderMentionSuggestions();
            $mentionList.prop('hidden', false);
        }

        function chooseMention(index) {
            var item = mentionMatches[index];
            var range = mentionRange;

            if (!item || !range) {
                hideMentionSuggestions();
                return;
            }

            range.deleteContents();

            var node = document.createTextNode('@' + (item.text || item.token) + ' ');
            range.insertNode(node);

            var after = document.createRange();
            after.setStart(node, node.nodeValue.length);
            after.collapse(true);

            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(after);

            hideMentionSuggestions();
            $('#chat-input').trigger('focus');
        }

        /**
         * Atiende las teclas de la lista de etiquetas. Devuelve true si la
         * consumió, para que no se envíe el mensaje con Enter.
         */
        function mentionKeydown(event) {
            if ($mentionList.prop('hidden') || !mentionMatches.length) {
                return false;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                var step = event.key === 'ArrowDown' ? 1 : mentionMatches.length - 1;
                mentionActive = (mentionActive + step) % mentionMatches.length;
                renderMentionSuggestions();

                return true;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                chooseMention(mentionActive);

                return true;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                hideMentionSuggestions();

                return true;
            }

            return false;
        }

        $mentionList.on('mousedown', '.chat-mention-option', function (event) {
            event.preventDefault();
            chooseMention(parseInt($(this).attr('data-index'), 10));
        });

        $('#chat-input').on('input', function () {
            updateMentionSuggestions();
        });

        $('#chat-input').on('blur', function () {
            window.setTimeout(hideMentionSuggestions, 150);
        });

        /* ------------------------------------------------------------------
           Contador del botón flotante
           ------------------------------------------------------------------ */

        function renderModerationCount(count) {
            var $count = $('#chat-moderation-count');

            if (!$count.length) {
                return;
            }

            if (count > 0) {
                $count.prop('hidden', false).text(count > 99 ? '+99' : count);
            } else {
                $count.prop('hidden', true).text('0');
            }
        }

        function pollCounter() {
            if (!contadorUrl || !canPoll() || isPanelOpen()) {
                return;
            }

            $.getJSON(contadorUrl).done(function (response) {
                lastUnread = response.unread || 0;
                setBadge(lastUnread);
                renderModerationCount(response.moderation || 0);
            });
        }

        function startCounterPolling() {
            window.clearInterval(counterTimer);
            counterTimer = window.setInterval(pollCounter, 10000);
        }

        var isTouchDevice = ('ontouchstart' in window || navigator.maxTouchPoints > 0) &&
            window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

        $('#chat-input').on('keydown', function (event) {
            if (mentionKeydown(event)) {
                return;
            }

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

        document.addEventListener('chat:open', function (event) {
            var id = parseInt(event.detail && event.detail.id, 10);

            if (!id) {
                return;
            }

            pendingOpenId = id;
            pendingFromUrl = false;
            $('#chat-panel').prop('hidden', false);
            $('#chat-toggle').attr('aria-expanded', 'true');
            startSummaryPolling();
            refresh();
        });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopChatPolling();

                return;
            }

            // Al volver a la pestaña se sincroniza al instante y luego se
            // retoma el ciclo normal.
            syncNow();
        });

        window.addEventListener('focus', syncNow);

        window.addEventListener('online', function () {
            syncNow();
        });

        window.addEventListener('offline', function () {
            stopChatPolling();
        });

        if (pendingOpenId) {
            $('#chat-panel').prop('hidden', false);
            $('#chat-toggle').attr('aria-expanded', 'true');
            startSummaryPolling();
        }

        function manageMembers(conversationId) {
            $.getJSON(chatBase + '/grupo/' + conversationId + '/miembros').done(function (response) {
                var items = (response && response.items) || [];
                var options = items
                    .filter(function (item) { return !item.is_owner; })
                    .map(function (item) {
                        var notes = [];

                        if (item.suspended_until) {
                            notes.push('suspendido hasta ' + item.suspended_until);
                        }

                        if (item.banned) {
                            notes.push('vetado');
                        }

                        return '<option value="' + item.id + '">' + escape(item.name) +
                            (notes.length ? ' (' + escape(notes.join(', ')) + ')' : '') + '</option>';
                    })
                    .join('');

                if (!options) {
                    alertDialog('No hay otros miembros para gestionar.');
                    return;
                }

                if (!window.bootbox) {
                    return;
                }

                window.bootbox.dialog({
                    title: 'Gestionar miembros',
                    centerVertical: true,
                    message:
                        '<div class="mb-2"><label class="form-label" for="chat-member-select">Miembro</label>' +
                        '<select class="form-select" id="chat-member-select">' + options + '</select></div>' +
                        '<div class="mb-2"><label class="form-label" for="chat-member-days">Días de suspensión</label>' +
                        '<input type="number" class="form-control" id="chat-member-days" value="1" min="1" max="365"></div>',
                    buttons: {
                        cancel: { label: 'Cerrar', className: 'btn-outline-secondary' },
                        suspend: {
                            label: 'Suspender',
                            className: 'btn-warning',
                            callback: function () {
                                post(chatBase + '/grupo/' + conversationId + '/suspender/' + $('#chat-member-select').val(), {
                                    days: $('#chat-member-days').val()
                                }).done(function (dialogResponse) {
                                    alertDialog(dialogResponse.message || 'Usuario suspendido.');
                                    refresh();
                                }).fail(function (xhr) {
                                    alertDialog((xhr.responseJSON || {}).message || 'No se pudo suspender.');
                                });
                            }
                        },
                        ban: {
                            label: 'Banear',
                            className: 'btn-danger',
                            callback: function () {
                                post(chatBase + '/grupo/' + conversationId + '/banear/' + $('#chat-member-select').val(), {})
                                    .done(function (dialogResponse) {
                                        alertDialog(dialogResponse.message || 'Usuario vetado.');
                                        refresh();
                                    })
                                    .fail(function (xhr) {
                                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo banear.');
                                    });
                            }
                        },
                        unban: {
                            label: 'Desbanear',
                            className: 'btn-outline-secondary',
                            callback: function () {
                                remove(chatBase + '/grupo/' + conversationId + '/banear/' + $('#chat-member-select').val(), {})
                                    .done(function (dialogResponse) {
                                        alertDialog(dialogResponse.message || 'Veto retirado.');
                                        refresh();
                                    })
                                    .fail(function (xhr) {
                                        alertDialog((xhr.responseJSON || {}).message || 'No se pudo desbanear.');
                                    });
                            }
                        }
                    }
                });
            }).fail(function (xhr) {
                alertDialog((xhr.responseJSON || {}).message || 'No se pudieron cargar los miembros.');
            });
        }

        refresh();
        setBadge(0);

        // El contador se consulta aunque el panel esté cerrado: así el botón
        // flotante muestra los mensajes nuevos apenas llegan.
        pollCounter();
        startCounterPolling();
    });
})(window.jQuery);
