@extends('layouts.base')

@section('title', 'Panel principal · Amigo Secreto Inclusivo')

@section('content')
<main id="contenido" class="login-page">
    <div class="login-shell">

        <header class="login-header">
            <p class="login-eyebrow">Amigo Secreto Inclusivo</p>
            <h1 class="login-title">Panel principal</h1>
            <p class="login-subtitle">Sesión iniciada como {{ auth()->user()->name }}.</p>
        </header>

        <section class="login-card" aria-labelledby="panel-title">
            <h2 id="panel-title" class="section-title">Panel en construcción</h2>
            <p>El panel del evento se conecta en la siguiente fase.</p>
        </section>

        <footer class="login-footer">
            <p>Amigo Secreto Inclusivo · {{ now()->year }}</p>
        </footer>
    </div>
</main>
@endsection
