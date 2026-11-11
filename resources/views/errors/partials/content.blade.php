<main class="error-page">
    <p class="error-code">{{ $code }}</p>
    <h2 class="error-title">{{ $title }}</h2>
    <p class="error-text">{{ $message }}</p>

    <a class="btn btn-primary btn-lg" href="{{ isset($shell) ? route('dashboard') : route('login') }}">
        {{ isset($shell) ? 'Volver al panel' : 'Ir al acceso' }}
    </a>
</main>
