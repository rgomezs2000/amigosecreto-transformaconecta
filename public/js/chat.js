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
        var chatBase = $widget.attr('data-url-chat');
        var soundEnabled = $widget.attr('data-sound') === '1';
        var inAppEnabled = $widget.attr('data-in-app') === '1';
        var isAdmin = $widget.attr('data-is-admin') === '1';
        var token = $widget.attr('data-csrf');

        var currentId = null;
        var pollTimer = null;
        var messageTimer = null;
        var lastUnread = 0;
        var audioContext = null;
        var activeMessage = null;

        function escape(value) {
            return $('<div>').text(value == null ? '' : value).html();
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
                : '<p class="chat-message__body">' + escape(message.body) + '</p>';

            if (message.attachment_url && !message.deleted) {
                if (message.type === 'image') {
                    attachment = '<img class="chat-attachment__image" src="' + message.attachment_url + '" alt="Imagen adjunta">';
                } else if (message.type === 'video') {
                    attachment = '<video class="chat-attachment__video" controls src="' + message.attachment_url + '"></video>';
                } else if (message.type === 'audio') {
                    attachment = '<audio class="chat-attachment__audio" controls src="' + message.attachment_url + '"></audio>';
                } else {
                    attachment = '<a class="chat-attachment__file" href="' + message.attachment_url + '" target="_blank" rel="noopener">' +
                        escape(message.attachment_name || 'Archivo') + '</a>';
                }
            }

            var reactions = (message.reactions || []).map(function (reaction) {
                return '<button type="button" class="chat-reaction' + (reaction.mine ? ' is-mine' : '') +
                    '" data-reaction="' + escape(reaction.emoji) + '">' +
                    escape(reaction.emoji) + ' ' + reaction.count + '</button>';
            }).join('');

            return '<div class="chat-message' +
                (message.mine ? ' chat-message--mine' : '') +
                (message.is_moderation ? ' chat-message--moderation' : '') +
                (message.deleted ? ' chat-message--deleted' : '') + '"' +
                ' data-message-id="' + message.id + '"' +
                ' data-can-edit="' + (message.can_edit ? '1' : '0') + '"' +
                ' data-can-delete="' + (message.can_delete ? '1' : '0') + '"' +
                ' data-reported="' + (message.reported ? '1' : '0') + '">' +
                attachment + body +
                '<p class="chat-message__meta">' + escape(message.sender) + ' · ' + escape(message.created_at) +
                (message.edited ? ' · <span class="chat-message__edited">editado</span>' : '') + '</p>' +
                (reactions ? '<div class="chat-message__reactions">' + reactions + '</div>' : '') +
                '</div>';
        }

        function loadMessages() {
            if (!currentId) {
                return;
            }

            $.getJSON(chatBase + '/' + currentId + '/mensajes')
                .done(function (response) {
                    var html = (response.messages || []).map(messageHtml).join('');

                    $('#chat-messages').html(html || '<p class="chat-hint">Aún no hay mensajes.</p>');
                    $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
                })
                .fail(function () {
                    $('#chat-messages').html('<p class="chat-hint">No se pudieron cargar los mensajes.</p>');
                });
        }

        function openConversation(id, title, subtitle) {
            currentId = id;
            $('#chat-title').text(title || '');
            $('#chat-subtitle').text(subtitle || '');
            $('#chat-body').hide();
            $('#chat-conversation').prop('hidden', false);
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
                    ' data-subtitle="' + escape(item.is_group ? 'Grupo' : 'Conversación directa') + '">' +
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
                    ' data-title="' + escape(item.title) + '" data-subtitle="Moderación">' +
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
                })
                .fail(function () {
                    $('#chat-users').html('<p class="chat-hint">No se pudieron cargar los usuarios. Intenta de nuevo.</p>');
                });
        }

        function showList() {
            currentId = null;
            $('#chat-conversation').prop('hidden', true);
            $('#chat-body').show();
            window.clearInterval(messageTimer);
        }

        function hideContextMenu() {
            $('#chat-context-menu').prop('hidden', true).css({ top: '', left: '' });
            activeMessage = null;
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
                showList();
            }
        });

        $('#chat-close').on('click', function () {
            $('#chat-panel').prop('hidden', true);
            $('#chat-toggle').attr('aria-expanded', 'false');
            window.clearInterval(pollTimer);
            showList();
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

            hideContextMenu();

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
                $input.val(($input.val() || '') + emoji).trigger('focus');
                $('#chat-emoji-palette').prop('hidden', true);
            }
        });

        $('#chat-emoji-toggle').on('click', function (event) {
            event.stopPropagation();
            buildEmojiPalette({ type: 'input' });
        });

        $('#chat-attach').on('click', function () {
            $('#chat-file').trigger('click');
        });

        $('#chat-file').on('change', function () {
            var name = this.files && this.files[0] ? this.files[0].name : '';

            $('#chat-file-name').prop('hidden', !name).text(name ? 'Adjunto: ' + name : '');
        });

        $('#chat-form').on('submit', function (event) {
            event.preventDefault();

            var body = $.trim($('#chat-input').val());
            var file = $('#chat-file')[0].files[0] || null;

            if ((!body && !file) || !currentId) {
                return;
            }

            var data = new FormData();
            data.append('_token', token);

            if (body) {
                data.append('body', body);
            }

            if (file) {
                data.append('file', file);
            }

            $.ajax({
                url: chatBase + '/' + currentId + '/mensajes',
                method: 'POST',
                dataType: 'json',
                data: data,
                processData: false,
                contentType: false
            }).done(function () {
                $('#chat-input').val('');
                $('#chat-file').val('');
                $('#chat-file-name').prop('hidden', true);
                loadMessages();
                refresh();
            }).fail(function (xhr) {
                alertDialog((xhr.responseJSON || {}).message || 'No se pudo enviar el mensaje.');
            });
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

        refresh();
        setBadge(0);
    });
})(window.jQuery);
