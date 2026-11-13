@extends('layouts.app')

@section('title', 'Ver tu amigo · Amigo Secreto Inclusivo')
@section('shell-title', 'Ver tu amigo')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    <section class="sorteo-page" aria-label="Ver tu amigo">
        <article class="sorteo-card">
            <header class="sorteo-card__head">
                <div>
                    <h2 class="sorteo-card__title">Tu amigo secreto</h2>
                    <p class="sorteo-card__text">Puedes consultarlo cuantas veces quieras: no se vuelve a sortear.</p>
                </div>
            </header>

            @if (! $estado['revelado'])
                <p class="sorteo-note">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>{{ $estado['mensaje'] ?? 'Todavía no has descubierto a tu amigo secreto.' }}</span>
                </p>

                <div class="sorteo-cta">
                    <a class="btn btn-primary" href="{{ module_url('asignar-amigo') }}">
                        <i class="fa-solid fa-handshake" aria-hidden="true"></i>
                        <span>Ir al Sorteo ciego</span>
                    </a>
                </div>
            @else
                <div id="amigo-tarjeta">
                    @include('sorteo.partials.tarjeta', ['amigo' => $estado['amigo']])
                </div>

                <div class="sorteo-actions">
                    <button type="button" class="btn btn-outline-secondary" id="amigo-ver-de-nuevo">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        <span>Ver de nuevo</span>
                    </button>
                </div>

                <section class="amigo-clues" aria-label="Pistas de tu amigo secreto">
                    <h3 class="amigo-clues__title">
                        <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                        <span>Pistas que te ha dejado tu amigo secreto</span>
                    </h3>

                    <ul class="amigo-clues__list" id="amigo-clues">
                        @forelse ($pistas as $pista)
                            <li class="amigo-clue">
                                <p class="amigo-clue__body">{{ $pista->body }}</p>
                                <p class="amigo-clue__date">{{ $pista->sent_at?->format('d/m/Y H:i') }}</p>
                            </li>
                        @empty
                            <li class="amigo-clue amigo-clue--empty">Todavía no te ha dejado pistas.</li>
                        @endforelse
                    </ul>
                </section>
            @endif

            <p class="sorteo-error" id="amigo-error" role="alert" hidden></p>
        </article>
    </section>

    <div id="amigo-config"
         data-url-datos="{{ route('ver-tu-amigo.datos') }}"
         hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/sorteo.js') }}?v={{ filemtime(public_path('js/sorteo.js')) }}"></script>
@endpush
