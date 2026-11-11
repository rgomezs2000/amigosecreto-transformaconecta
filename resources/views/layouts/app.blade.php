@extends('layouts.base')

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
            <p class="dashboard-eyebrow">@yield('shell-eyebrow', $shell['event']?->name ?? 'Amigo Secreto Inclusivo')</p>
            <h1 class="dashboard-title">@yield('shell-title', 'Panel de control')</h1>
        </div>

        @if ($shell['showNotifications'])
            <button class="btn dashboard-notifications-btn"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#notifications-panel"
                    aria-controls="notifications-panel"
                    aria-label="Notificaciones: {{ $shell['notificationsCount'] }}">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <span class="dashboard-notifications-label">Notificaciones</span>
                <span class="dashboard-notifications-count">{{ $shell['notificationsCount'] }}</span>
            </button>
        @endif
    </header>

    <div class="dashboard-meta">
        <div id="event-countdown"
             class="dashboard-countdown @if (! $shell['event']?->exchange_at) dashboard-countdown--empty @endif"
             data-exchange-at="{{ $shell['event']?->exchange_at?->toIso8601String() }}">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $shell['event']?->exchange_at ? 'Calculando…' : 'Sin fecha de intercambio' }}</span>
        </div>

        <p class="dashboard-clock" id="dashboard-clock" aria-live="polite"></p>
    </div>

    <div class="dashboard-welcome">
        <p class="dashboard-greeting">Hola: {{ $shell['user']->name }}</p>

        <p class="dashboard-rate">
            US$/Bs (BCV):
            <strong>{{ format_rate($shell['dollarRate']) }}</strong>
        </p>
    </div>

    @yield('page')

    <div class="offcanvas offcanvas-start" tabindex="-1" id="main-menu"
         data-bs-backdrop="true" data-bs-scroll="false"
         aria-labelledby="main-menu-label">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="main-menu-label">Menú</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            @include('partials.menu', ['groups' => $shell['menuGroups']])
        </div>
    </div>

    @if ($shell['showNotifications'])
        <div class="offcanvas offcanvas-end" tabindex="-1" id="notifications-panel"
             data-bs-backdrop="true" data-bs-scroll="false"
             aria-labelledby="notifications-title">
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
                        @foreach ($shell['notifications'] as $notification)
                            <tr>
                                <td data-order="{{ $notification->created_at->toIso8601String() }}">
                                    {{ format_date($notification->created_at) }}
                                </td>
                                <td>{{ $notification->data['title'] ?? 'Notificación' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection

@if ($shell['showNotifications'])
    @push('head')
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    @endpush

    @push('vendor')
        <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    @endpush

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
