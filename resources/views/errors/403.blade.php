@extends('layouts.base')

@section('title', 'Acceso denegado · Amigo Secreto Inclusivo')

@section('content')
<main class="error-page" id="contenido">
    <p class="error-code">403</p>
    <h1 class="error-title">Acceso denegado</h1>
    <p class="error-text">No tienes permiso para entrar a este módulo.</p>

    <a class="btn btn-primary btn-lg" href="{{ route('dashboard') }}">
        Volver al panel
    </a>
</main>
@endsection
