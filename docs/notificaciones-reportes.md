# Notificaciones de reportes

Cuando alguien reporta un mensaje, a un usuario o una conversación/grupo, la
administración recibe el aviso por varios canales:

1. **Push interno**: notificación en la campana (`chat.reporte` para mensajes y
   `chat.report` para usuarios/conversaciones), con espejo en Firestore
   (`notificaciones_push`).
2. **Correo**: un `NotificationMail` por administrador activo, registrado en
   `email_logs` y en Firestore (`correos`).
3. **Chat de reportes**: un mensaje de moderación en la conversación interna
   «Reportes», visible para los administradores.
4. **Moderación**: el reporte aparece en la tabla de reportes.

La notificación push respeta las preferencias del administrador (sonido,
avisos visuales y control global). El enlace abre Moderación.

## Evitar duplicados

Los reportes de usuario y de conversación usan `firstOrCreate`: si la misma
persona vuelve a reportar el mismo mensaje, no se duplican ni la notificación,
ni el correo, ni el mensaje del chat de reportes. Los reportes de mensaje
individual conservan su flujo propio.
