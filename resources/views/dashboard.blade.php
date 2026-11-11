@extends('layouts.app')

@section('title', 'Panel de control · Amigo Secreto Inclusivo')

@section('page')
    @include('partials.stepper', ['currentStep' => $shell['currentStep']])

    <section class="dashboard-modules" aria-label="Módulos disponibles">
        <h2 class="dashboard-modules__title">Módulos disponibles</h2>

        @if ($shell['modules']->isEmpty())
            <p class="menu-empty">No tienes módulos asignados. Contacta al administrador.</p>
        @else
            <div class="module-panels">
                @foreach ($shell['groups'] as $group => $groupModules)
                    @php($groupSlug = \Illuminate\Support\Str::slug($group))

                    <section class="module-panel-group module-panel-group--{{ $groupSlug }}"
                             aria-labelledby="module-group-{{ $groupSlug }}">
                        <h3 class="module-panel-group__title" id="module-group-{{ $groupSlug }}">
                            {{ $group }}
                        </h3>

                        <div class="module-grid">
                            @foreach ($groupModules as $module)
                                @php($moduleGroup = $module->category?->name ?? $module->group)

                                <a class="module-tile module-tile--{{ \Illuminate\Support\Str::slug($moduleGroup) }}"
                                   href="{{ $module->url() }}">
                                    <span class="module-tile__icon">
                                        <i class="fa-solid {{ $module->icon }}" aria-hidden="true"></i>
                                    </span>
                                    <span class="module-tile__name">{{ $module->name }}</span>
                                    <span class="module-tile__group">{{ $moduleGroup }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </section>
@endsection
