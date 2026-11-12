<div class="chat-widget" id="chat-widget"
     data-url-resumen="{{ route('chat.resumen') }}"
     data-url-abrir="{{ route('chat.abrir') }}"
     data-url-grupo="{{ route('chat.grupo') }}"
     data-url-secret="{{ route('chat.amigo-secreto') }}"
     data-url-chat="{{ url('/chat') }}"
     data-url-preferencias="{{ route('notificaciones.preferencias') }}"
     data-sound="{{ ($shell['notificationPreferences']['chat_sound'] ?? false) ? '1' : '0' }}"
     data-in-app="{{ ($shell['notificationPreferences']['in_app'] ?? true) ? '1' : '0' }}"
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
            <div class="chat-panel__actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-sound-toggle"
                        aria-pressed="false" aria-label="Sonido del chat" title="Sonido del chat">
                    <i class="fa-solid fa-volume-xmark" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-close" aria-label="Cerrar mensajes">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="chat-tabs" role="tablist">
            <button type="button" class="chat-tab is-active" data-tab="conversations" role="tab">Conversaciones</button>
            <button type="button" class="chat-tab" data-tab="users" role="tab">Usuarios</button>
            <button type="button" class="chat-tab" data-tab="secret" role="tab">Amigo Secreto</button>
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

            <div class="chat-tab-panel" data-panel="secret" hidden>
                <p class="chat-hint">El nombre de tu amigo secreto no se muestra. Solo verás si está en línea.</p>
                <div class="chat-list" id="chat-secret">
                    <p class="chat-hint">Aún no tienes amigo secreto asignado.</p>
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
                    <p class="chat-conversation__hint">
                        <span class="chat-status" id="chat-status" aria-hidden="true"></span>
                        <span id="chat-subtitle"></span>
                    </p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-gallery" aria-label="Multimedia">
                    <i class="fa-regular fa-images" aria-hidden="true"></i>
                </button>
            </header>

            <div class="chat-gallery-panel" id="chat-gallery-panel" hidden>
                <div class="chat-tabs">
                    <button type="button" class="chat-tab is-active" data-gallery="multimedia">Multimedia</button>
                    <button type="button" class="chat-tab" data-gallery="documents">Documentos</button>
                    <button type="button" class="chat-tab" data-gallery="audios">Audios</button>
                    <button type="button" class="chat-tab" data-gallery="links">Links</button>
                </div>
                <div class="chat-list" id="chat-gallery-list"></div>
            </div>

            <div class="chat-link-preview" id="chat-link-preview" hidden>
                <img id="chat-link-image" alt="" hidden>
                <div class="chat-link-preview__text">
                    <p id="chat-link-title"></p>
                    <p id="chat-link-description"></p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-link-close" aria-label="Quitar vista previa">×</button>
            </div>

            <div class="chat-messages" id="chat-messages" aria-live="polite"></div>

            <div class="chat-tray" id="chat-attachment-tray" hidden>
                <div class="chat-tray__items" id="chat-tray-items"></div>
                <button type="button" class="chat-tray__clear" id="chat-tray-clear" aria-label="Quitar adjuntos" title="Quitar adjuntos">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <form class="chat-form" id="chat-form">
                <input type="file" id="chat-file" hidden multiple>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach" aria-label="Adjuntar archivo" title="Adjuntar">
                    <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-emoji-toggle" aria-label="Emojis" title="Emojis">
                    <i class="fa-regular fa-face-smile" aria-hidden="true"></i>
                </button>
                <div class="form-control chat-input" id="chat-input" contenteditable="true" role="textbox"
                     aria-label="Escribe un mensaje" data-placeholder="Escribe un mensaje..."></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-mic" aria-label="Grabar audio" title="Grabar audio">
                    <i class="fa-solid fa-microphone" aria-hidden="true"></i>
                </button>
                <button type="submit" class="btn btn-primary" aria-label="Enviar mensaje">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </button>
            </form>

            <div class="chat-recorder" id="chat-recorder" hidden>
                <span class="chat-recorder__dot" aria-hidden="true"></span>
                <span class="chat-recorder__time" id="chat-recorder-time">0:00</span>
                <canvas class="chat-recorder__wave" id="chat-recorder-wave" width="180" height="36"></canvas>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-recorder-cancel" aria-label="Cancelar audio" title="Cancelar audio">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="chat-recorder-send" aria-label="Enviar audio" title="Enviar audio">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </button>
            </div>

            <div class="chat-attach-menu" id="chat-attach-menu" hidden>
                <button type="button" class="chat-context-item" data-attach="camera-photo">Cámara: foto</button>
                <button type="button" class="chat-context-item" data-attach="camera-video">Cámara: video</button>
                <button type="button" class="chat-context-item" data-attach="image">Imágenes</button>
                <button type="button" class="chat-context-item" data-attach="video">Videos</button>
                <button type="button" class="chat-context-item" data-attach="file">Archivos y documentos</button>
                <button type="button" class="chat-context-item" data-attach="contact">Contacto</button>
                <button type="button" class="chat-context-item" data-attach="location">Ubicación</button>
            </div>

            <input type="file" id="chat-camera-photo" accept="image/*" capture="environment" hidden>
            <input type="file" id="chat-camera-video" accept="video/*" capture="environment" hidden>
            <input type="file" id="chat-image-picker" accept="image/*" hidden>
            <input type="file" id="chat-video-picker" accept="video/*" hidden>
            <input type="file" id="chat-file-picker" hidden>
            <input type="file" id="chat-contact-picker" accept=".vcf,text/vcard,text/x-vcard" hidden>

            <p class="chat-file-name" id="chat-file-name" hidden></p>
        </div>

        <div class="chat-lightbox" id="chat-lightbox" hidden>
            <div class="chat-lightbox__stage" id="chat-lightbox-stage"></div>
            <div class="chat-lightbox__footer">
                <div class="chat-lightbox__actions">
                    <button type="button" class="chat-lightbox__icon" id="chat-lightbox-copy" aria-label="Copiar" title="Copiar">
                        <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    </button>
                    <a class="chat-lightbox__icon" id="chat-lightbox-download" download aria-label="Descargar" title="Descargar">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>
                    </a>
                    <button type="button" class="chat-lightbox__icon" id="chat-lightbox-close" aria-label="Cerrar" title="Cerrar">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <p class="chat-lightbox__title" id="chat-lightbox-name"></p>
            </div>
        </div>

        <div class="chat-context-menu" id="chat-context-menu" hidden>
            <button type="button" class="chat-context-item" data-action="resend" hidden>Volver a enviar</button>
            <button type="button" class="chat-context-item" data-action="copy">Copiar</button>
            <button type="button" class="chat-context-item" data-action="reaction">Reaccionar</button>
            <button type="button" class="chat-context-item" data-action="edit" hidden>Editar</button>
            <button type="button" class="chat-context-item" data-action="delete" hidden>Eliminar</button>
            <button type="button" class="chat-context-item" data-action="report" hidden>Reportar</button>
        </div>

        <div class="chat-text-menu" id="chat-text-menu" hidden>
            <button type="button" class="chat-context-item" data-text="selectAll">Seleccionar todo</button>
            <button type="button" class="chat-context-item" data-text="cut">Cortar</button>
            <button type="button" class="chat-context-item" data-text="copy">Copiar</button>
            <button type="button" class="chat-context-item" data-text="paste">Pegar</button>
            <button type="button" class="chat-context-item" data-text="bold"><strong>Negrita</strong></button>
            <button type="button" class="chat-context-item" data-text="italic"><em>Cursiva</em></button>
            <button type="button" class="chat-context-item" data-text="underline"><u>Subrayado</u></button>
            <button type="button" class="chat-context-item" data-text="strikeThrough"><s>Tachado</s></button>
        </div>

        <div class="chat-emoji-palette" id="chat-emoji-palette" hidden></div>
    </section>
</div>

@push('scripts')
    <script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
@endpush
