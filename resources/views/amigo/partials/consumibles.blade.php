<article class="sorteo-card amigo-consumibles" aria-labelledby="amigo-consumibles-title">
    <header class="sorteo-card__head">
        <div>
            <h2 class="sorteo-card__title" id="amigo-consumibles-title">
                <i class="fa-solid fa-cookie-bite" aria-hidden="true"></i>
                <span>Obsequios consumibles</span>
            </h2>
            <p class="sorteo-card__text">
                Agrega los detalles que se consumen (chocolates, café, dulces…). Cada uno con su
                valor en US$ y su equivalente en Bs. a la tasa oficial del BCV.
            </p>
        </div>

        <button type="button" class="btn btn-primary" id="amigo-consumible-nuevo"
                @disabled(! $puedeEditar)>
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            <span>Nuevo consumible</span>
        </button>
    </header>

    @unless ($puedeEditar)
        <p class="sorteo-note">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span>El evento ya finalizó: los consumibles quedan en solo lectura.</span>
        </p>
    @endunless

    <p class="amigo-form-status" id="amigo-consumibles-status" role="status" aria-live="polite"></p>

    <div class="table-responsive">
        <table id="amigo-consumibles-table"
               class="table table-striped align-middle w-100 amigo-tabla"
               style="width:100% !important;">
            <thead>
                <tr>
                    <th scope="col" class="amigo-col-fecha">Fecha</th>
                    <th scope="col" class="amigo-col-texto">Obsequio</th>
                    <th scope="col" class="amigo-col-valor">Valor US$</th>
                    <th scope="col" class="amigo-col-valor">Valor Bs.</th>
                    <th scope="col" class="amigo-col-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <th scope="col" class="amigo-col-fecha">Fecha</th>
                    <th scope="col" class="amigo-col-texto">Obsequio</th>
                    <th scope="col" class="amigo-col-valor">Valor US$</th>
                    <th scope="col" class="amigo-col-valor">Valor Bs.</th>
                    <th scope="col" class="amigo-col-acciones">Acciones</th>
                </tr>
            </tfoot>
        </table>
    </div>
</article>
