@extends('layouts.base')

@section('title', $module->name . ' · Amigo Secreto Inclusivo')

@section('content')
<main class="module-page" id="contenido">
    <a class="module-back" href="{{ route('dashboard') }}">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        <span>Volver al panel</span>
    </a>

    <h1 class="module-title">{{ $module->name }}</h1>
    <p class="module-subtitle">Este módulo está en construcción.</p>
</main>
@endsection
