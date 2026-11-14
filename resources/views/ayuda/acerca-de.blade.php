@extends('layouts.app')

@section('title', 'Acerca de · ' . $settings['name'])
@section('shell-title', 'Acerca de')
@section('shell-eyebrow', $module->category?->name ?? 'Ayuda')

@section('page')
    <a class="module-back" href="{{ route('dashboard') }}">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        <span>Volver al panel</span>
    </a>

    <div class="help-page">
        <header class="help-hero">
            <span class="help-hero__icon" aria-hidden="true">
                <i class="fa-solid {{ $module->icon }}"></i>
            </span>

            <div class="help-hero__body">
                <p class="help-eyebrow">Centro de ayuda</p>
                <p class="help-hero__title">{{ $settings['name'] }}</p>
                <p class="help-hero__text">{{ $settings['tagline'] }}</p>

                <p class="help-hero__meta">
                    <span class="help-badge">Versión {{ $version }}</span>
                    <span class="help-badge help-badge--soft">{{ $settings['status'] }}</span>
                </p>
            </div>
        </header>

        <div class="help-layout">
            @include('ayuda.partials.toc', [
                'label' => 'En esta página',
                'links' => $articles->map(fn ($article) => [
                    'label' => $article->title,
                    'anchor' => $article->slug,
                ])->all(),
                'adminLinks' => $adminArticles->map(fn ($article) => [
                    'label' => $article->title,
                    'anchor' => $article->slug,
                ])->all(),
            ])

            <div class="help-content">
                @forelse ($articles as $article)
                    @include('ayuda.partials.article', ['article' => $article, 'admin' => false])
                @empty
                    <div class="help-card">
                        <p class="help-text">Todavía no hay contenido publicado para esta página.</p>
                    </div>
                @endforelse

                <div class="help-card help-cta" aria-label="Siguientes pasos">
                    <p class="help-cta__title">¿Quieres saber cómo se usa?</p>
                    <p class="help-text">La guía explica la dinámica paso a paso. Las preguntas frecuentes resuelven las dudas más comunes.</p>

                    <div class="help-cta__actions">
                        <a class="btn btn-primary" href="{{ module_url('ayuda') }}">
                            <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                            <span>Leer la guía de uso</span>
                        </a>
                        <a class="btn btn-outline-secondary" href="{{ module_url('faq') }}">
                            <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                            <span>Ver preguntas frecuentes</span>
                        </a>
                    </div>
                </div>

                @if ($adminArticles->isNotEmpty())
                    <x-help-admin-section title="Administración del sistema">
                        @foreach ($adminArticles as $article)
                            @include('ayuda.partials.article', ['article' => $article, 'admin' => true])
                        @endforeach
                    </x-help-admin-section>
                @endif

                <p class="help-footer">
                    <i class="fa-solid fa-code-branch" aria-hidden="true"></i>
                    <span>{{ $settings['name'] }} · Versión {{ $version }}</span>
                </p>
            </div>
        </div>
    </div>
@endsection
