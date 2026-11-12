@extends('layouts.app')

@section('title', 'Eventos · Amigo Secreto Inclusivo')
@section('shell-title', 'Eventos')
@section('shell-eyebrow', 'Eventos')

@section('page')
    @if (session('eventos_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('eventos_status') }}</span>
        </div>
    @endif

    <section class="eventos-page" aria-label="Eventos">
        <div class="eventos-toolbar">
            <div>
                <h2 class="eventos-title">Tus eventos</h2>
                <p class="eventos-subtitle">Un evento por año. Las tarjetas son de solo lectura; usa los botones para gestionar el evento.</p>
            </div>

            <button type="button" class="btn btn-primary" id="eventos-new">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                <span>Nuevo evento</span>
            </button>
        </div>

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
                                <h3 class="evento-card__name">{{ $event->name }}</h3>
                            </div>
                            <span class="status-badge status-badge--{{ $badge }}">{{ $event->statusLabel() }}</span>
                        </header>

                        <dl class="evento-card__data">
                            <div>
                                <dt>Fecha del intercambio</dt>
                                <dd>{{ $event->exchange_at?->format('d/m/Y') ?? 'Sin fecha' }}</dd>
                            </div>
                            <div>
                                <dt>Participantes</dt>
                                <dd>{{ $event->participants()->count() }}</dd>
                            </div>
                            <div>
                                <dt>Estado</dt>
                                <dd>{{ $event->statusLabel() }}</dd>
                            </div>
                            <div>
                                <dt>Creado</dt>
                                <dd>{{ $event->created_at?->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                        </dl>

                        @if ($event->status_reason)
                            <p class="evento-card__note">
                                <strong>Observaciones:</strong> {{ $event->status_reason }}
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

                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                    title="Disponible en una próxima fase" aria-label="Reiniciar, disponible próximamente">
                                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                <span>Reiniciar</span>
                            </button>
                        </footer>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <input type="hidden" id="eventos-csrf" value="{{ csrf_token() }}">

    <div id="eventos-config"
         data-url-crear="{{ route('eventos.crear') }}"
         data-url-index="{{ route('eventos.index') }}"
         data-url-participantes="{{ url('/eventos') }}"
         hidden></div>

    <x-app-modal id="modal-evento-form" title="Nuevo evento">
        <x-slot:body>
            <form id="form-evento" novalidate>
                @csrf
                <div class="field">
                    <label for="evento-name" class="form-label">Nombre del evento</label>
                    <input type="text" class="form-control" id="evento-name" maxlength="120"
                           placeholder="Ejemplo: Amigo Secreto 2026" autocomplete="off">
                    <p class="field-message" id="evento-name-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="evento-date" class="form-label">Fecha del intercambio</label>
                    <input type="date" class="form-control" id="evento-date">
                    <p class="field-message" id="evento-date-error" role="status" aria-live="polite"></p>
                </div>

                <div class="field">
                    <label for="evento-observations" class="form-label">Observaciones generales</label>
                    <textarea class="form-control" id="evento-observations" rows="3" maxlength="1000"
                              placeholder="Opcional"></textarea>
                </div>

                <div class="evento-participants">
                    <p class="evento-participants__title">
                        Participantes activos: <strong>{{ $participantUsers->count() }}</strong>
                    </p>

                    @if ($participantUsers->isEmpty())
                        <p class="field-message" data-kind="error">No hay usuarios participantes activos. Crea usuarios con rol de participante antes de planificar el evento.</p>
                    @else
                        <div class="evento-participants__list">
                            @foreach ($participantUsers as $participant)
                                <div class="evento-participant">
                                    <div class="evento-participant__data">
                                        <p class="evento-participant__name">{{ $participant->name }}</p>
                                        <p class="evento-participant__email">{{ $participant->email }}</p>
                                        <span class="status-badge status-badge--active">Activo</span>
                                    </div>
                                    <input type="text" class="form-control evento-participant__notes"
                                           data-user-id="{{ $participant->id }}"
                                           maxlength="500"
                                           placeholder="Observaciones (opcional)"
                                           aria-label="Observaciones de {{ $participant->name }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="evento-participants__pager">
                            <div class="evento-participants__length">
                                <label for="evento-participants-length">Mostrar</label>
                                <select id="evento-participants-length" class="form-select form-select-sm">
                                    <option value="2" selected>2</option>
                                    <option value="5">5</option>
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                </select>
                                <span>participantes</span>
                            </div>

                            <div class="evento-participants__nav">
                                <span id="evento-participants-info"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="evento-participants-prev">Anterior</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="evento-participants-next">Siguiente</button>
                            </div>
                        </div>
                    @endif
                </div>
            </form>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" form="form-evento" class="btn btn-primary" id="evento-save">Guardar evento</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-evento-participantes" title="Participantes del evento">
        <x-slot:body>
            <p class="evento-participants__count" id="evento-participantes-count"></p>
            <div class="evento-participants__list" id="evento-participantes-list"></div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-evento-suspender" title="Suspender evento">
        <x-slot:body>
            <p class="evento-modal__text">El evento se congela y todos los módulos de amigo secreto quedan deshabilitados hasta que se reactive.</p>
            <div class="field">
                <label for="evento-suspender-reason" class="form-label">Motivo de la suspensión</label>
                <textarea class="form-control" id="evento-suspender-reason" rows="3" maxlength="1000"></textarea>
                <p class="field-message" id="evento-suspender-error" role="status" aria-live="polite"></p>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="evento-suspender-confirm">Suspender evento</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-evento-posponer" title="Posponer evento">
        <x-slot:body>
            <div class="field">
                <label for="evento-posponer-date" class="form-label">Nueva fecha del intercambio</label>
                <input type="date" class="form-control" id="evento-posponer-date">
                <p class="field-message" id="evento-posponer-date-error" role="status" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="evento-posponer-reason" class="form-label">Motivo de la postergación</label>
                <textarea class="form-control" id="evento-posponer-reason" rows="3" maxlength="1000"></textarea>
                <p class="field-message" id="evento-posponer-error" role="status" aria-live="polite"></p>
            </div>

            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="evento-posponer-suspend">
                <label class="form-check-label" for="evento-posponer-suspend">
                    Mantener el evento suspendido después de posponer
                </label>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="evento-posponer-confirm">Posponer evento</button>
        </x-slot:footer>
    </x-app-modal>

    <x-app-modal id="modal-evento-cancelar" title="Cancelar evento">
        <x-slot:body>
            <p class="evento-modal__text">El evento se elimina del sistema. Esta acción no se puede deshacer.</p>
            <div class="field">
                <label for="evento-cancelar-reason" class="form-label">Motivo de la cancelación</label>
                <textarea class="form-control" id="evento-cancelar-reason" rows="3" maxlength="1000"></textarea>
                <p class="field-message" id="evento-cancelar-error" role="status" aria-live="polite"></p>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
            <button type="button" class="btn btn-primary" id="evento-cancelar-confirm">Cancelar evento</button>
        </x-slot:footer>
    </x-app-modal>
@endsection

@push('scripts')
    <script src="{{ asset('js/eventos.js') }}?v={{ filemtime(public_path('js/eventos.js')) }}"></script>
@endpush
