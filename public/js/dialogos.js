/**
 * Diálogos globales del sistema.
 *
 * Única capa sobre Bootbox: avisos, errores, éxitos, confirmaciones y
 * solicitudes de texto. Los módulos deben usar AppDialog en lugar de
 * llamar a window.bootbox directamente.
 *
 * Depende de Bootbox y jQuery, que se cargan antes en el layout base.
 */
(function (window) {
    'use strict';

    function bootboxAvailable() {
        return Boolean(window.bootbox);
    }

    function merge(base, extra) {
        var result = {};
        var key;

        base = base || {};
        extra = extra || {};

        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                result[key] = base[key];
            }
        }

        for (key in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
                result[key] = extra[key];
            }
        }

        return result;
    }

    function defaultTitle(type) {
        if (type === 'error') {
            return 'Error';
        }

        if (type === 'success') {
            return 'Listo';
        }

        if (type === 'warning') {
            return 'Atención';
        }

        return 'Aviso';
    }

    function alertDialog(message, options) {
        options = options || {};

        var title = options.title || defaultTitle(options.type);

        if (!bootboxAvailable()) {
            window.alert(message);

            if (typeof options.callback === 'function') {
                options.callback();
            }

            return;
        }

        window.bootbox.alert({
            title: title,
            message: message,
            centerVertical: true,
            size: options.size || null,
            buttons: options.buttons || undefined,
            className: options.className || undefined,
            callback: typeof options.callback === 'function' ? options.callback : undefined
        });
    }

    function confirmDialog(message, onConfirm, options) {
        options = options || {};

        var title = options.title || 'Confirmar';

        if (!bootboxAvailable()) {
            if (window.confirm(message)) {
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            } else if (typeof options.onCancel === 'function') {
                options.onCancel();
            }

            return;
        }

        window.bootbox.confirm({
            title: title,
            message: message,
            centerVertical: true,
            size: options.size || null,
            buttons: {
                cancel: {
                    label: options.cancelLabel || 'Cancelar',
                    className: options.cancelClass || 'btn-outline-secondary'
                },
                confirm: {
                    label: options.confirmLabel || 'Aceptar',
                    className: options.confirmClass || (options.danger ? 'btn-danger' : 'btn-primary')
                }
            },
            callback: function (ok) {
                if (ok) {
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                } else if (typeof options.onCancel === 'function') {
                    options.onCancel();
                }
            }
        });
    }

    function promptDialog(message, onConfirm, options) {
        options = options || {};

        if (!bootboxAvailable()) {
            var fallback = window.prompt(message, options.value || '');

            if (fallback !== null && typeof onConfirm === 'function') {
                onConfirm(fallback);
            }

            return;
        }

        window.bootbox.prompt({
            title: options.title || 'Escribir',
            message: message,
            value: options.value || '',
            centerVertical: true,
            size: options.size || null,
            inputType: options.inputType || 'text',
            placeholder: options.placeholder || '',
            required: Boolean(options.required),
            maxlength: options.maxlength || null,
            buttons: {
                cancel: {
                    label: options.cancelLabel || 'Cancelar',
                    className: options.cancelClass || 'btn-outline-secondary'
                },
                confirm: {
                    label: options.confirmLabel || 'Aceptar',
                    className: options.confirmClass || 'btn-primary'
                }
            },
            callback: function (value) {
                if (value !== null && typeof onConfirm === 'function') {
                    onConfirm(value);
                }
            }
        });
    }

    function customDialog(options) {
        if (!bootboxAvailable()) {
            if (options && options.message) {
                window.alert(options.message);
            }

            return;
        }

        return window.bootbox.dialog(options);
    }

    var AppDialog = {
        alert: alertDialog,
        info: function (message, options) {
            return alertDialog(message, merge(options, { type: 'info' }));
        },
        success: function (message, options) {
            return alertDialog(message, merge(options, { type: 'success' }));
        },
        error: function (message, options) {
            return alertDialog(message, merge(options, { type: 'error' }));
        },
        warning: function (message, options) {
            return alertDialog(message, merge(options, { type: 'warning' }));
        },
        confirm: confirmDialog,
        prompt: promptDialog,
        custom: customDialog
    };

    window.AppDialog = AppDialog;

    // Alias para pantallas antiguas que invocan estos nombres globales.
    window.confirmDialog = function (message, onConfirm, options) {
        return AppDialog.confirm(message, onConfirm, options);
    };

    window.alertDialog = function (message, options) {
        return AppDialog.alert(message, options);
    };

    window.errorDialog = function (message, options) {
        return AppDialog.error(message, options);
    };

    window.successDialog = function (message, options) {
        return AppDialog.success(message, options);
    };

    // Alias de compatibilidad para scripts antiguos que usan estas firmas.
    window.dialog = function (message, title, callback) {
        return AppDialog.alert(message, { title: title, callback: callback });
    };

    window.confirmAction = function (message, title, onConfirm) {
        return AppDialog.confirm(message, onConfirm, { title: title });
    };

    // Chrome bloquea/avisa cuando un modal se oculta con el foco todavía en un
    // botón interno y Bootstrap le pone aria-hidden. Se retira el foco antes
    // de que Bootstrap oculte el diálogo.
    function releaseBootboxFocus(modal) {
        if (!modal || !modal.classList || !modal.classList.contains('bootbox')) {
            return;
        }

        var active = document.activeElement;

        if (active && modal.contains(active) && typeof active.blur === 'function') {
            active.blur();
        }
    }

    document.addEventListener('hide.bs.modal', function (event) {
        releaseBootboxFocus(event.target);
    }, true);

    if (window.jQuery) {
        window.jQuery(document).on('hide.bs.modal', '.bootbox', function () {
            releaseBootboxFocus(this);
        });
    }
})(window);
