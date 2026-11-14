@php($admin = $admin ?? false)
@php($openFirst = $openFirst ?? false)
@php($topicAnchor = 'tema-' . \Illuminate\Support\Str::slug($topic))

<section class="help-card help-section {{ $admin ? 'help-section--admin' : '' }}"
         id="{{ $topicAnchor }}"
         aria-labelledby="titulo-{{ $topicAnchor }}">
    <h2 class="help-section__title" id="titulo-{{ $topicAnchor }}">
        <span class="help-section__icon" aria-hidden="true">
            <i class="fa-solid {{ $admin ? 'fa-lock' : 'fa-circle-question' }}"></i>
        </span>

        <span>{{ $topic }}</span>

        @if ($admin)
            <span class="help-admin-badge">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                Solo administración
            </span>
        @endif
    </h2>

    <div class="help-faq">
        @foreach ($questions as $question)
            <details class="help-faq__item" @if ($openFirst && $loop->first) open @endif>
                <summary class="help-faq__question">
                    <span>{{ $question->title }}</span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </summary>

                <div class="help-faq__answer">
                    @include('ayuda.partials.blocks', ['blocks' => $question->body])
                </div>
            </details>
        @endforeach
    </div>
</section>
