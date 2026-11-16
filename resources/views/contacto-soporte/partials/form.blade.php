@php($types = $types ?? \App\Models\ChatReport::TYPES)
@php($endpoint = $endpoint ?? route('contacto-soporte.crear'))
@php($mode = $mode ?? 'user')

<form id="reporte-form" class="reporte-form" data-endpoint="{{ $endpoint }}" data-mode="{{ $mode }}" novalidate>
    @csrf
    <input type="hidden" id="reporte-context-url" name="context_url" value="">
    <input type="hidden" id="reporte-context-label" name="context_label" value="">

    <div class="field">
        <label for="reporte-tipo" class="form-label">Tipo de reporte</label>
        <select class="form-select" id="reporte-tipo" name="type" required>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="field">
        <label for="reporte-motivo" class="form-label">Motivo</label>
        <input type="text" class="form-control" id="reporte-motivo" name="reason"
               maxlength="2000" placeholder="Resume el motivo del caso." required>
    </div>

    <div class="field">
        <label for="reporte-mensaje" class="form-label">Mensaje</label>
        <textarea class="form-control" id="reporte-mensaje" name="message" rows="5"
                  maxlength="5000" placeholder="Explica con detalle qué ocurrió o qué necesitas." required></textarea>
        <p class="form-text">
            Este mensaje lo lee la administración antes de tomar el caso y se copia al chat del ticket.
        </p>
    </div>

    <div id="reporte-sugerencia-wrap" hidden>
        <div class="field">
            <label for="reporte-sugerencia" class="form-label">Sugerencia</label>
            <textarea class="form-control" id="reporte-sugerencia" name="suggestion" rows="4"
                      maxlength="5000" placeholder="Escribe la sugerencia para la administración."></textarea>
        </div>

        <fieldset class="reporte-encuesta-fieldset">
            <legend class="form-label">Valora estos aspectos</legend>
            <p class="form-text">Elige una cara para cada aspecto. No se muestra un puntaje.</p>
            @include('contacto-soporte.partials.encuesta')
        </fieldset>
    </div>

    <div class="field">
        <label for="reporte-links" class="form-label">Enlaces de soporte (opcional)</label>
        <textarea class="form-control" id="reporte-links" name="links" rows="2"
                  placeholder="Un enlace por línea. Ejemplo: https://..."></textarea>
    </div>

    <div class="field">
        <span class="form-label d-block">Archivos de soporte (opcional)</span>
        <div class="chat-report-dropzone" id="reporte-dropzone" tabindex="0" role="button"
             aria-controls="reporte-files" aria-describedby="reporte-files-help">
            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
            <p class="chat-report-dropzone__title">Haz clic o arrastra archivos aquí</p>
            <p class="chat-report-dropzone__help" id="reporte-files-help">
                Imágenes, videos, audios o documentos. Puedes adjuntar hasta 10 archivos de 50 MB.
            </p>
            <input type="file" id="reporte-files" multiple hidden>
        </div>
        <ul class="chat-report-file-list" id="reporte-file-list"></ul>
    </div>

    <p class="field-message" id="reporte-status" role="status" aria-live="polite"></p>
</form>
