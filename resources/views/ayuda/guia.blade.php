@extends('layouts.app')

@section('title', 'Ayuda · ' . $settings['name'])
@section('shell-title', 'Ayuda')
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
                <p class="help-eyebrow">{{ $module->name }}</p>
                <p class="help-hero__title">Guía de uso del sistema</p>
                <p class="help-hero__text">
                    Todo lo que necesitas para entrar, entender cada paso de la dinámica y saber qué hacer
                    en el momento indicado. Lee solo la sección que necesites; puedes volver cuando quieras.
                </p>

                <p class="help-hero__meta">
                    <span class="help-badge">Versión {{ $version }}</span>
                    <span class="help-badge help-badge--soft">{{ $articles->count() }} secciones</span>
                </p>
            </div>
        </header>

        <div class="help-layout">
            @include('ayuda.partials.toc', [
                'label' => 'Contenido de la guía',
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
                        <p class="help-text">Todavía no hay contenido publicado para esta guía.</p>
                    </div>
                @endforelse

                <div class="help-card help-cta" aria-label="Siguientes pasos">
                    <p class="help-cta__title">¿Te queda una duda?</p>
                    <p class="help-text">
                        Revisa las preguntas frecuentes. Si no encuentras la respuesta, escribe a la
                        organización del evento por el canal que usaron para invitarte.
                    </p>

                    <div class="help-cta__actions">
                        <a class="btn btn-primary" href="{{ module_url('faq') }}">
                            <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                            <span>Ver preguntas frecuentes</span>
                        </a>
                        <a class="btn btn-outline-secondary" href="{{ module_url('acerca-de') }}">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Acerca de la aplicación</span>
                        </a>
                    </div>
                </div>

                @if ($adminArticles->isNotEmpty())
                    <x-help-admin-section title="Administración de la ayuda">
                        @foreach ($adminArticles as $article)
                            @include('ayuda.partials.article', ['article' => $article, 'admin' => true])
                        @endforeach
                    </x-help-admin-section>
                @endif

                <p class="help-footer">
                    <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                    <span>{{ $settings['name'] }} · Versión {{ $version }}</span>
                </p>
            </div>
        </div>
    </div>
@endsection
