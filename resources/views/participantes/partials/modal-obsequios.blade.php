<x-app-modal id="modal-participante-obsequios" title="Obsequios" size="lg" scrollable>
    <x-slot:body>
        <div class="participantes-modal">
            <p class="participantes-modal__eyebrow">Participante</p>
            <h3 class="participantes-modal__name" id="obsequios-nombre">—</h3>

            <div id="obsequios-aviso" aria-live="polite"></div>

            <div id="obsequios-secciones" class="d-none">
                <div id="obsequios-obsequio"></div>

                <h4 class="participantes-subtitle">Obsequios consumibles</h4>

                <div class="table-responsive">
                    <table id="obsequios-consumibles-table"
                           class="table table-striped align-middle w-100 participantes-tabla">
                        <thead>
                            <tr>
                                <th scope="col" class="col-fecha">Fecha</th>
                                <th scope="col" class="col-texto">Obsequio</th>
                                <th scope="col" class="col-numero">Cantidad</th>
                                <th scope="col" class="col-valor">Valor</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr>
                                <th scope="col" class="col-fecha">Fecha</th>
                                <th scope="col" class="col-texto">Obsequio</th>
                                <th scope="col" class="col-numero">Cantidad</th>
                                <th scope="col" class="col-valor">Valor</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="participantes-totales" id="obsequios-consumibles-totales" aria-live="polite"></p>
            </div>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
