@php
    $steps = [
        1 => 'Creación / Unión',
        2 => 'Sorteo ciego',
        3 => 'Lista de deseos',
        4 => 'Intercambio de pistas',
        5 => 'Gran revelación',
    ];
@endphp

<nav class="stepper" aria-label="Fases del amigo secreto">
    <ol class="stepper__list">
        @foreach ($steps as $number => $label)
            @php
                $state = $number < $currentStep
                    ? 'complete'
                    : ($number === $currentStep ? 'active' : 'pending');
            @endphp

            <li class="stepper__item stepper__item--{{ $state }}"
                @if ($state === 'active') aria-current="step" @endif>
                <span class="stepper__marker">
                    @if ($state === 'complete')
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                    @else
                        {{ $number }}
                    @endif
                </span>
                <span class="stepper__label">{{ $label }}</span>
            </li>
        @endforeach
    </ol>
</nav>
