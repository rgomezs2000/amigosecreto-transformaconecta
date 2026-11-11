@extends('layouts.base')

@section('content')
<div class="dashboard-page error-shell" id="contenido">
    <header class="dashboard-top">
        <div class="dashboard-heading">
            <p class="dashboard-eyebrow">Amigo Secreto Inclusivo</p>
            <h1 class="dashboard-title">@yield('shell-title', 'Error')</h1>
        </div>
    </header>

    @yield('error')
</div>
@endsection
