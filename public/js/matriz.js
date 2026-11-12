/**
 * Matriz de perfiles: DataTable agrupado por tipo de módulo, búsqueda,
 * filtros, asignación/revocación preparada y aplicación confirmada.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    $(function () {
        var $config = $('#matriz-config');

        if (!$config.length) {
            return;
        }

        var datosUrl = $config.attr('data-url-datos');
        var aplicarUrl = $config.attr('data-url-aplicar');
        var indexUrl = $config.attr('data-url-index');
        var token = $('#matriz-csrf').val();
        var $role = $('#matriz-role');
        var $search = $('#matriz-search');
        var $filterType = $('#matriz-filter-type');
        var $filterPermission = $('#matriz-filter-permission');
        var $filterPermissionWrap = $('#matriz-filter-permission-wrap');
        var $status = $('#matriz-status');
        var $apply = $('#matriz-apply');
        var currentProfile = $role.val();

        var modules = [];
        var staging = {};
        var table = null;
        var canEdit = false;
        var filterType = 'all';
        var filterPermission = null;
        var lastSignature = null;
        var refreshing = false;

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setStatus(message, kind) {
            $status.attr('data-kind', kind || '').text(message || '');
        }

        function showError(xhr) {
            var response = xhr.responseJSON || {};
            var message = response.message;

            if (!message && response.errors) {
                var first = Object.keys(response.errors)[0];
                message = response.errors[first][0];
            }

            message = message || 'No se pudo completar la operación.';

            if (window.bootbox) {
                window.bootbox.alert({ title: 'Error', message: message, centerVertical: true });
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

        function initStaging(response) {
            modules = response.modules || [];
            staging = {};

            modules.forEach(function (module) {
                var set = new Set();

                (module.permissions || []).forEach(function (permission) {
                    if (permission.granted) {
                        set.add(permission.id);
                    }
                });

                staging[module.id] = set;
                module.search_text = [module.name, module.group]
                    .concat((module.permissions || []).map(function (permission) {
                        return permission.name;
                    }))
                    .join(' ');
            });
        }

        function basePermission(module) {
            var permissions = module.permissions || [];

            return permissions.find(function (permission) {
                return permission.slug === 'view';
            }) || permissions[0] || null;
        }

        function renderModule(module) {
            return '<div class="matriz-module">'
                + '<span class="module-icon matriz-module__icon"><i class="fa-solid '
                + escapeHtml(module.icon) + '" aria-hidden="true"></i></span>'
                + '<div><span class="matriz-module__name">' + escapeHtml(module.name) + '</span>'
                + '<span class="matriz-module__group">' + escapeHtml(module.group) + '</span></div>'
                + '</div>';
        }

        function renderAssociated(module) {
            var set = staging[module.id] || new Set();
            var checked = set.size > 0;

            return '<label class="matriz-associated-label"><input type="checkbox"'
                + ' class="form-check-input matriz-associated" data-module-id="' + module.id + '"'
                + (checked ? ' checked' : '')
                + (canEdit ? '' : ' disabled')
                + ' aria-label="Asociar ' + escapeHtml(module.name) + ' al rol"></label>';
        }

        function renderPermissions(module) {
            var set = staging[module.id] || new Set();
            var permissions = module.permissions || [];

            if (!permissions.length) {
                return '<span class="matriz-no-permissions">Sin permisos disponibles</span>';
            }

            return '<div class="matriz-permissions">' + permissions.map(function (permission) {
                var checked = set.has(permission.id);

                return '<label class="matriz-permission"><input type="checkbox"'
                    + ' class="form-check-input matriz-permission-check"'
                    + ' data-module-id="' + module.id + '"'
                    + ' data-permission-id="' + permission.id + '"'
                    + (checked ? ' checked' : '')
                    + (canEdit ? '' : ' disabled')
                    + ' aria-label="' + escapeHtml(permission.name) + ' para ' + escapeHtml(module.name) + '">'
                    + '<span>' + escapeHtml(permission.name) + '</span></label>';
            }).join('') + '</div>';
        }

        function buildPermissionFilter() {
            var map = {};

            modules.forEach(function (module) {
                (module.permissions || []).forEach(function (permission) {
                    map[permission.id] = permission.name;
                });
            });

            var options = '<option value="">Todos</option>';

            Object.keys(map)
                .sort(function (a, b) { return map[a].localeCompare(map[b]); })
                .forEach(function (id) {
                    options += '<option value="' + id + '">' + escapeHtml(map[id]) + '</option>';
                });

            $filterPermission.html(options);
        }

        function destroyTable() {
            if (table) {
                table.destroy();
                table = null;
            }
        }

        function initTable() {
            destroyTable();

            table = $('#matriz-table').DataTable({
                data: modules,
                autoWidth: true,
                pageLength: 10,
                lengthMenu: [[10, 25, 50], [10, 25, 50]],
                searching: true,
                ordering: true,
                order: [[0, 'asc'], [1, 'asc']],
                rowGroup: { dataSrc: 'group' },
                columns: [
                    { data: 'group', visible: false },
                    {
                        data: 'name',
                        render: function (data, type, row) {
                            return renderModule(row);
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'matriz-col-associated',
                        render: function (data, type, row) {
                            return renderAssociated(row);
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: function (data, type, row) {
                            return renderPermissions(row);
                        }
                    },
                    { data: 'search_text', visible: false, searchable: true }
                ],
                columnDefs: [
                    { orderable: false, targets: [0, 2, 3, 4] }
                ],
                dom: 'lrtip',
                language: {
                    processing: 'Cargando...',
                    lengthMenu: 'Mostrar _MENU_ módulos',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ módulos',
                    infoEmpty: 'Sin módulos',
                    infoFiltered: '(filtrado de _MAX_)',
                    zeroRecords: 'No se encontraron módulos',
                    emptyTable: 'No hay módulos activos',
                    paginate: {
                        first: 'Primera',
                        last: 'Última',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });
        }

        $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
            if (settings.nTable.id !== 'matriz-table') {
                return true;
            }

            if (filterType === 'assigned') {
                var set = staging[rowData.id];

                return !!set && set.size > 0;
            }

            if (filterType === 'permission') {
                if (!filterPermission) {
                    return true;
                }

                return (rowData.permissions || []).some(function (permission) {
                    return permission.id === filterPermission;
                });
            }

            return true;
        });

        function hasPendingChanges() {
            return modules.some(function (module) {
                var base = new Set((module.permissions || []).filter(function (permission) {
                    return permission.granted;
                }).map(function (permission) {
                    return permission.id;
                }));
                var current = staging[module.id] || new Set();

                if (base.size !== current.size) {
                    return true;
                }

                var changed = false;

                current.forEach(function (permissionId) {
                    if (!base.has(permissionId)) {
                        changed = true;
                    }
                });

                return changed;
            });
        }

        function loadMatrix(profileId, preserveState) {
            if (!profileId) {
                return;
            }

            var previousSearch = $search.val();
            var previousType = filterType;
            var previousPermission = filterPermission;

            setStatus('Cargando matriz...', 'info');

            $.getJSON(datosUrl, { profile_id: profileId })
                .done(function (response) {
                    currentProfile = profileId;
                    lastSignature = JSON.stringify(response);
                    canEdit = !!(response.can_grant && response.can_revoke);

                    initStaging(response);
                    buildPermissionFilter();

                    if (!preserveState) {
                        filterType = 'all';
                        filterPermission = null;
                        $filterType.val('all');
                        $filterPermission.val('');
                        $filterPermissionWrap.addClass('d-none');
                        $search.val('');
                    }

                    initTable();

                    if (preserveState) {
                        filterType = previousType;
                        filterPermission = previousPermission;
                        $filterType.val(previousType);
                        $filterPermission.val(previousPermission || '');
                        $filterPermissionWrap.toggleClass('d-none', previousType !== 'permission');
                        $search.val(previousSearch);
                        table.search(previousSearch);
                        table.draw(false);
                    }

                    $apply.prop('disabled', !canEdit);
                    setStatus('Matriz cargada.', 'info');
                })
                .fail(function (xhr) {
                    setStatus('', '');
                    showError(xhr);
                });
        }

        function refreshMatrixIfChanged() {            if (refreshing || !currentProfile || document.hidden) {
                return;
            }

            if ($.active > 0) {
                return;
            }

            if (document.querySelector('.modal.show, .bootbox')) {
                return;
            }

            var active = document.activeElement;

            if (active && (active.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName))) {
                return;
            }

            // No se toca la matriz mientras haya cambios sin aplicar.
            if (hasPendingChanges()) {
                return;
            }

            refreshing = true;

            $.getJSON(datosUrl, { profile_id: currentProfile })
                .done(function (response) {
                    if (JSON.stringify(response) !== lastSignature) {
                        loadMatrix(currentProfile, true);
                    }
                })
                .always(function () {
                    refreshing = false;
                });
        }

        $role.on('change', function () {
            currentProfile = $(this).val();
            loadMatrix(currentProfile);
        });

        $search.on('input', function () {
            if (table) {
                table.search(this.value).draw();
            }
        });

        $filterType.on('change', function () {
            filterType = this.value;

            var isPermission = filterType === 'permission';
            $filterPermissionWrap.toggleClass('d-none', !isPermission);

            if (!isPermission) {
                filterPermission = null;
                $filterPermission.val('');
            }

            if (table) {
                table.draw();
            }
        });

        $filterPermission.on('change', function () {
            filterPermission = this.value ? parseInt(this.value, 10) : null;

            if (table) {
                table.draw();
            }
        });

        $('#matriz-clear').on('click', function () {
            $search.val('');
            filterType = 'all';
            filterPermission = null;
            $filterType.val('all');
            $filterPermission.val('');
            $filterPermissionWrap.addClass('d-none');

            if (table) {
                table.search('').draw();
            }

            $search.trigger('focus');
        });

        $('#matriz-table').on('change', '.matriz-associated', function () {
            var $checkbox = $(this);
            var moduleId = parseInt($checkbox.data('module-id'), 10);
            var module = modules.find(function (item) {
                return item.id === moduleId;
            });

            if (!module) {
                return;
            }

            var set = staging[moduleId] || new Set();

            if ($checkbox.is(':checked')) {
                if (!set.size) {
                    var base = basePermission(module);

                    if (base) {
                        set.add(base.id);
                        $checkbox.closest('tr')
                            .find('.matriz-permission-check[data-permission-id="' + base.id + '"]')
                            .prop('checked', true);
                    }
                }
            } else {
                set.clear();
                $checkbox.closest('tr').find('.matriz-permission-check').prop('checked', false);
            }

            staging[moduleId] = set;

            if (table) {
                table.draw(false);
            }
        });

        $('#matriz-table').on('change', '.matriz-permission-check', function () {
            var $checkbox = $(this);
            var moduleId = parseInt($checkbox.data('module-id'), 10);
            var permissionId = parseInt($checkbox.data('permission-id'), 10);
            var set = staging[moduleId] || new Set();

            if ($checkbox.is(':checked')) {
                set.add(permissionId);
            } else {
                set.delete(permissionId);
            }

            staging[moduleId] = set;
            $checkbox.closest('tr').find('.matriz-associated').prop('checked', set.size > 0);

            if (table) {
                table.draw(false);
            }
        });

        $('#matriz-apply').on('click', function () {
            if (!canEdit) {
                return;
            }

            var state = Object.keys(staging).map(function (moduleId) {
                return {
                    module_id: parseInt(moduleId, 10),
                    permission_ids: Array.from(staging[moduleId])
                };
            });

            confirmDialog('¿Desea aplicar esta asignación/revocación de perfiles?', function () {
                $.ajax({
                    url: aplicarUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        _token: token,
                        profile_id: currentProfile,
                        modules: state
                    }
                }).done(function (response) {
                    window.location.assign(response.redirect || indexUrl);
                }).fail(showError);
            });
        });

        if (currentProfile) {
            loadMatrix(currentProfile);
        }

        window.setInterval(refreshMatrixIfChanged, 30000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshMatrixIfChanged();
            }
        });
    });
})(window.jQuery);
