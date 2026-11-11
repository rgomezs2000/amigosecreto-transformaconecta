@extends('layouts.app')

@php($moduleGroup = $module->category?->name ?? $module->group)
@php($groupClass = \Illuminate\Support\Str::slug($moduleGroup))

@section('title', $module->name . ' · Amigo Secreto Inclusivo')
@section('shell-eyebrow', $moduleGroup)
@section('shell-title', $module->name)

@section('page')
    <a class="module-back" href="{{ route('dashboard') }}">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        <span>Volver al panel</span>
    </a>

    <div class="module-layout">
        <article class="module-card">
            <span class="module-icon module-icon--{{ $groupClass }}">
                <i class="fa-solid {{ $module->icon }}" aria-hidden="true"></i>
            </span>

            <p class="module-subtitle">Este módulo está en construcción.</p>
            <p class="module-note">Mientras tanto, puedes volver al panel.</p>

            <div class="module-actions">
                <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">
                    Volver al panel
                </a>
            </div>
        </article>

        <aside class="module-panel" aria-label="Contenido del módulo">
            <p class="module-eyebrow">Contenido</p>
            <h2 class="module-panel__title">Disponible en la siguiente fase</h2>
            <p class="module-note">
                La estructura visual ya está lista. Aquí se conectará el contenido real de
                «{{ $module->name }}» sin cambiar el diseño del sistema.
            </p>
        </aside>
    </div>
@endsection
