@php($admin = $admin ?? false)
@php($article = $article ?? null)

@if ($article)
    <section class="help-card help-section {{ $admin ? 'help-section--admin' : '' }}"
             id="{{ $article->slug }}"
             aria-labelledby="titulo-{{ $article->slug }}">
        <h2 class="help-section__title" id="titulo-{{ $article->slug }}">
            @if ($article->icon)
                <span class="help-section__icon" aria-hidden="true">
                    <i class="fa-solid {{ $article->icon }}"></i>
                </span>
            @endif

            <span>{{ $article->title }}</span>

            @if ($admin)
                <span class="help-admin-badge">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    Solo administración
                </span>
            @endif
        </h2>

        @include('ayuda.partials.blocks', ['blocks' => $article->body])
    </section>
@endif
