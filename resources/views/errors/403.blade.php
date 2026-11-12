@extends(isset($shell) ? 'layouts.app' : 'layouts.error')

@section('title', 'Error 403 · Amigo Secreto Inclusivo')
@section('shell-title', 'Error 403')
@section('shell-eyebrow', 'Acceso denegado')

@if (isset($shell))
    @section('page')
        @include('errors.partials.content', [
            'code' => '403',
            'title' => 'Acceso denegado',
            'message' => 'No tienes permiso para entrar a este módulo.',
        ])
    @endsection
@else
    @section('error')
        @include('errors.partials.content', [
            'code' => '403',
            'title' => 'Acceso denegado',
            'message' => 'No tienes permiso para entrar a este módulo.',
        ])
    @endsection
@endif
