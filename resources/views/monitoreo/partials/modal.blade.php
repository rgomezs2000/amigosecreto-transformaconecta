<x-app-modal id="modal-monitoreo" :title="$titulo" size="lg" scrollable>
    <x-slot:body>
        <div class="participantes-modal monitoreo-modal">
            <p class="participantes-modal__eyebrow">Participante</p>
            <h3 class="participantes-modal__name" id="monitoreo-nombre">—</h3>

            <ul class="nav nav-tabs participantes-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link active" id="monitoreo-tab-participante"
                            data-bs-toggle="tab" data-bs-target="#monitoreo-pane-participante"
                            data-monitoreo-tab="participante" role="tab"
                            aria-controls="monitoreo-pane-participante" aria-selected="true">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <span>Participante</span>
                    </button>
                </li>

                @if ($tipo === 'deseos')
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="monitoreo-tab-deseos"
                                data-bs-toggle="tab" data-bs-target="#monitoreo-pane-deseos"
                                data-monitoreo-tab="deseos" role="tab"
                                aria-controls="monitoreo-pane-deseos" aria-selected="false">
                            <i class="fa-solid fa-list" aria-hidden="true"></i>
                            <span>Lista de deseos</span>
                        </button>
                    </li>
                @endif

                @if ($tipo === 'obsequios')
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="monitoreo-tab-obsequio"
                                data-bs-toggle="tab" data-bs-target="#monitoreo-pane-obsequio"
                                data-monitoreo-tab="obsequio" role="tab"
                                aria-controls="monitoreo-pane-obsequio" aria-selected="false">
                            <i class="fa-solid fa-gift" aria-hidden="true"></i>
                            <span>Obsequio</span>
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="monitoreo-tab-consumibles"
                                data-bs-toggle="tab" data-bs-target="#monitoreo-pane-consumibles"
                                data-monitoreo-tab="consumibles" role="tab"
                                aria-controls="monitoreo-pane-consumibles" aria-selected="false">
                            <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                            <span>Consumibles</span>
                        </button>
                    </li>
                @endif

                @if ($tipo === 'pistas')
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="monitoreo-tab-recibidas"
                                data-bs-toggle="tab" data-bs-target="#monitoreo-pane-recibidas"
                                data-monitoreo-tab="recibidas" role="tab"
                                aria-controls="monitoreo-pane-recibidas" aria-selected="false">
                            <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                            <span>Pistas del amigo secreto</span>
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="monitoreo-tab-enviadas"
                                data-bs-toggle="tab" data-bs-target="#monitoreo-pane-enviadas"
                                data-monitoreo-tab="enviadas" role="tab"
                                aria-controls="monitoreo-pane-enviadas" aria-selected="false">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            <span>Pistas al amigo secreto</span>
                        </button>
                    </li>
                @endif
            </ul>

            <div class="tab-content participantes-tabs__content">
                <div class="tab-pane fade show active" id="monitoreo-pane-participante"
                     role="tabpanel" aria-labelledby="monitoreo-tab-participante" tabindex="0">
                    <div id="monitoreo-participante" aria-live="polite">
                        <p class="participantes-empty">Cargando…</p>
                    </div>
                </div>

                @if ($tipo === 'deseos')
                    <div class="tab-pane fade" id="monitoreo-pane-deseos"
                         role="tabpanel" aria-labelledby="monitoreo-tab-deseos" tabindex="0">
                        <div id="monitoreo-deseos" aria-live="polite"></div>
                    </div>
                @endif

                @if ($tipo === 'obsequios')
                    <div class="tab-pane fade" id="monitoreo-pane-obsequio"
                         role="tabpanel" aria-labelledby="monitoreo-tab-obsequio" tabindex="0">
                        <div id="monitoreo-obsequio" aria-live="polite"></div>
                    </div>

                    <div class="tab-pane fade" id="monitoreo-pane-consumibles"
                         role="tabpanel" aria-labelledby="monitoreo-tab-consumibles" tabindex="0">
                        <div class="table-responsive">
                            <table id="monitoreo-consumibles-table"
                                   class="table table-striped align-middle w-100 participantes-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Descripción</th>
                                        <th scope="col" class="col-numero">Cantidad</th>
                                        <th scope="col" class="col-valor">Monto US$</th>
                                        <th scope="col" class="col-valor">Monto Bs.</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <p class="participantes-totales" id="monitoreo-consumibles-totales" aria-live="polite"></p>
                    </div>
                @endif

                @if ($tipo === 'pistas')
                    <div class="tab-pane fade" id="monitoreo-pane-recibidas"
                         role="tabpanel" aria-labelledby="monitoreo-tab-recibidas" tabindex="0">
                        <div class="table-responsive">
                            <table id="monitoreo-pistas-recibidas-table"
                                   class="table table-striped align-middle w-100 participantes-tabla">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-fecha">Fecha</th>
                                        <th scope="col" class="col-texto">Pista recibida</th>
                                        <th scope="col" class="col-estado">Leída</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="monitoreo-pane-enviadas"
                         role="tabpanel" aria-labelledby="monitoreo-tab-enviadas" tabindex="0">
                        <div class="table-responsive">
                            <table id="monitoreo-pistas-enviadas-table"
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
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>
