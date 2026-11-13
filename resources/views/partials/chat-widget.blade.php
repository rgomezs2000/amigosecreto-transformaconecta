<div class="chat-widget" id="chat-widget"
     data-url-resumen="{{ route('chat.resumen') }}"
     data-url-contador="{{ route('chat.contador') }}"
     data-url-abrir="{{ route('chat.abrir') }}"
     data-url-grupo="{{ route('chat.grupo') }}"
     data-url-secret="{{ route('chat.amigo-secreto') }}"
     data-url-chat="{{ url('/chat') }}"
     data-url-destinatarios="{{ route('chat.destinatarios') }}"
     data-url-preferencias="{{ route('notificaciones.preferencias') }}"
     data-sound="{{ ($shell['notificationPreferences']['chat_sound'] ?? false) ? '1' : '0' }}"
     data-in-app="{{ ($shell['notificationPreferences']['in_app'] ?? true) ? '1' : '0' }}"
     data-is-admin="{{ $shell['user']->role === \App\Enums\UserRole::Admin ? '1' : '0' }}"
     data-invitation="{{ $shell['chatInvitation'] ?? '' }}"
     data-invitation-notice="{{ $shell['chatInvitationNotice'] ?? '' }}"
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
            <button type="button" class="chat-tab" data-tab="groups" role="tab">Grupos</button>
            <button type="button" class="chat-tab" data-tab="reports" role="tab">
                Reportes
                <span class="chat-tab__count" id="chat-reports-count" hidden>0</span>
            </button>
            <button type="button" class="chat-tab" data-tab="archived" role="tab">
                Archivadas
                <span class="chat-tab__count" id="chat-archived-count" hidden>0</span>
            </button>
            <button type="button" class="chat-tab" data-tab="users" role="tab">Usuarios</button>
            {{-- El amigo secreto es de cada pareja: el administrador solo
                 monitorea y no tiene chat de amigo secreto. --}}
            @if ($shell['user']->role !== \App\Enums\UserRole::Admin)
                <button type="button" class="chat-tab" data-tab="secret" role="tab">Amigo Secreto</button>
            @endif
            @if ($shell['user']->role === \App\Enums\UserRole::Admin)
                <button type="button" class="chat-tab" data-tab="moderation" role="tab">
                    Moderación
                    <span class="chat-tab__count" id="chat-moderation-count" hidden>0</span>
                </button>
            @endif
        </div>

        <div class="chat-body" id="chat-body">
            <div class="chat-tab-panel" data-panel="conversations">
                <div class="chat-list" id="chat-conversations">
                    <p class="chat-hint">Aún no tienes conversaciones.</p>
                </div>
            </div>

            <div class="chat-tab-panel" data-panel="groups" hidden>
                <p class="chat-hint">Aquí están los grupos a los que perteneces. Haz clic derecho en un grupo para gestionarlo.</p>
                <div class="chat-list" id="chat-groups">
                    <p class="chat-hint">Aún no perteneces a ningún grupo.</p>
                </div>

                <div class="chat-list-actions">
                    <button type="button" class="btn btn-sm btn-primary" id="chat-group-open">
                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                        <span>Crear grupo</span>
                    </button>
                </div>
            </div>

            <div class="chat-tab-panel" data-panel="reports" hidden>
                <p class="chat-hint">
                    Chats de tus casos de reporte, uno por ticket. Se abren cuando un
                    administrador toma el caso y quedan en solo lectura al cerrarlo.
                </p>
                <div class="chat-list" id="chat-reports">
                    <p class="chat-hint">Aún no tienes chats de reportes.</p>
                </div>
            </div>

            <div class="chat-tab-panel" data-panel="archived" hidden>
                <p class="chat-hint">Conversaciones que archivaste. Mientras estén aquí no se moderan y no cuentan en el contador. Haz clic derecho para deshacer el archivo.</p>
                <div class="chat-list" id="chat-archived">
                    <p class="chat-hint">No tienes conversaciones archivadas.</p>
                </div>
            </div>

            <div class="chat-tab-panel" data-panel="users" hidden>
                <p class="chat-hint">Haz clic derecho sobre una persona para silenciarla, bloquearla o reportarla.</p>
                <div class="chat-list" id="chat-users">
                    <p class="chat-hint">Cargando usuarios...</p>
                </div>
            </div>

            @if ($shell['user']->role !== \App\Enums\UserRole::Admin)
                <div class="chat-tab-panel" data-panel="secret" hidden>
                    <p class="chat-hint">El nombre de tu amigo secreto no se muestra. Solo verás si está en línea.</p>
                    <div class="chat-list" id="chat-secret">
                        <p class="chat-hint">Aún no tienes amigo secreto asignado.</p>
                    </div>
                </div>
            @endif

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
                <button type="button" class="chat-conversation__identity" id="chat-profile-open"
                        aria-label="Abrir perfil de la conversación">
                    <span class="chat-conversation__heading">
                        <span class="chat-conversation__title" id="chat-title"></span>
                        {{-- Etiqueta de rol del sistema: se muestra cuando con
                             quien se habla es administrador. --}}
                        <span class="chat-badge chat-badge--admin" id="chat-title-badge" hidden>Administrador</span>
                    </span>
                    <span class="chat-conversation__hint">
                        <span class="chat-status" id="chat-status" aria-hidden="true"></span>
                        <span id="chat-subtitle"></span>
                    </span>
                </button>
            </header>

            {{-- Cintillo del mensaje fijado. Se desfija con el clic derecho. --}}
            <div class="chat-pin" id="chat-pin" hidden>
                <i class="fa-solid fa-thumbtack" aria-hidden="true"></i>
                <div class="chat-pin__text">
                    <p class="chat-pin__label">Mensaje fijado</p>
                    <p class="chat-pin__body" id="chat-pin-body"></p>
                </div>
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

            {{-- Cita del mensaje que se está respondiendo. Se levanta sobre la
                 barra del mensaje y se cierra sin enviar nada. --}}
            <div class="chat-reply" id="chat-reply" hidden>
                <i class="fa-solid fa-reply" aria-hidden="true"></i>
                <div class="chat-reply__text">
                    <p class="chat-reply__label">
                        Respondiendo a <span id="chat-reply-sender"></span>
                    </p>
                    <p class="chat-reply__body" id="chat-reply-body"></p>
                </div>
                <button type="button" class="chat-reply__close" id="chat-reply-cancel"
                        aria-label="Cancelar respuesta" title="Cancelar respuesta">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="chat-tray" id="chat-attachment-tray" hidden>
                <div class="chat-tray__items" id="chat-tray-items"></div>
                <button type="button" class="chat-tray__clear" id="chat-tray-clear" aria-label="Quitar adjuntos" title="Quitar adjuntos">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            {{-- Sugerencias de etiquetas: se llena al escribir @ en el cuadro. --}}
            <div class="chat-mentions" id="chat-mentions" role="listbox"
                 aria-label="Etiquetas disponibles" hidden></div>

            <p class="chat-readonly" id="chat-readonly" hidden>
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <span id="chat-readonly-label">Chat del evento: solo lectura.</span>
            </p>

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

        <section class="chat-profile" id="chat-profile" hidden aria-label="Perfil de la conversación">
            <header class="chat-profile__head">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-profile-back" aria-label="Volver">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div class="chat-profile__heading">
                    <p class="chat-panel__eyebrow" id="chat-profile-eyebrow">Perfil</p>
                    <h2 class="chat-profile__title" id="chat-profile-title">Perfil</h2>
                </div>
                <span class="chat-profile__head-spacer" aria-hidden="true"></span>
            </header>

            <div class="chat-profile__body" id="chat-profile-body">
                <p class="chat-profile__loading" id="chat-profile-loading">Cargando perfil...</p>

                <div class="chat-profile__section" data-profile-section="main" id="chat-profile-main" hidden></div>

                <div class="chat-profile__section" data-profile-section="members" hidden>
                    <div class="chat-profile__section-head">
                        <h3 class="chat-profile__section-title" id="chat-profile-members-title">Miembros</h3>
                    </div>
                    <label class="chat-profile__search">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="chat-profile-member-search" placeholder="Buscar miembro..." autocomplete="off">
                    </label>
                    <div class="chat-profile__members" id="chat-profile-member-list"></div>
                </div>

                <div class="chat-profile__section" data-profile-section="audit" hidden>
                    <div class="chat-profile__section-head">
                        <h3 class="chat-profile__section-title">Auditoría del grupo</h3>
                    </div>
                    <div class="chat-profile__audit" id="chat-profile-audit-list"></div>
                </div>

                <div class="chat-profile__section chat-profile__section--gallery" data-profile-section="gallery" hidden>
                    <div class="chat-gallery-panel" id="chat-gallery-panel" hidden>
                        <div class="chat-tabs">
                            <button type="button" class="chat-tab is-active" data-gallery="multimedia">Multimedia</button>
                            <button type="button" class="chat-tab" data-gallery="documents">Documentos</button>
                            <button type="button" class="chat-tab" data-gallery="audios">Audios</button>
                            <button type="button" class="chat-tab" data-gallery="links">Links</button>
                        </div>
                        <div class="chat-list" id="chat-gallery-list"></div>
                    </div>
                </div>
            </div>
        </section>

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
                    <button type="button" class="chat-lightbox__icon" id="chat-lightbox-delete" aria-label="Eliminar" title="Eliminar" hidden>
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="chat-lightbox__icon" id="chat-lightbox-close" aria-label="Cerrar" title="Cerrar">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <p class="chat-lightbox__title" id="chat-lightbox-name"></p>
            </div>
            <div class="chat-context-menu chat-lightbox-menu" id="chat-lightbox-menu" hidden>
                <button type="button" class="chat-context-item" data-lightbox-action="copy">Copiar imagen</button>
                <button type="button" class="chat-context-item" data-lightbox-action="download">Descargar</button>
                <button type="button" class="chat-context-item" data-lightbox-action="link">Copiar enlace</button>
                <button type="button" class="chat-context-item chat-context-item--danger" data-lightbox-action="delete">Eliminar</button>
            </div>
        </div>

        {{-- Acciones del contenido de la galería (imágenes, documentos, audios y enlaces). --}}
        <div class="chat-context-menu chat-content-menu" id="chat-content-menu" hidden>
            <button type="button" class="chat-context-item" data-content-action="copy-image">Copiar imagen</button>
            <button type="button" class="chat-context-item" data-content-action="copy-link">Copiar enlace</button>
            <button type="button" class="chat-context-item" data-content-action="download">Descargar</button>
            <button type="button" class="chat-context-item chat-context-item--danger" data-content-action="delete">Eliminar</button>
        </div>

        <div class="chat-context-menu" id="chat-context-menu" hidden>
            <button type="button" class="chat-context-item" data-action="resend" hidden>Volver a enviar</button>
            <button type="button" class="chat-context-item" data-action="reply" hidden>Responder</button>
            <button type="button" class="chat-context-item" data-action="forward" hidden>Reenviar</button>
            <button type="button" class="chat-context-item" data-action="copy">Copiar</button>
            <button type="button" class="chat-context-item" data-action="reaction">Reaccionar</button>
            <button type="button" class="chat-context-item" data-action="pin" hidden>Fijar mensaje</button>
            <button type="button" class="chat-context-item" data-action="unpin" hidden>Desfijar mensaje</button>
            <button type="button" class="chat-context-item" data-action="edit" hidden>Editar</button>
            <button type="button" class="chat-context-item" data-action="delete" hidden>Eliminar</button>
            <button type="button" class="chat-context-item" data-action="report" hidden>Reportar</button>
        </div>

        <div class="chat-context-menu" id="chat-pin-menu" hidden>
            <button type="button" class="chat-context-item" data-pin-action="unpin">Desfijar mensaje</button>
        </div>

        {{-- Acciones de una persona de la pestaña Usuarios. --}}
        <div class="chat-context-menu chat-list-menu" id="chat-user-menu" hidden>
            <button type="button" class="chat-context-item" data-user-action="mute" hidden>Silenciar</button>
            <button type="button" class="chat-context-item" data-user-action="unmute" hidden>Quitar silencio</button>
            <button type="button" class="chat-context-item chat-context-item--danger" data-user-action="block" hidden>Bloquear</button>
            <button type="button" class="chat-context-item" data-user-action="unblock" hidden>Desbloquear</button>
            <button type="button" class="chat-context-item" data-user-action="report">Reportar</button>
        </div>

        <div class="chat-context-menu chat-list-menu" id="chat-list-menu" hidden>
            <button type="button" class="chat-context-item" data-list-action="mute" hidden>Silenciar</button>
            <button type="button" class="chat-context-item" data-list-action="unmute" hidden>Quitar silencio</button>
            <button type="button" class="chat-context-item" data-list-action="favorite" hidden>Colocar en favoritos</button>
            <button type="button" class="chat-context-item" data-list-action="unfavorite" hidden>Quitar de favoritos</button>
            <button type="button" class="chat-context-item" data-list-action="pin" hidden>Fijar conversación</button>
            <button type="button" class="chat-context-item" data-list-action="unpin" hidden>Desfijar conversación</button>
            <button type="button" class="chat-context-item" data-list-action="read" hidden>Marcar como leído</button>
            <button type="button" class="chat-context-item" data-list-action="unread" hidden>Marcar como no leído</button>
            <button type="button" class="chat-context-item" data-list-action="archive" hidden>Archivar</button>
            <button type="button" class="chat-context-item" data-list-action="unarchive" hidden>Deshacer archivo</button>
            <button type="button" class="chat-context-item" data-list-action="vaciar" hidden>Vaciar chat</button>
            <button type="button" class="chat-context-item" data-list-action="clear" hidden>Eliminar</button>
            <button type="button" class="chat-context-item chat-context-item--danger" data-list-action="block" hidden>Bloquear</button>
            <button type="button" class="chat-context-item" data-list-action="unblock" hidden>Desbloquear</button>
            <button type="button" class="chat-context-item" data-list-action="leave" hidden>Salir del grupo</button>
            <button type="button" class="chat-context-item" data-list-action="invite" hidden>Copiar enlace de invitación</button>
            <button type="button" class="chat-context-item" data-list-action="members" hidden>Gestionar miembros</button>
            <button type="button" class="chat-context-item" data-list-action="banned" hidden>Vetados del grupo</button>
            <button type="button" class="chat-context-item" data-list-action="report">Reportar</button>
            <button type="button" class="chat-context-item chat-context-item--danger" data-list-action="delete" hidden>Eliminar para todos</button>
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

