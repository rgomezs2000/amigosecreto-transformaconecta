# Acciones sobre miembros (grupo y sala)

Dentro de un grupo o de una sala hay acciones sobre sus miembros. Se llega a
ellas por dos vías, con el mismo criterio de permisos:

- **Ficha del miembro**: un clic en una fila de miembro abre su ficha y debajo
  de la identidad aparecen las acciones.
- **Menú contextual**: clic derecho en el nombre o el avatar de un mensaje, o en
  una fila de miembro del perfil.

«Silenciar» es el mismo silencio de la pestaña Usuarios: el chat directo con esa
persona deja de notificar. «Suspender», «Expulsar» y «Banear» solo existen en
grupos (las salas cerradas no admiten suspensiones ni vetos).

## Ficha del miembro

| Quien mira | Miembro | Acciones |
| --- | --- | --- |
| Dueño del grupo | su propia ficha | Eliminar grupo |
| Dueño de la sala | su propia ficha | Eliminar sala |
| Dueño del grupo | otro miembro | Silenciar, Reportar, Suspender, Expulsar, Banear |
| Dueño de la sala | otro miembro | Expulsar |
| Miembro | su propia ficha | ninguna |
| Miembro | otro miembro | Silenciar, Bloquear, Reportar |
| Miembro | el dueño | Silenciar, Reportar |
| Administración del sistema | otro miembro | Silenciar, Reportar |
| Cualquiera | un administrador del sistema | solo Mensaje |

La ficha de otra persona mantiene el botón **Mensaje** para abrir su chat
directo.

## Menú contextual

| Quien mira | Miembro | Opciones |
| --- | --- | --- |
| Dueño del grupo | otro miembro | Conversación, Ver perfil, Silenciar, Bloquear, Reportar, Suspender, Expulsar, Banear |
| Dueño de la sala | otro miembro | Conversación, Ver perfil, Silenciar, Bloquear, Reportar, Expulsar |
| Miembro | otro miembro | Conversación, Ver perfil, Silenciar, Bloquear, Reportar |
| Miembro | el dueño | Conversación, Ver perfil, Silenciar, Reportar |
| Administración del sistema | otro miembro | Conversación, Ver perfil, Silenciar, Reportar |
| Cualquiera | su propia ficha | Ver perfil |
| Cualquiera | un administrador del sistema | Conversación, Ver perfil |

## Qué hace cada acción

- **Conversación**: abre (o crea) el chat directo con esa persona.
- **Ver perfil**: abre su ficha dentro del grupo o de la sala.
- **Silenciar / Quitar silencio**: silencia el chat directo con esa persona.
- **Bloquear / Desbloquear**: bloqueo entre usuarios. En un grupo o sala el
  bloqueo puede expulsar a quien lo recibe (ver
  [perfiles y bloqueos](chat-perfiles-bloqueos.md)).
- **Reportar**: abre el formulario de reporte con esa persona como objetivo.
- **Suspender**: pide días (1 a 365) y un motivo opcional. Mientras dura, la
  persona no puede escribir, reaccionar, adjuntar archivos ni enviar audios en
  el grupo. Queda en la auditoría del grupo.
- **Expulsar**: saca al miembro del grupo o de la sala. Queda en la auditoría.
- **Banear**: expulsa al miembro y lo deja vetado para que no pueda volver.
- **Eliminar grupo / Eliminar sala**: solo para el dueño, desde su propia ficha.

## Permisos en el servidor

- `POST /chat/grupo/{conversation}/suspender/{user}`: dueño o administración,
  solo grupos.
- `POST /chat/grupo/{conversation}/banear/{user}`: dueño o administración, solo
  grupos.
- `POST /chat/{conversation}/expulsar/{user}`: dueño o administración; sirve
  para grupos y salas.
- `POST /chat/usuarios/{user}/silenciar` y
  `POST /chat/usuarios/{user}/bloquear`: cualquier usuario, sobre otra persona.

`GET /chat/{conversation}/perfil` y `GET /chat/grupo/{conversation}/miembros`
indican `is_owner` e `is_admin` de cada miembro; los mensajes indican
`sender_is_owner` y `sender_is_admin`.
