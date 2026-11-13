@extends(isset($shell) ? 'layouts.app' : 'layouts.error')

@section('title', 'Error 419 · Amigo Secreto Inclusivo')
@section('shell-title', 'Error 419')
@section('shell-eyebrow', 'Sesión expirada')

@if (isset($shell))
    @section('page')
        @include('errors.partials.content', [
            'code' => '419',
            'title' => 'Sesión expirada',
            'message' => 'Tu sesión o el token de seguridad expiró. Vuelve a iniciar sesión para continuar.',
        ])
    @endsection
@else
    @section('error')
        @include('errors.partials.content', [
            'code' => '419',
            'title' => 'Sesión expirada',
            'message' => 'Tu sesión o el token de seguridad expiró. Vuelve a iniciar sesión para continuar.',
        ])
    @endsection
@endif