{{-- Modal para crear un grupo. Va fuera del widget para que quede por encima
     del panel flotante y de su fondo. --}}
<x-app-modal id="modal-chat-group" title="Crear grupo">
    <x-slot:body>
        <div class="chat-group-form">
            <label for="chat-group-name" class="form-label">Nombre del grupo</label>
            <input type="text" class="form-control" id="chat-group-name"
                   placeholder="Nombre del grupo" maxlength="120" autocomplete="off">

            <p class="form-label" id="chat-group-members-label">Integrantes</p>
            <div class="chat-group__users" id="chat-group-users"
                 role="group" aria-labelledby="chat-group-members-label"></div>

            <p class="chat-hint">Puedes crear el grupo sin integrantes y compartir el enlace de invitación.</p>
            <p class="chat-hint" id="chat-group-error" role="status" aria-live="polite"></p>

            <div class="chat-group__invite" id="chat-group-invite" hidden>
                <p class="chat-group__invite-title">Enlace de invitación</p>
                <input type="text" class="form-control" id="chat-group-invite-url" readonly
                       aria-label="Enlace de invitación">
                <button type="button" class="btn btn-outline-secondary" id="chat-group-invite-copy">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                    <span>Copiar enlace</span>
                </button>
            </div>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-primary" id="chat-group-create">Crear grupo</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
    </x-slot:footer>
