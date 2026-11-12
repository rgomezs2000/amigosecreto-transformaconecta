# Perfil de la conversación (chat)

La barra del título de una conversación abre un perfil que se monta sobre el
panel del chat. El botón de contenido del encabezado se retiró: la galería se
abre desde el perfil.

## Navegación

- Perfil principal → conversación con la flecha izquierda.
- Miembros, auditoría o galería → perfil principal.
- Conversación → bandeja con la flecha del encabezado del chat.

## Conversación directa

- Identidad centrada: nombre, `@usuario` y correo.
- 5 miniaturas de fotos, videos y audios.
- Encabezado «Contenido multimedia, documentos y enlaces» con flecha para abrir
  la galería completa.
- Opciones: añadir/quitar favorito, vaciar chat, bloquear/desbloquear y reportar.
- En el amigo secreto se respeta el anonimato del receptor.

## Grupo

- Nombre y total de miembros.
- Miembros (`N`) con las primeras cinco personas y **Ver todos**; la lista
  completa tiene buscador.
- Auditoría del grupo solo para el dueño y la administración: acción, estado,
  fecha y hora, quién la ejerció y sobre quién.
- Las mismas 5 miniaturas y galería completa.
- Opciones: favorito, vaciar sala, salir (miembros), eliminar (dueño o
  administración) y reportar.

## Datos

- `GET /chat/{conversation}/perfil`: identidad, miembros, permisos y auditoría.
- `GET /chat/{conversation}/media`: galería (ya existente).
- Tabla `chat_group_audits`: bitácora de cambios del grupo. Se registra desde
  ahora: creación, invitaciones, altas, suspensiones, baneos, expulsiones,
  salidas, mensajes eliminados y salas vaciadas.
