@php($links = $links ?? [])
@php($adminLinks = $adminLinks ?? [])
@php($label = $label ?? 'En esta página')

@if (! empty($links) || ! empty($adminLinks))
    <nav class="help-toc" aria-label="{{ $label }}">
        @if (! empty($links))
            <div class="help-toc__group">
                <p class="help-toc__title">{{ $label }}</p>

                <ul class="help-toc__list">
                    @foreach ($links as $link)
                        <li>
                            <a class="help-toc__link" href="#{{ $link['anchor'] }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! empty($adminLinks))
            <div class="help-toc__group help-toc__group--admin">
                <p class="help-toc__title help-toc__title--admin">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>Administración</span>
                </p>

                <ul class="help-toc__list">
                    @foreach ($adminLinks as $link)
                        <li>
                            <a class="help-toc__link" href="#{{ $link['anchor'] }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>
@endif
