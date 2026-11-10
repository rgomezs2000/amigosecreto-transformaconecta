@extends('layouts.base')

@section('title', 'Panel de control · Amigo Secreto Inclusivo')

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

        <h1 class="dashboard-title">Panel de control</h1>
    </header>

    <div class="dashboard-welcome">
        <p class="dashboard-greeting">Hola: {{ $user->name }}</p>
        <p class="dashboard-clock" id="dashboard-clock" aria-live="polite"></p>
    </div>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="main-menu" aria-labelledby="main-menu-label">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="main-menu-label">Menú</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            @include('partials.menu', ['groups' => $groups])
        </div>
    </div>

    <section class="dashboard-content" aria-label="Contenido del panel">
        <p>Selecciona un módulo del menú para comenzar.</p>
    </section>
</div>
@endsection
