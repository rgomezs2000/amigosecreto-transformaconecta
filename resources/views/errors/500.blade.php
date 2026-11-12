@extends(isset($shell) ? 'layouts.app' : 'layouts.error')

@section('title', 'Error 500 · Amigo Secreto Inclusivo')
@section('shell-title', 'Error 500')
@section('shell-eyebrow', 'Error del servidor')

@if (isset($shell))
    @section('page')
        @include('errors.partials.content', [
            'code' => '500',
            'title' => 'Error del servidor',
            'message' => 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.',
        ])
    @endsection
@else
    @section('error')
        @include('errors.partials.content', [
            'code' => '500',
            'title' => 'Error del servidor',
            'message' => 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.',
        ])
    @endsection
@endif
