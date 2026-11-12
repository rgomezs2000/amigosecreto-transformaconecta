<div class="chat-widget" id="chat-widget"
     data-url-resumen="{{ route('chat.resumen') }}"
     data-url-abrir="{{ route('chat.abrir') }}"
     data-url-grupo="{{ route('chat.grupo') }}"
     data-url-chat="{{ url('/chat') }}"
     data-sound="{{ ($shell['notificationSettings']->sound_enabled ?? false) ? '1' : '0' }}"
     data-in-app="{{ ($shell['notificationSettings']->in_app_enabled ?? true) ? '1' : '0' }}"
     data-is-admin="{{ $shell['user']->role === \App\Enums\UserRole::Admin ? '1' : '0' }}"
     data-csrf="{{ csrf_token() }}">
    <button type="button" class="chat-toggle" id="chat-toggle"
            aria-expanded="false" aria-controls="chat-panel" aria-label="Abrir mensajes">
        <i class="fa-solid fa-comments" aria-hidden="true"></i>
        <span class="chat-toggle__badge" id="chat-badge" hidden>0</span>
    </button>

    <section class="chat-panel" id="chat-panel" hidden aria-label="Mensajes">
        <header class="chat-panel__head">
            <div>
                <p class="chat-panel__eyebrow">Mensajes</p>
                <h2 class="chat-panel__title">Buzón de entrada</h2>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-close" aria-label="Cerrar mensajes">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="chat-tabs" role="tablist">
            <button type="button" class="chat-tab is-active" data-tab="conversations" role="tab">Conversaciones</button>
            <button type="button" class="chat-tab" data-tab="users" role="tab">Usuarios</button>
            @if ($shell['user']->role === \App\Enums\UserRole::Admin)
                <button type="button" class="chat-tab" data-tab="moderation" role="tab">Moderación</button>
            @endif
        </div>

        <div class="chat-body" id="chat-body">
            <div class="chat-tab-panel" data-panel="conversations">
                <div class="chat-list" id="chat-conversations">
                    <p class="chat-hint">Aún no tienes conversaciones.</p>
                </div>
            </div>

            <div class="chat-tab-panel" data-panel="users" hidden>
                @if ($shell['user']->role === \App\Enums\UserRole::Admin)
                    <div class="chat-group">
                        <input type="text" class="form-control" id="chat-group-name"
                               placeholder="Nombre del grupo" maxlength="120">
                        <div class="chat-group__users" id="chat-group-users"></div>
                        <button type="button" class="btn btn-sm btn-primary" id="chat-group-create">Crear grupo</button>
                        <p class="chat-hint" id="chat-group-error" role="status" aria-live="polite"></p>
                    </div>
                @endif

                <div class="chat-list" id="chat-users">
                    <p class="chat-hint">Cargando usuarios...</p>
                </div>
            </div>

            @if ($shell['user']->role === \App\Enums\UserRole::Admin)
                <div class="chat-tab-panel" data-panel="moderation" hidden>
                    <p class="chat-hint">Aquí ves todas las conversaciones y grupos, aunque no participes en ellos. Entra para moderar; tus mensajes se muestran como «Moderación».</p>
                    <div class="chat-list" id="chat-moderation">
                        <p class="chat-hint">Cargando conversaciones...</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="chat-conversation" id="chat-conversation" hidden>
            <header class="chat-conversation__head">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-back" aria-label="Volver">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div>
                    <p class="chat-conversation__title" id="chat-title"></p>
                    <p class="chat-conversation__hint" id="chat-subtitle"></p>
                </div>
            </header>

            <div class="chat-messages" id="chat-messages" aria-live="polite"></div>

            <form class="chat-form" id="chat-form">
                <input type="file" id="chat-file" hidden
                       accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach" aria-label="Adjuntar archivo" title="Adjuntar">
                    <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-emoji-toggle" aria-label="Emojis" title="Emojis">
                    <i class="fa-regular fa-face-smile" aria-hidden="true"></i>
                </button>
                <input type="text" class="form-control" id="chat-input"
                       placeholder="Escribe un mensaje..." maxlength="2000" autocomplete="off">
                <button type="submit" class="btn btn-primary" aria-label="Enviar mensaje">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </button>
            </form>

            <p class="chat-file-name" id="chat-file-name" hidden></p>
        </div>

        <div class="chat-context-menu" id="chat-context-menu" hidden>
            <button type="button" class="chat-context-item" data-action="reaction">Reaccionar</button>
            <button type="button" class="chat-context-item" data-action="edit" hidden>Editar</button>
            <button type="button" class="chat-context-item" data-action="delete" hidden>Eliminar</button>
            <button type="button" class="chat-context-item" data-action="report" hidden>Reportar</button>
        </div>

        <div class="chat-emoji-palette" id="chat-emoji-palette" hidden></div>
    </section>
</div>

@push('scripts')
    <script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
@endpush
