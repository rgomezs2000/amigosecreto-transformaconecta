@php($blocks = $blocks ?? [])

@foreach ($blocks as $block)
    @switch($block['type'] ?? '')
        @case('paragraph')
            <p class="help-text">{{ $block['text'] ?? '' }}</p>
            @break

        @case('list')
            <ul class="help-list">
                @foreach ($block['items'] ?? [] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
            @break

        @case('steps')
            <ol class="help-steps">
                @foreach ($block['items'] ?? [] as $item)
                    <li class="help-step">
                        @if (is_array($item))
                            @if (! empty($item['title']))
                                <p class="help-step__title">{{ $item['title'] }}</p>
                            @endif

                            @if (! empty($item['text']))
                                <p class="help-step__text">{{ $item['text'] }}</p>
                            @endif
                        @else
                            <p class="help-step__text">{{ $item }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            @break

        @case('note')
            @php($tone = in_array($block['tone'] ?? 'info', ['info', 'important', 'tip'], true) ? $block['tone'] : 'info')
            @php($toneIcon = $tone === 'important' ? 'fa-triangle-exclamation' : ($tone === 'tip' ? 'fa-lightbulb' : 'fa-circle-info'))

            <aside class="help-note help-note--{{ $tone }}" aria-label="{{ $block['title'] ?? 'Nota' }}">
                <i class="fa-solid {{ $toneIcon }}" aria-hidden="true"></i>
                <div class="help-note__body">
                    @if (! empty($block['title']))
                        <p class="help-note__title">{{ $block['title'] }}</p>
                    @endif
                    <p class="help-note__text">{{ $block['text'] ?? '' }}</p>
                </div>
            </aside>
            @break

        @case('facts')
            <dl class="help-facts">
                @foreach ($block['items'] ?? [] as $fact)
                    <div class="help-facts__item">
                        <dt>{{ $fact['label'] ?? '' }}</dt>
                        <dd>{{ $fact['value'] ?? '' }}</dd>
                    </div>
                @endforeach
            </dl>
            @break

        @case('version')
            <div class="help-version">
                <p class="help-version__label">Versión actual</p>
                <p class="help-version__number">{{ $version ?? '0.1' }}</p>

                @if (! empty($settings['status']))
                    <p class="help-version__status">{{ $settings['status'] }}</p>
                @endif

                @if (! empty($settings['notes']))
                    <p class="help-version__note">{{ $settings['notes'] }}</p>
                @endif
            </div>
            @break
    @endswitch
@endforeach
