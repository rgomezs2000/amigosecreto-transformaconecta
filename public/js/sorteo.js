/**
 * Sorteo ciego y «Ver tu amigo».
 *
 * Descubre al amigo secreto sin recargar y permite volver a verlo cuantas
 * veces se quiera (el reparto no cambia).
 */
(function ($) {
    'use strict';

    $(function () {
        var $sorteo = $('#sorteo-config');
        var $amigo = $('#amigo-config');

        if (!$sorteo.length && !$amigo.length) {
            return;
        }

        function cardHtml(amigo) {
            var html = '<article class="amigo-card">' +
                '<p class="amigo-card__eyebrow">Tu amigo secreto es</p>' +
                '<p class="amigo-card__name"></p>';

            if (amigo.username) {
                html += '<p class="amigo-card__user"></p>';
            }

            if (amigo.notas) {
                html += '<p class="amigo-card__notes"><span class="amigo-card__label">Preferencias</span> <span class="amigo-card__notes-text"></span></p>';
            }

            html += '</article>';

            var $html = $(html);

            $html.find('.amigo-card__name').text(amigo.name || '—');

            if (amigo.username) {
                $html.find('.amigo-card__user').text('@' + amigo.username);
            }

            if (amigo.notas) {
                $html.find('.amigo-card__notes-text').text(amigo.notas);
            }

            return $html;
        }

        function showError($target, message) {
            $target.text(message || 'No se pudo completar la acción.').removeAttr('hidden');
        }

        function clearError($target) {
            $target.text('').attr('hidden', true);
        }

        // ---------------------------------------------------------------- Sorteo
        if ($sorteo.length) {
            var descubrirUrl = $sorteo.attr('data-url-descubrir');
            var verUrl = $sorteo.attr('data-url-ver');
            var token = $sorteo.attr('data-csrf');

            var $error = $('#sorteo-error');
            var $abierto = $('#sorteo-abierto');
            var $cerrado = $('#sorteo-cerrado');
            var $revelado = $('#sorteo-amigo');
            var $tarjeta = $('#sorteo-amigo-tarjeta');
            var $descubrir = $('#sorteo-descubrir');

            function pintarAmigo(amigo) {
                $tarjeta.html(cardHtml(amigo));
                $abierto.attr('hidden', true);
                $revelado.removeAttr('hidden');
            }

            $descubrir.on('click', function () {
                var $button = $(this);

                clearError($error);
                $button.prop('disabled', true);

                $.ajax({
                    url: descubrirUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: { _token: token }
                }).done(function (response) {
                    if (response.amigo) {
                        pintarAmigo(response.amigo);
                    } else {
                        showError($error, response.message);
                    }

                    if (response.estado && !response.estado.abierto) {
                        $cerrado.text(response.estado.mensaje).removeAttr('hidden');
                    }
                }).fail(function (xhr) {
                    var data = xhr.responseJSON || {};

                    showError($error, data.message);
                    $button.prop('disabled', false);
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });

            $('#sorteo-ver-de-nuevo').on('click', function () {
                clearError($error);

                $.getJSON(verUrl).done(function (response) {
                    if (response.amigo) {
                        pintarAmigo(response.amigo);
                    }
                }).fail(function (xhr) {
                    var data = xhr.responseJSON || {};

                    showError($error, data.message);
                });
            });
        }

        // ------------------------------------------------------- Ver tu amigo
        if ($amigo.length) {
            var datosUrl = $amigo.attr('data-url-datos');
            var $errorAmigo = $('#amigo-error');
            var $tarjetaAmigo = $('#amigo-tarjeta');
            var $pistas = $('#amigo-clues');

            $('#amigo-ver-de-nuevo').on('click', function () {
                clearError($errorAmigo);

                $.getJSON(datosUrl).done(function (response) {
                    if (response.amigo) {
                        $tarjetaAmigo.html(cardHtml(response.amigo));
                    }

                    if (response.pistas) {
                        if (!response.pistas.length) {
                            $pistas.html('<li class="amigo-clue amigo-clue--empty">Todavía no te ha dejado pistas.</li>');
                        } else {
                            $pistas.html(response.pistas.map(function (pista) {
                                return '<li class="amigo-clue">' +
                                    '<p class="amigo-clue__body"></p>' +
                                    '<p class="amigo-clue__date"></p>' +
                                    '</li>';
                            }).join(''));

                            response.pistas.forEach(function (pista, index) {
                                var $item = $pistas.find('.amigo-clue').eq(index);

                                $item.find('.amigo-clue__body').text(pista.body || '');
                                $item.find('.amigo-clue__date').text(pista.fecha || '');
                            });
                        }
                    }
                }).fail(function (xhr) {
                    var data = xhr.responseJSON || {};

                    showError($errorAmigo, data.message);
                });
            });
        }
    });
})(jQuery);
