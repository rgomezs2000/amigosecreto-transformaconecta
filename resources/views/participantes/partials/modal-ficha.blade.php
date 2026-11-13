<x-app-modal id="modal-participante-ficha" title="Ficha del participante" size="lg" scrollable>
    <x-slot:body>
        <div id="participante-ficha-body" aria-live="polite">
            <p class="participantes-empty">Cargando…</p>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
