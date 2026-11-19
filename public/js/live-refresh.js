/**
 * Refresco vivo y no invasivo del sistema.
 *
 * - Añade un parámetro anticaché a todas las peticiones GET del mismo origen,
 *   tanto de jQuery como de fetch.
 * - Refresca el menú lateral cuando cambia en el servidor, sin recargar.
 * - No toca formularios, modales, offcanvas abiertos ni la pestaña oculta.
 *
 * El chat, las notificaciones y las DataTables mantienen sus propios sondeos;
 * este archivo centraliza el anticaché y el menú.
 */
(function (window, $) {
    'use strict';

    function sameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function hasCacheBuster(url) {
        return /[?&](_|_ts|_cache)=/.test(String(url));
    }

    function withCacheBuster(url) {
        try {
            var parsed = new URL(url, window.location.href);
            parsed.searchParams.set('_', Date.now());
            return parsed.toString();
        } catch (error) {
            return url;
        }
    }

    if ($ && $.ajaxPrefilter) {
        $.ajaxPrefilter(function (options) {
            var method = String(options.type || 'GET').toUpperCase();

            if (method !== 'GET' || options.cache === false || !options.url) {
                return;
            }

            if (!sameOrigin(options.url) || hasCacheBuster(options.url)) {
                return;
            }

            options.url = withCacheBuster(options.url);
        });
    }

    var nativeFetch = window.fetch;

    if (typeof nativeFetch === 'function') {
        window.fetch = function (input, init) {
            try {
                var isUrlObject = typeof URL !== 'undefined' && input instanceof URL;
                var rawUrl = typeof input === 'string' ? input : (isUrlObject ? input.toString() : null);

                if (rawUrl !== null) {
                    var method = String((init && init.method) || 'GET').toUpperCase();

                    if (method === 'GET' && sameOrigin(rawUrl) && !hasCacheBuster(rawUrl)) {
                        input = withCacheBuster(rawUrl);
                    }
                }
            } catch (error) {
                // Si no se puede interpretar la URL, se deja la petición intacta.
            }

            return nativeFetch.call(this, input, init);
        };
    }

    var paused = false;

    function menuRefreshBlocked() {
        if (paused || document.hidden) {
            return true;
        }

        return Boolean(document.querySelector('.modal.show, .bootbox, .offcanvas.show'));
    }

    function startMenuRefresh() {
        var $body = $('body');
        var menuUrl = String($body.attr('data-live-menu-url') || '');
        var $menu = $('#main-menu');

        if (!menuUrl || !$menu.length) {
            return;
        }

        var signature = String($menu.attr('data-menu-signature') || '');
        var busy = false;

        function refresh() {
            if (busy || menuRefreshBlocked()) {
                return;
            }

            var body = document.querySelector('#main-menu .offcanvas-body');

            if (!body) {
                return;
            }

            busy = true;

            $.getJSON(menuUrl)
                .done(function (response) {
                    if (!response || !response.html || !response.signature) {
                        return;
                    }

                    if (String(response.signature) === signature) {
                        return;
                    }

                    // Si el usuario abrió el menú mientras llegaba la respuesta,
                    // se espera al siguiente ciclo para no interrumpirlo.
                    if (menuRefreshBlocked()) {
                        return;
                    }

                    signature = String(response.signature);
                    $menu.attr('data-menu-signature', signature);

                    var scroll = body.scrollTop;
                    body.innerHTML = response.html;
                    body.scrollTop = scroll;

                    document.dispatchEvent(new CustomEvent('live:menu-updated'));
                })
                .always(function () {
                    busy = false;
                });
        }

        window.setTimeout(refresh, 12000);
        window.setInterval(refresh, 30000);
        document.addEventListener('live:refresh-request', refresh);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refresh();
            }
        });
    }

    $(startMenuRefresh);

    function startRateRefresh() {
        var rateUrl = String($('body').attr('data-live-rate-url') || '');

        if (!rateUrl) {
            return;
        }

        var lastRate = null;
        var busy = false;
        var formatter = new Intl.NumberFormat('es-VE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        function applyRate(rate) {
            if (!isFinite(rate) || rate <= 0) {
                return;
            }

            if (lastRate !== null && Math.abs(rate - lastRate) < 0.000001) {
                return;
            }

            lastRate = rate;

            var formatted = formatter.format(rate);

            document.querySelectorAll('[data-bcv-rate]').forEach(function (element) {
                element.textContent = formatted;
            });

            document.querySelectorAll('[data-bcv-usd]').forEach(function (element) {
                var usd = parseFloat(element.getAttribute('data-bcv-usd'));

                if (!isFinite(usd) || usd <= 0) {
                    return;
                }

                element.textContent = 'Bs. ' + formatter.format(usd * rate);
            });

            document.dispatchEvent(new CustomEvent('bcv:rate', {
                detail: { rate: rate, formatted: formatted }
            }));
        }

        function refresh() {
            if (busy || document.hidden) {
                return;
            }

            busy = true;

            $.getJSON(rateUrl)
                .done(function (response) {
                    var rate = response ? Number(response.rate) : NaN;

                    if (isFinite(rate) && rate > 0) {
                        applyRate(rate);
                    }
                })
                .always(function () {
                    busy = false;
                });
        }

        window.setTimeout(refresh, 5000);
        window.setInterval(refresh, 60000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refresh();
            }
        });

        window.LiveRate = {
            refresh: refresh,
            apply: applyRate
        };
    }

    $(startRateRefresh);

    window.LiveRefresh = {
        pause: function () {
            paused = true;
        },
        resume: function () {
            paused = false;
        },
        menu: function () {
            // El sondeo normal se encarga; esta función fuerza un ciclo pronto.
            window.dispatchEvent(new CustomEvent('live:refresh-request'));
        }
    };
})(window, window.jQuery);
