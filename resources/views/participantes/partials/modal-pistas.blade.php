<x-app-modal id="modal-participante-pistas" title="Pistas" size="lg" scrollable>
    <x-slot:body>
        <div class="participantes-modal">
            <p class="participantes-modal__eyebrow">Participante</p>
            <h3 class="participantes-modal__name" id="pistas-nombre">—</h3>

            <div id="pistas-aviso" aria-live="polite"></div>

            <div id="pistas-tablas" class="d-none">
                <ul class="nav nav-tabs participantes-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link active" id="pistas-tab-recibidas"
                                data-bs-toggle="tab" data-bs-target="#pistas-pane-recibidas"
                                role="tab" aria-controls="pistas-pane-recibidas" aria-selected="true">
                            <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                            <span>Pistas del amigo secreto</span>
                            <span class="participantes-tab__count" id="pistas-count-recibidas"></span>
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="pistas-tab-enviadas"
                                data-bs-toggle="tab" data-bs-target="#pistas-pane-enviadas"
                                role="tab" aria-controls="pistas-pane-enviadas" aria-selected="false">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            <span>Pistas al amigo secreto</span>
                            <span class="participantes-tab__count" id="pistas-count-enviadas"></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content participantes-tabs__content">
                    <div class="tab-pane fade show active" id="pistas-pane-recibidas"
                         role="tabpanel" aria-labelledby="pistas-tab-recibidas" tabindex="0">
                        <div class="table-responsive">
                            <table id="pistas-recibidas-table"
                                   class="table table-striped align-middle w-100 participantes-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Pista recibida</th>
                                        <th scope="col" class="col-estado">Leída</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Pista recibida</th>
                                        <th scope="col" class="col-estado">Leída</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pistas-pane-enviadas"
                         role="tabpanel" aria-labelledby="pistas-tab-enviadas" tabindex="0">
                        <div class="table-responsive">
                            <table id="pistas-enviadas-table"
                                   class="table table-striped align-middle w-100 participantes-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Para</th>
                                        <th scope="col" class="col-texto">Pista enviada</th>
                                        <th scope="col" class="col-estado">Leída</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Para</th>
                                        <th scope="col" class="col-texto">Pista enviada</th>
                                        <th scope="col" class="col-estado">Leída</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
