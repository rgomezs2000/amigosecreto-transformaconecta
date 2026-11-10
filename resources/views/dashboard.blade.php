@extends('layouts.base')

@section('title', 'Panel de control · Amigo Secreto Inclusivo')

@if ($showNotifications)
    @push('head')
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    @endpush

    @push('vendor')
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    @endpush
@endif

@section('content')
<div class="dashboard-page" id="contenido">
    <header class="dashboard-top">
        <button class="btn dashboard-menu-btn"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#main-menu"
                aria-controls="main-menu"
                aria-label="Abrir menú">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>

        <div class="dashboard-heading">
            <p class="dashboard-eyebrow">{{ $event?->name ?? 'Amigo Secreto Inclusivo' }}</p>
            <h1 class="dashboard-title">Panel de control</h1>
        </div>

        @if ($showNotifications)
            <button class="btn dashboard-notifications-btn"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#notifications-panel"
                    aria-controls="notifications-panel"
                    aria-label="Notificaciones: {{ $notificationsCount }}">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <span class="dashboard-notifications-label">Notificaciones</span>
                <span class="dashboard-notifications-count">{{ $notificationsCount }}</span>
            </button>
        @endif
    </header>

    <div class="dashboard-meta">
        <div id="event-countdown"
             class="dashboard-countdown @if (! $event?->exchange_at) dashboard-countdown--empty @endif"
             data-exchange-at="{{ $event?->exchange_at?->toIso8601String() }}">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $event?->exchange_at ? 'Calculando…' : 'Sin fecha de intercambio' }}</span>
        </div>

        <p class="dashboard-clock" id="dashboard-clock" aria-live="polite"></p>
    </div>

    <div class="dashboard-welcome">
        <p class="dashboard-greeting">Hola: {{ $user->name }}</p>

        <p class="dashboard-rate">
            US$/Bs (BCV):
            <strong>
                @if ($dollarRate !== null)
                    {{ number_format((float) $dollarRate, 2, ',', '.') }}
                @else
                    No disponible
                @endif
            </strong>
        </p>
    </div>

    @include('partials.stepper', ['currentStep' => $currentStep])

    <div class="offcanvas offcanvas-start" tabindex="-1" id="main-menu" aria-labelledby="main-menu-label">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="main-menu-label">Menú</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            @include('partials.menu', ['groups' => $groups])
        </div>
    </div>

    @if ($showNotifications)
        <div class="offcanvas offcanvas-end" tabindex="-1" id="notifications-panel" aria-labelledby="notifications-title">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title" id="notifications-title">Notificaciones</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
            </div>
            <div class="offcanvas-body">
                <table id="notifications-table" class="table table-sm align-middle w-100">
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Título</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($notifications as $notification)
                            <tr>
                                <td data-order="{{ $notification->created_at->toIso8601String() }}">
                                    {{ $notification->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td>{{ $notification->data['title'] ?? 'Notificación' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <section class="dashboard-modules" aria-label="Módulos disponibles">
        <h2 class="dashboard-modules__title">Módulos disponibles</h2>

        @if ($modules->isEmpty())
            <p class="menu-empty">No tienes módulos asignados. Contacta al administrador.</p>
        @else
            <div class="module-panels">
                @foreach ($groups as $group => $groupModules)
                    @php($groupSlug = \Illuminate\Support\Str::slug($group))

                    <section class="module-panel-group module-panel-group--{{ $groupSlug }}"
                             aria-labelledby="module-group-{{ $groupSlug }}">
                        <h3 class="module-panel-group__title" id="module-group-{{ $groupSlug }}">
                            {{ $group }}
                        </h3>

                        <div class="module-grid">
                            @foreach ($groupModules as $module)
                                <a class="module-tile module-tile--{{ \Illuminate\Support\Str::slug($module->group) }}"
                                   href="{{ route('modules.show', $module) }}">
                                    <span class="module-tile__icon">
                                        <i class="fa-solid {{ $module->icon }}" aria-hidden="true"></i>
                                    </span>
                                    <span class="module-tile__name">{{ $module->name }}</span>
                                    <span class="module-tile__group">{{ $module->group }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection

@if ($showNotifications)
    @push('scripts')
        <script>
            (function ($) {
                'use strict';

                $(function () {
                    var $table = $('#notifications-table');

                    if (!$table.length || !$.fn.DataTable) {
                        return;
                    }

                    var table = $table.DataTable({
                        pageLength: 5,
                        lengthMenu: [5, 10, 25],
                        order: [[0, 'desc']],
                        language: {
                            emptyTable: 'No tienes notificaciones.',
                            info: 'Mostrando _START_ a _END_ de _TOTAL_',
                            infoEmpty: 'Sin notificaciones',
                            infoFiltered: '(filtrado de _MAX_)',
                            lengthMenu: 'Mostrar _MENU_',
                            search: 'Buscar:',
                            zeroRecords: 'No se encontraron notificaciones',
                            paginate: {
                                first: 'Primera',
                                last: 'Última',
                                next: 'Siguiente',
                                previous: 'Anterior'
                            }
                        }
                    });

                    var panel = document.getElementById('notifications-panel');

                    if (panel) {
                        panel.addEventListener('shown.bs.offcanvas', function () {
                            table.columns.adjust().draw();
                        });
                    }
                });
            })(window.jQuery);
        </script>
    @endpush
@endif
