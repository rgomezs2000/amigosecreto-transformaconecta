<x-app-modal id="modal-consumible" title="Nuevo consumible">
    <x-slot:body>
        <form id="form-consumible" novalidate>
            @csrf
            <input type="hidden" id="consumible-id" value="">

            <div class="field">
                <label for="consumible-nombre" class="form-label">Nombre del consumible</label>
                <input type="text" class="form-control" id="consumible-nombre" name="nombre"
                       maxlength="150" autocomplete="off" placeholder="Ejemplo: chocolate">
                <p class="field-message" id="consumible-nombre-error" role="status" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="consumible-valor" class="form-label">Valor en US$</label>
                <input type="number" class="form-control" id="consumible-valor" name="valor"
                       min="0.01" step="0.01" inputmode="decimal" placeholder="0,00">
                <p class="field-message" id="consumible-valor-error" role="status" aria-live="polite"></p>
            </div>

            <div class="field">
                <label for="consumible-bs" class="form-label">Valor en Bs. (tasa BCV)</label>
                <input type="text" class="form-control" id="consumible-bs"
                       readonly aria-readonly="true" placeholder="—">
                <p class="field-message amigo-hint" id="consumible-bs-hint">
                    Se calcula con la tasa oficial del BCV.
                </p>
            </div>

            <p class="amigo-tasa amigo-tasa--modal" id="consumible-tasa">
                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                <span>
                    Tasa oficial BCV:
                    <strong id="consumible-tasa-valor">{{ $tasa !== null ? 'Bs. '.format_rate($tasa) : 'No disponible' }}</strong>
                    por US$
                </span>
            </p>

            <p class="field-message" id="consumible-form-error" role="status" aria-live="polite"></p>
        </form>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-outline-secondary" id="consumible-limpiar">
            <i class="fa-solid fa-eraser" aria-hidden="true"></i>
            <span>Limpiar</span>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="consumible-cerrar">
            Cerrar
        </button>
        <button type="button" class="btn btn-primary" id="consumible-guardar">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            <span>Guardar</span>
        </button>
    </x-slot:footer>
</x-app-modal>
