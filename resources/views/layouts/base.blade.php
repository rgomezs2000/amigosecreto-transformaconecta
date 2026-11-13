<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#F9FAFB">
    <title>@yield('title', 'Amigo Secreto Inclusivo')</title>

    {{-- Librerías por CDN: sin NPM, sin Vite, sin compilación de assets. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}" rel="stylesheet">

    @stack('head')
</head>
<body class="app-body">
    <a class="skip-link" href="#contenido">Saltar al contenido principal</a>

    @yield('content')

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootbox@6.0.4/dist/bootbox.min.js"></script>
    <script src="{{ asset('js/functions.js') }}?v={{ filemtime(public_path('js/functions.js')) }}"></script>
    <script src="{{ asset('js/scripts.js') }}?v={{ filemtime(public_path('js/scripts.js')) }}"></script>

    {{-- DataTables y otras librerías de tabla se cargan solo en las vistas que
         las usan, con @push('vendor') desde esa vista. --}}
    @stack('vendor')

    {{-- DataTables Responsive: en móvil/tablet oculta columnas y las muestra
         dentro de la fila expandida, sin ensanchar la página. Solo se carga
         cuando la página ya cargó DataTables. --}}
    <script>
        (function () {
            if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable) {
                return;
            }

            document.write('<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">');
            document.write('<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"><\/script>');
            document.write('<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"><\/script>');
        })();
    </script>
    <script>
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
            window.jQuery.extend(true, window.jQuery.fn.dataTable.defaults, {
                responsive: true
            });
        }
    </script>

    @stack('scripts')

    {{-- Refresco en reposo de las tablas: nunca interrumpe formularios,
         modales ni la interacción del usuario. --}}
    <script src="{{ asset('js/live-tables.js') }}?v={{ filemtime(public_path('js/live-tables.js')) }}"></script>
</body>
</html>
