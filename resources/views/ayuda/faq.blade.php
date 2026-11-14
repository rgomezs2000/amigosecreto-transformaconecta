@extends('layouts.app')

@section('title', 'FAQ · ' . $settings['name'])
@section('shell-title', 'Preguntas frecuentes')
@section('shell-eyebrow', $module->category?->name ?? 'Ayuda')

@section('page')
    <div class="help-page">
        <header class="help-hero">
            <span class="help-hero__icon" aria-hidden="true">
                <i class="fa-solid {{ $module->icon }}"></i>
            </span>

            <div class="help-hero__body">
                <p class="help-eyebrow">{{ $module->name }}</p>
                <p class="help-hero__title">Preguntas frecuentes</p>
                <p class="help-hero__text">
                    Respuestas breves sobre el acceso, la dinámica, la lista de deseos, las pistas y la
                    revelación. Toca una pregunta para ver la respuesta.
                </p>

                <p class="help-hero__meta">
                    <span class="help-badge">Versión {{ $version }}</span>
                    <span class="help-badge help-badge--soft">{{ $articles->count() }} preguntas</span>
                </p>
            </div>
        </header>

        <div class="help-layout">
            @include('ayuda.partials.toc', [
                'label' => 'Temas',
                'links' => $topics->keys()->map(fn ($topic) => [
                    'label' => $topic,
                    'anchor' => 'tema-' . \Illuminate\Support\Str::slug($topic),
                ])->all(),
                'adminLinks' => $adminTopics->keys()->map(fn ($topic) => [
                    'label' => $topic,
                    'anchor' => 'tema-' . \Illuminate\Support\Str::slug($topic),
                ])->all(),
            ])

            <div class="help-content">
                @forelse ($topics as $topic => $questions)
                    @include('ayuda.partials.faq-group', [
                        'topic' => $topic,
                        'questions' => $questions,
                        'admin' => false,
                        'openFirst' => $loop->first,
                    ])
                @empty
                    <div class="help-card">
                        <p class="help-text">Todavía no hay preguntas publicadas.</p>
                    </div>
                @endforelse

                <div class="help-card help-cta" aria-label="Siguientes pasos">
                    <p class="help-cta__title">¿No encuentras tu respuesta?</p>
                    <p class="help-text">
                        La guía explica la dinámica completa, paso a paso. Si la duda sigue, escribe a la
                        organización del evento: el módulo de contacto y soporte aún no está habilitado.
                    </p>

                    <div class="help-cta__actions">
                        <a class="btn btn-primary" href="{{ module_url('ayuda') }}">
                            <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                            <span>Leer la guía de uso</span>
                        </a>
                        <a class="btn btn-outline-secondary" href="{{ module_url('acerca-de') }}">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Acerca de la aplicación</span>
                        </a>
                    </div>
                </div>

                @if ($adminTopics->isNotEmpty())
                    <x-help-admin-section title="Administración">
                        @foreach ($adminTopics as $topic => $questions)
                            @include('ayuda.partials.faq-group', [
                                'topic' => $topic,
                                'questions' => $questions,
                                'admin' => true,
                                'openFirst' => false,
                            ])
                        @endforeach
                    </x-help-admin-section>
                @endif

                <p class="help-footer">
                    <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                    <span>{{ $settings['name'] }} · Versión {{ $version }}</span>
                </p>
            </div>
        </div>
    </div>
@endsection
