# Perfil de la conversación (chat)

La barra del título de una conversación abre un perfil que se monta sobre el
panel del chat. El botón de contenido del encabezado se retiró: la galería se
abre desde el perfil.

## Navegación

- Un clic en una conversación de la bandeja o en una persona de la pestaña
  Usuarios abre el mensaje; el perfil se abre con clic derecho → **Ver perfil**.
- Perfil abierto desde una conversación → vuelve a la conversación.
- Perfil abierto desde la bandeja, grupo, sala, difusión o Usuarios → vuelve a
  la lista; no abre el mensaje por detrás.
- Miembros, auditoría o galería → perfil principal.
- La acción principal va debajo de los datos de identidad (nombre, `@usuario` y
  correo): **Buscar** si se abrió dentro de la conversación, **Conversación** si
  es un grupo/sala/difusión de la lista, o **Mensaje** para una persona. Ver
  [perfiles y bloqueos](chat-perfiles-bloqueos.md).

## Conversación directa

- Identidad centrada: nombre, `@usuario` y correo.
- 5 miniaturas de fotos, videos y audios.
- Encabezado «Contenido multimedia, documentos y enlaces» con flecha para abrir
  la galería completa.
- Opciones: añadir/quitar favorito, vaciar chat, bloquear/desbloquear y reportar.
- Si hay un bloqueo, el chat se puede leer pero el cuadro de escritura se
  reemplaza por el aviso de bloqueo.
- En el amigo secreto se respeta el anonimato del receptor.

## Grupo

- Nombre y total de miembros.
- Miembros (`N`) con las primeras cinco personas y **Ver todos**; la lista
  completa tiene buscador.
- Un clic en un miembro abre su ficha; allí están las acciones según el papel
  de quien mira. El clic derecho sobre el nombre/avatar de un mensaje o sobre
  una fila de miembro abre el mismo menú. Ver
  [acciones sobre miembros](chat-miembros-acciones.md).
- La ficha del miembro no lleva «Volver al grupo»: se vuelve con la flecha de
  la cabecera.
- Auditoría del grupo solo para el dueño y la administración: acción, estado,
  fecha y hora, quién la ejerció y sobre quién.
- Las mismas 5 miniaturas y galería completa.
- Opciones: favorito, vaciar sala, salir (miembros), eliminar (dueño o
  administración) y reportar.

## Grupos en común

En un chat directo, el mini título **Grupos en común** muestra a la derecha
«N grupos en común» o «No hay grupos en común». Debajo solo se listan los
grupos que existen, junto con las acciones de crear un grupo con esa persona o
añadirla a los grupos del usuario.

## Datos

- `GET /chat/{conversation}/perfil`: identidad, miembros, permisos y auditoría.
  Cada miembro incluye `is_owner` e `is_admin`.
- `GET /chat/{conversation}/media`: galería (ya existente).
- Los mensajes incluyen `sender_is_owner` y `sender_is_admin` para decidir las
  acciones del menú del miembro.
- Tabla `chat_group_audits`: bitácora de cambios del grupo. Se registra desde
  ahora: creación, invitaciones, altas, suspensiones, baneos, expulsiones,
  salidas, mensajes eliminados y salas vaciadas.
