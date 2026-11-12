{{--
    Modal de dos listas para Moderación.

    Se usa dos veces: para los bloqueos entre usuarios y para los vetados de
    cada grupo. La lista izquierda son las personas sin la sanción; la derecha,
    las que la tienen. Se elige con un clic; con Shift se toma un tramo seguido
    y con Ctrl (o Cmd) se suman personas sueltas, y todo lo seleccionado se
    arrastra junto o se mueve con las flechas. Los cambios son locales hasta
    pulsar «Guardar»: allí se confirma, se envía la lista completa de cada lado
    y se muestran el error o el éxito. «Cerrar» descarta lo pendiente y, al
    volver a abrir, se recarga el estado del servidor.

    Variables: $modalId, $modalTitle, $kind, $subjectPrefix, $sideLabels,
    $sideHelp, $moveLabels, $urlDetalle, $urlSincronizar, $showMemberHint.
--}}
@php($rightKey = $kind === 'vetados' ? 'banned' : 'blocked')

<x-app-modal :id="$modalId" :title="$modalTitle" size="lg" scrollable>
    <x-slot:body>
        <p class="moderacion-transfer__subject" data-transfer-subject>Cargando...</p>

        <div class="moderacion-transfer"
             data-transfer
             data-kind="{{ $kind }}"
             data-right-key="{{ $rightKey }}"
             data-subject-prefix="{{ $subjectPrefix }}"
             data-show-member="{{ $showMemberHint ? '1' : '0' }}"
             data-url-detalle="{{ $urlDetalle }}"
             data-url-sincronizar="{{ $urlSincronizar }}"
             data-csrf="{{ csrf_token() }}">

            <p class="moderacion-transfer__hint">
                Haz clic en una persona; con Shift eliges un tramo seguido y con Ctrl (o Cmd)
                varias sueltas. Arrastra la selección o usa las flechas, y pulsa «Guardar».
            </p>

            <div class="moderacion-transfer__grid">
                <section class="moderacion-transfer__side" data-side="available"
                         aria-labelledby="{{ $modalId }}-available-title">
                    <h3 class="moderacion-transfer__title" id="{{ $modalId }}-available-title">
                        <span>{{ $sideLabels[0] }}</span>
                        <span class="moderacion-transfer__count" data-count>0</span>
                    </h3>
                    <p class="moderacion-transfer__help">{{ $sideHelp[0] }}</p>
                    <ul class="moderacion-transfer__list" data-list="available" role="listbox"
                        aria-label="{{ $sideLabels[0] }}"></ul>
                </section>

                <div class="moderacion-transfer__controls" role="group" aria-label="Mover personas">
                    <button type="button" class="btn btn-outline-primary moderacion-transfer__move"
                            data-move="to-right-all" title="{{ $moveLabels[0] }}"
                            aria-label="{{ $moveLabels[0] }}">
                        <i class="fa-solid fa-angles-right" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-primary moderacion-transfer__move"
                            data-move="to-right" title="{{ $moveLabels[1] }}"
                            aria-label="{{ $moveLabels[1] }}">
                        <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-primary moderacion-transfer__move"
                            data-move="to-left" title="{{ $moveLabels[2] }}"
                            aria-label="{{ $moveLabels[2] }}">
                        <i class="fa-solid fa-angle-left" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary moderacion-transfer__move"
                            data-move="to-left-all" title="{{ $moveLabels[3] }}"
                            aria-label="{{ $moveLabels[3] }}">
                        <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
                    </button>
                </div>

                <section class="moderacion-transfer__side" data-side="right"
                         aria-labelledby="{{ $modalId }}-right-title">
                    <h3 class="moderacion-transfer__title" id="{{ $modalId }}-right-title">
                        <span>{{ $sideLabels[1] }}</span>
                        <span class="moderacion-transfer__count" data-count>0</span>
                    </h3>
                    <p class="moderacion-transfer__help">{{ $sideHelp[1] }}</p>
                    <ul class="moderacion-transfer__list" data-list="{{ $rightKey }}" role="listbox"
                        aria-label="{{ $sideLabels[1] }}"></ul>
                </section>
            </div>

            <p class="moderacion-transfer__status" role="status" aria-live="polite" data-transfer-status></p>

            {{-- Confirmación dentro del modal: evita apilar un diálogo sobre otro. --}}
            <div class="moderacion-transfer__confirm" data-transfer-confirm role="alert" hidden>
                <p class="moderacion-transfer__confirm-text" data-transfer-confirm-text></p>
                <div class="moderacion-transfer__confirm-actions">
                    <button type="button" class="btn btn-primary" data-transfer-confirm-yes>Sí, guardar</button>
                    <button type="button" class="btn btn-outline-secondary" data-transfer-confirm-no>Cancelar</button>
                </div>
            </div>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-primary" data-transfer-save disabled>Guardar</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
