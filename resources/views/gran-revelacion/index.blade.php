@extends('layouts.app')

@section('title', 'Gran revelación · Amigo Secreto Inclusivo')
@section('shell-title', 'Gran revelación')
@section('shell-eyebrow', $event?->name ?? 'Amigo secreto')

@section('page')
    <section class="revelacion-page" aria-label="Gran revelación">
        @if ($event === null)
            <article class="sorteo-card">
                <p class="sorteo-note">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <span>Todavía no hay un evento activo.</span>
                </p>
            </article>
        @elseif (! $participacion?->status->isParticipating())
            <article class="sorteo-card">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">Gran revelación</h2>
                        <p class="sorteo-card__text">
                            Solo los participantes activos pueden ver la revelación.
                        </p>
                    </div>
                </header>
            </article>
        @elseif (! $disponible)
            <article class="sorteo-card">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            <span>Aún no es el día de la revelación</span>
                        </h2>
                        <p class="sorteo-card__text">
                            La gran revelación se abre el día exacto del intercambio.
                        </p>
                    </div>

                    <span class="status-badge status-badge--inactive">Bloqueada</span>
                </header>

                <p class="sorteo-note">
                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                    <span>
                        Fecha del intercambio:
                        <strong>{{ $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha' }}</strong>.
                        @if ($event->status === \App\Enums\EventStatus::Registration)
                            Todavía estás en la Unión: el sorteo ciego aún no ha comenzado.
                        @elseif ($event->status === \App\Enums\EventStatus::DrawEnabled)
                            El sorteo ciego está en curso.
                        @elseif ($event->status === \App\Enums\EventStatus::Drawn)
                            Mientras llega la fecha, puedes seguir intercambiando pistas.
                        @endif
                    </span>
                </p>

                <div class="sorteo-actions">
                    <a class="btn btn-outline-secondary" href="{{ module_url('intercambio-de-pista') }}">
                        <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                        <span>Ir al intercambio de pistas</span>
                    </a>
                </div>
            </article>
        @else
            <article class="sorteo-card revelacion-card">
                <header class="sorteo-card__head">
                    <div>
                        <h2 class="sorteo-card__title">La gran revelación</h2>
                        <p class="sorteo-card__text">
                            {{ $event->name }} · {{ $event->exchange_at?->format('d/m/Y') }}
                        </p>
                    </div>

                    <span class="status-badge {{ $event->status->isFinished() ? 'status-badge--inactive' : 'status-badge--active' }}">
                        {{ $event->status->isFinished() ? 'Finalizado' : 'En revelación' }}
                    </span>
                </header>

                @if ($revelacion === null)
                    <p class="sorteo-note">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        <span>No hay un amigo secreto que revelar para tu participación.</span>
                    </p>
                @else
                    <div id="revelacion-contenido">
                        @include('gran-revelacion.partials.revelacion', ['revelacion' => $revelacion])
                    </div>

                    <div class="sorteo-actions">
                        <button type="button" class="btn btn-outline-secondary" id="revelacion-refrescar">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            <span>Actualizar</span>
                        </button>

                        <a class="btn btn-outline-secondary" href="{{ module_url('dashboard') }}">
                            <i class="fa-solid fa-house" aria-hidden="true"></i>
                            <span>Volver al panel</span>
                        </a>
                    </div>
                @endif
            </article>
        @endif
    </section>

    <div id="revelacion-config"
         data-url-datos="{{ route('gran-revelacion.datos') }}"
         data-csrf="{{ csrf_token() }}"
         hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/gran-revelacion.js') }}?v={{ filemtime(public_path('js/gran-revelacion.js')) }}"></script>
@endpush
