@extends('layouts.app')

@section('title', 'Chat · Amigo Secreto Inclusivo')
@section('shell-title', 'Chat')
@section('shell-eyebrow', 'Mensajes')

@section('page')
    <section class="chat-page" aria-label="Chat">
        <div class="chat-page__card">
            <h2 class="eventos-title">Buzón de entrada</h2>
            <p class="eventos-subtitle">
                Usa el botón flotante de mensajes, a la derecha de la pantalla, para abrir tus conversaciones,
                escribir y ver quién está en línea.
            </p>
            <p class="eventos-subtitle">
                Las notificaciones del chat son internas: no se envían por correo.
            </p>
            <button type="button" class="btn btn-primary" id="chat-page-open">
                <i class="fa-solid fa-comments" aria-hidden="true"></i>
                <span>Abrir mensajes</span>
            </button>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.getElementById('chat-page-open').addEventListener('click', function () {
            var toggle = document.getElementById('chat-toggle');

            if (toggle) {
                toggle.click();
            }
        });
    </script>
@endpush
