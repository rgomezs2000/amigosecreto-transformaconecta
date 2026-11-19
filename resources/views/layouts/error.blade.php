<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#F9FAFB">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <title>@yield('title', 'Amigo Secreto Inclusivo')</title>

    {{-- Página de error autónoma: no depende de la sesión, la base de datos ni
         el cifrado. Así se puede mostrar incluso cuando la aplicación falla. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}" rel="stylesheet">
</head>
<body class="app-body error-body">
    <main class="error-standalone" id="contenido">
        <section class="error-shell" aria-label="Error del sistema">
            <header class="dashboard-top">
                <div class="dashboard-heading">
                    <p class="dashboard-eyebrow">Amigo Secreto Inclusivo</p>
                    <h1 class="dashboard-title">@yield('shell-title', 'Error')</h1>
                </div>
            </header>

            @yield('error')
        </section>
    </main>
</body>
</html>
