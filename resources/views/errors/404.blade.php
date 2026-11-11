@extends(isset($shell) ? 'layouts.app' : 'layouts.error')

@section('title', 'Error 404 · Amigo Secreto Inclusivo')
@section('shell-title', 'Error 404')
@section('shell-eyebrow', 'Página no encontrada')

@section('page')
    @include('errors.partials.content', [
        'code' => '404',
        'title' => 'Página no encontrada',
        'message' => 'La página o el módulo que buscas no existe.',
    ])
@endsection

@section('error')
    @include('errors.partials.content', [
        'code' => '404',
        'title' => 'Página no encontrada',
        'message' => 'La página o el módulo que buscas no existe.',
    ])
@endsection
