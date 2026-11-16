@php($aspects = $aspects ?? \App\Models\ChatReportRating::ASPECTS)
@php($labels = $labels ?? \App\Models\ChatReportRating::LABELS)
@php($icons = [1 => 'fa-face-frown', 2 => 'fa-face-meh', 3 => 'fa-face-meh-blank', 4 => 'fa-face-smile', 5 => 'fa-face-grin-stars'])

<div class="reporte-encuesta" role="group" aria-label="Valoración de la sugerencia">
    @foreach ($aspects as $aspect => $aspectLabel)
        <div class="reporte-encuesta__aspecto" data-aspecto="{{ $aspect }}">
            <p class="reporte-encuesta__titulo">{{ $aspectLabel }}</p>

            <div class="reporte-encuesta__caras" role="group" aria-label="Valora {{ $aspectLabel }}">
                @foreach ($labels as $score => $label)
                    <button type="button"
                            class="reporte-cara reporte-cara--{{ $score }}"
                            data-reporte-cara
                            data-aspecto="{{ $aspect }}"
                            data-score="{{ $score }}"
                            aria-pressed="false"
                            aria-label="{{ $label }}">
                        <i class="fa-solid {{ $icons[$score] }}" aria-hidden="true"></i>
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>

            <input type="hidden" name="ratings[{{ $aspect }}]" id="reporte-rating-{{ $aspect }}" value="">
            <p class="reporte-encuesta__cualitativa" id="reporte-rating-label-{{ $aspect }}">Sin valorar</p>
        </div>
    @endforeach
</div>
