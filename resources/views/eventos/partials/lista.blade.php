@if ($events->isEmpty())
    <div class="evento-empty">
        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
        <p>Todavía no hay eventos. Crea el primero con «Nuevo evento».</p>
    </div>
@else
    <div class="evento-grid">
        @foreach ($events as $event)
            @php($badge = $event->isSuspended() ? 'blocked' : ($event->status->isFinished() ? 'inactive' : 'active'))

            <article class="evento-card" data-evento-id="{{ $event->id }}" data-evento-name="{{ $event->name }}">
                <header class="evento-card__head">
                    <div>
                        <p class="evento-card__year">Año {{ $event->year }}</p>
                        <h3 class="evento-card__name" data-evento-field="name">{{ $event->name }}</h3>
                    </div>
                    <span class="status-badge status-badge--{{ $badge }}" data-evento-field="status">{{ $event->statusLabel() }}</span>
                </header>

                <dl class="evento-card__data">
                    <div>
                        <dt>Fecha del intercambio</dt>
                        <dd data-evento-field="exchange">{{ $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha' }}</dd>
                    </div>
                    <div>
                        <dt>Participantes</dt>
                        <dd data-evento-field="participants">{{ $event->participants()->count() }}</dd>
                    </div>
                    <div>
                        <dt>Estado</dt>
                        <dd data-evento-field="status">{{ $event->statusLabel() }}</dd>
                    </div>
                    <div>
                        <dt>Creado</dt>
                        <dd data-evento-field="created">{{ $event->created_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($event->status_reason)
                    <p class="evento-card__note">
                        <span class="evento-card__note-label">Observaciones</span>
                        <span>{{ $event->status_reason }}</span>
                    </p>
                @endif

                @if ($event->suspended_at)
                    <p class="evento-card__meta">Suspendido el {{ $event->suspended_at->format('d/m/Y') }}.</p>
                @endif

                @if ($event->postponed_at)
                    <p class="evento-card__meta">Pospuesto el {{ $event->postponed_at->format('d/m/Y') }}.</p>
                @endif

                <footer class="evento-card__actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-participantes">
                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                        <span>Participantes</span>
                    </button>

                    @if (in_array($event->status, [\App\Enums\EventStatus::Draft, \App\Enums\EventStatus::Registration], true))
                        <button type="button" class="btn btn-sm btn-primary btn-evento-iniciar">
                            <i class="fa-solid fa-play" aria-hidden="true"></i>
                            <span>Iniciar</span>
                        </button>
                    @endif

                    @if (! $event->isSuspended() && ! $event->status->isFinished())
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-suspender">
                            <i class="fa-solid fa-pause" aria-hidden="true"></i>
                            <span>Suspender</span>
                        </button>
                    @endif

                    @if (! $event->status->isFinished())
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-posponer">
                            <i class="fa-regular fa-calendar-plus" aria-hidden="true"></i>
                            <span>{{ $event->isSuspended() ? 'Posponer / Reactivar' : 'Posponer' }}</span>
                        </button>

                        <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-cancelar">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            <span>Cancelar</span>
                        </button>
                    @endif

                    <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-reiniciar"
                            data-year="{{ $event->year }}"
                            title="Reiniciar el evento de {{ $event->year }}"
                            aria-label="Reiniciar el evento de {{ $event->year }}">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        <span>Reiniciar</span>
                    </button>

                    @if ($event->drawIsOpen())
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-evento-reiniciar-sorteo"
                                data-id="{{ $event->id }}"
                                title="Borrar las parejas y volver a sortear"
                                aria-label="Reiniciar el sorteo ciego">
                            <i class="fa-solid fa-dice" aria-hidden="true"></i>
                            <span>Reiniciar sorteo</span>
                        </button>
                    @endif
                </footer>
            </article>
        @endforeach
    </div>
@endif
