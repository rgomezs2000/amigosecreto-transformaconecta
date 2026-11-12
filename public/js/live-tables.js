/**
 * Actualización no invasiva de tablas DataTables del sistema.
 *
 * Reglas:
 *  - Solo tablas con datos en servidor (serverSide).
 *  - Nunca mientras haya un modal, un diálogo, un campo enfocado o la
 *    pestaña oculta.
 *  - Solo tras unos segundos sin interacción del usuario (modo reposo).
 *  - Conserva la página, el orden, la búsqueda y las filas seleccionadas.
 *  - Una tabla con data-live="off" o data-live-dirty="1" se deja quieta.
 *
 * Expone window.LiveTables para que un módulo pueda pausar/reanudar el
 * refresco mientras hace una operación delicada.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.dataTable) {
        return;
    }

    var INTERVAL = 30000;
    var IDLE = 8000;
    var paused = false;
    var running = false;
    var lastInteraction = Date.now();

    function touch() {
        lastInteraction = Date.now();
    }

    ['pointerdown', 'keydown', 'input', 'change'].forEach(function (eventName) {
        document.addEventListener(eventName, touch, true);
    });

    function isBlocked() {
        if (paused || document.hidden || navigator.onLine === false) {
            return true;
        }

        // Hay una operación AJAX en curso (guardar, eliminar, aplicar...).
        if ($.active > 0) {
            return true;
        }

        if (document.querySelector('.modal.show, .bootbox, .offcanvas.show')) {
            return true;
        }

        var active = document.activeElement;

        if (active && (active.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName))) {
            return true;
        }

        return Date.now() - lastInteraction < IDLE;
    }

    function isVisible(api) {
        var node = api.table().node();

        return !!(node && node.getClientRects && node.getClientRects().length);
    }

    function reload(api) {
        var node = api.table().node();

        if (!node) {
            return;
        }

        if (node.getAttribute('data-live') === 'off' || node.getAttribute('data-live-dirty') === '1') {
            return;
        }

        if (!isVisible(api)) {
            return;
        }

        var $container = $(api.table().container());

        if ($container.find('.dt-processing:visible, .dataTables_processing:visible').length) {
            return;
        }

        var checked = [];

        $(node).find('tbody input[type="checkbox"]:checked').each(function () {
            checked.push(String(this.value));
        });

        // El refresco automático no debe mostrar "Procesando...".
        var settings = api.settings()[0];
        var hadProcessing = settings && settings.oFeatures ? settings.oFeatures.bProcessing : null;

        if (settings && settings.oFeatures) {
            settings.oFeatures.bProcessing = false;
        }

        api.ajax.reload(function () {
            if (settings && settings.oFeatures && hadProcessing !== null) {
                settings.oFeatures.bProcessing = hadProcessing;
            }

            if (!checked.length || !node.isConnected) {
                return;
            }

            $(node).find('tbody input[type="checkbox"]').each(function () {
                if (checked.indexOf(String(this.value)) !== -1) {
                    this.checked = true;
                }
            });
        }, false);
    }

    function cycle() {
        if (running || isBlocked()) {
            return;
        }

        var apis = [];

        try {
            $.fn.dataTable.tables({ visible: true, api: true }).each(function () {
                var info = this.page && this.page.info ? this.page.info() : null;

                if (info && info.serverSide && this.ajax && typeof this.ajax.reload === 'function') {
                    apis.push(this);
                }
            });
        } catch (error) {
            return;
        }

        if (!apis.length) {
            return;
        }

        running = true;

        apis.forEach(function (api, index) {
            window.setTimeout(function () {
                try {
                    reload(api);
                } catch (error) {
                    // Una tabla destruida entre ciclos no debe romper el resto.
                }

                if (index === apis.length - 1) {
                    window.setTimeout(function () {
                        running = false;
                    }, 1200);
                }
            }, index * 500);
        });
    }

    window.LiveTables = {
        pause: function () {
            paused = true;
        },
        resume: function () {
            paused = false;
        },
        touch: touch,
        refreshNow: function () {
            lastInteraction = 0;
            cycle();
        }
    };

    window.setTimeout(function () {
        cycle();
        window.setInterval(cycle, INTERVAL);
    }, 10000);
})(window.jQuery);