</x-app-modal>

{{-- Reenviar un mensaje: se eligen uno o varios destinatarios entre grupos,
     administradores, amigos secretos y personas. --}}
<x-app-modal id="modal-chat-forward" title="Reenviar mensaje" size="lg" scrollable>
    <x-slot:body>
        <div class="chat-forward">
            <div class="chat-forward__preview">
                <p class="chat-forward__label">Mensaje</p>
                <p class="chat-forward__preview-body" id="chat-forward-preview"></p>
            </div>

            <label class="chat-forward__search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="chat-forward-search"
                       placeholder="Buscar grupo, administrador o persona..." autocomplete="off">
            </label>

            <p class="chat-hint" id="chat-forward-hint">
                Puedes elegir más de un destinatario. Los chats de solo lectura no reciben reenvíos.
            </p>

            <div class="chat-forward__list" id="chat-forward-list">
                <p class="chat-hint">Cargando destinatarios...</p>
            </div>

            <p class="chat-forward__error" id="chat-forward-error" role="status" aria-live="polite"></p>
        </div>
    </x-slot:body>

    <x-slot:footer>
        <button type="button" class="btn btn-primary" id="chat-forward-send" disabled>Reenviar</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
    </x-slot:footer>
</x-app-modal>

{{-- Invitación pendiente o aviso de enlace expirado/usado/eliminado. --}}
@if (! empty($shell['chatInvitation']) || ! empty($shell['chatInvitationNotice']))
    <x-app-modal id="modal-chat-invite" title="Invitación a un grupo">
        <x-slot:body>
            <p class="chat-hint" id="chat-invite-text">
                {{ $shell['chatInvitationNotice'] ?? 'Te invitaron a un grupo.' }}
            </p>
            <p class="chat-invite__group" id="chat-invite-group"></p>
            <p class="chat-hint" id="chat-invite-error" role="status" aria-live="polite"></p>
        </x-slot:body>

        <x-slot:footer>
            <button type="button" class="btn btn-primary" id="chat-invite-accept"
                    @if (empty($shell['chatInvitation'])) hidden @endif>
                Aceptar e ingresar
            </button>
            <button type="button" class="btn btn-outline-secondary" id="chat-invite-reject">
                {{ empty($shell['chatInvitation']) ? 'Cerrar' : 'Rechazar' }}
            </button>
        </x-slot:footer>
    </x-app-modal>
@endif

@push('scripts')
    <script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
@endpush
