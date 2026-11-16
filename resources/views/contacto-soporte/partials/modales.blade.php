@php($mode = $mode ?? 'user')

<x-app-modal id="modal-confirmar-reporte" title="Confirmar reporte">
    <x-slot:body>
        <p class="mb-0">
            ¿Deseas crear el ticket con la información que escribiste?
            Si confirmas, se registrará el caso y podrás seguirlo desde la pestaña Seguimiento.
        </p>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-primary" id="reporte-confirmar">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>Sí, crear ticket</span>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
    </x-slot:footer>
</x-app-modal>

<x-app-modal id="modal-ticket-creado" title="Ticket creado">
    <x-slot:body>
        <p class="mb-2">Tu ticket quedó registrado con el número:</p>
        <p class="moderacion-summary__total mb-3" id="reporte-ticket-numero">—</p>
        <p class="mb-0 text-muted" id="reporte-ticket-texto">
            Puedes seguirlo desde la pestaña Seguimiento.
        </p>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-primary" id="reporte-ticket-seguimiento">
            <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            <span>Ir a Seguimiento</span>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
