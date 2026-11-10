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

        <div class="dashboard-heading">
            <p class="dashboard-eyebrow">{{ $event?->name ?? 'Amigo Secreto Inclusivo' }}</p>
            <h1 class="dashboard-title">Panel de control</h1>
        </div>
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

    <section class="dashboard-modules" aria-label="Módulos disponibles">
        <h2 class="dashboard-modules__title">Módulos disponibles</h2>

        @if ($modules->isEmpty())
            <p class="menu-empty">No tienes módulos asignados. Contacta al administrador.</p>
        @else
            <div class="module-grid">
                @foreach ($modules as $module)
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
        @endif
    </section>
</div>
@endsection
