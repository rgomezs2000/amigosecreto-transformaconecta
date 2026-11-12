@extends('layouts.app')

@section('title', 'Sorteo ciego · Amigo Secreto Inclusivo')
@section('shell-title', 'Sorteo ciego')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    <section class="sorteo-page" aria-label="Sorteo ciego">
        <article class="sorteo-card">
            <header class="sorteo-card__head">
                <span class="sorteo-card__icon" aria-hidden="true">
                    <i class="fa-solid fa-handshake"></i>
                </span>

                <div>
                    <h2 class="sorteo-card__title">Sorteo ciego</h2>
                    <p class="sorteo-card__text">
                        {{ $event?->name ?? 'Sin evento activo' }}
                        @if ($event?->exchange_at)
                            · intercambio el {{ $event->exchange_at->format('d/m/Y') }}
                        @endif
                    </p>
                </div>
            </header>

            @if ($estado['total'] > 0)
                <p class="sorteo-progress" role="status" aria-live="polite">
                    Han elegido <strong>{{ $estado['elegidos'] }}</strong> de
                    <strong>{{ $estado['total'] }}</strong> participantes.
                </p>
            @endif

            {{-- Sorteo cerrado: se explica el motivo. --}}
            <div id="sorteo-cerrado" @if ($estado['abierto']) hidden @endif>
                <p class="sorteo-note">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>{{ $estado['mensaje'] }}</span>
                </p>
            </div>

            {{-- Todavía no ha elegido: botón central. --}}
            <div id="sorteo-abierto" @if (! $estado['abierto'] || $estado['revelado']) hidden @endif>
                <p class="sorteo-note">
                    <i class="fa-solid fa-dice" aria-hidden="true"></i>
                    <span>El reparto es al azar y se elige una sola vez. Después podrás volver a verlo cuando quieras.</span>
                </p>

                <div class="sorteo-cta">
                    <button type="button" class="btn btn-primary" id="sorteo-descubrir">
                        <i class="fa-solid fa-gift" aria-hidden="true"></i>
                        <span>Descubrir a mi Amigo Secreto</span>
                    </button>
                </div>
            </div>

            {{-- Ya eligió: tarjeta limpia con su amigo. --}}
            <div id="sorteo-amigo" @if (! $estado['revelado']) hidden @endif>
                <div id="sorteo-amigo-tarjeta">
                    @if ($estado['revelado'])
                        @include('sorteo.partials.tarjeta', ['amigo' => $estado['amigo']])
                    @endif
                </div>

                <div class="sorteo-actions">
                    <button type="button" class="btn btn-outline-secondary" id="sorteo-ver-de-nuevo">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        <span>Ver de nuevo</span>
                    </button>

                    <a class="btn btn-outline-secondary" href="{{ route('ver-tu-amigo.index') }}">
                        <i class="fa-solid fa-user-secret" aria-hidden="true"></i>
                        <span>Ver tu amigo y tus pistas</span>
                    </a>
                </div>
            </div>

            <p class="sorteo-error" id="sorteo-error" role="alert" hidden></p>
        </article>
    </section>

    <div id="sorteo-config"
         data-url-descubrir="{{ route('sorteo.descubrir') }}"
         data-url-ver="{{ route('sorteo.ver') }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/sorteo.js') }}?v={{ filemtime(public_path('js/sorteo.js')) }}"></script>
@endpush
