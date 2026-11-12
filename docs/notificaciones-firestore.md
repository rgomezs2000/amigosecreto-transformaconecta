# Notificaciones, chat y correo con Firestore

Este documento describe el módulo de **Control de Notificaciones**, el espejo
**NoSQL (Firestore)** de la mensajería y el módulo de **Moderación**.

La regla de oro: **SQL es la fuente de verdad** y Firestore guarda una copia de
lo que viaja por push, correo y chat. Si Firebase falla, la aplicación sigue
funcionando; el error queda en el log y se muestra en el panel de control.

## Qué se guarda en Firestore (y nada más)

| Colección | Documento | Contenido |
| --- | --- | --- |
| `notificaciones_push` | UUID de la notificación | usuario, tipo, título, cuerpo, enlace, origen, sonido, aviso visual, `read_at`, payload |
| `correos` | UUID del envío | destinatario, usuario, asunto, título, líneas, acción, estado (`sent`/`failed`/`skipped`), error |
| `conversaciones` | id de la conversación | tipo (`direct`/`group`/`reports`), nombre, participantes con `last_read_at`, último mensaje |
| `mensajes_chat` | id del mensaje | conversación, autor, texto, tipo, adjuntos, estado de edición/borrado, ubicación |
| `reportes_chat` | id del reporte | mensaje, conversación, quién reporta, autor reportado, motivo y copia del mensaje |

Todo lo demás sigue viviendo solo en SQL (`notifications`, `email_logs`,
`chat_messages`, `chat_reports`, etc.).

## Configuración

```dotenv
FIREBASE_CREDENTIALS=storage/app/amigo-secreto-laravel-firebase-adminsdk-fbsvc-8bf636c4f1.json
FIREBASE_FIRESTORE_DRIVER=rest
FIREBASE_FIRESTORE_DATABASE=(default)
```

- `rest` (recomendado): usa la API REST de Firestore con `google/auth`. No
  necesita la extensión `ext-grpc`, que es justo lo que falta en XAMPP y en la
  mayoría de hosting compartido. No agrega dependencias a Composer.
- `sdk`: usa `Firebase::firestore()`. Requiere `google/cloud-firestore` y
  `ext-grpc` instalados en el servidor.
- `off`: desactiva el espejo NoSQL (solo queda SQL). Útil en pruebas.

El JSON de la cuenta de servicio **no debe subirse al repositorio**. Si se
filtra, hay que revocarlo en Google Cloud y generar uno nuevo.

En la copia NoSQL de los correos, las claves temporales se enmascaran
(`Clave: [oculto]`): el correo real sí las lleva, pero no quedan almacenadas en
Firestore. Las reglas de seguridad de Firestore deben negar el acceso directo
de los clientes; solo el servidor (cuenta de servicio) escribe y lee.

Comprobación rápida desde la terminal:

```bash
php artisan firebase:check
php artisan firebase:check --write
```

El segundo comando escribe un documento temporal en `correos`, lo lee y lo
borra en el acto.

Para copiar el historial de chat que ya existía antes de activar el espejo:

```bash
php artisan chat:sync-firestore
php artisan chat:sync-firestore --limit=5000
```

## Puntos de integración

- `app/Services/MailNotifier.php`: reemplaza el envío directo del helper
  `send_mail()`. Registra cada destinatario en `email_logs` (estado y error) y
  en `correos` (contenido completo).
- `app/Services/PushNotifier.php`: crea la notificación SQL (tabla
  `notifications`, ampliada con `channel`, `sound`, `visual`, `url`, `source`)
  y la espeja en `notificaciones_push`. Respeta la configuración global y la de
  cada usuario.
- `app/Http/Controllers/ChatController.php`: sincroniza conversaciones al
  crearlas o marcarlas como leídas, mensajes al enviarlos/editar/eliminar y
  reportes al reportar. Además envía push a los demás participantes.
- `app/Services/Firestore/FirestoreService.php`: único lugar que conoce los
  nombres de las colecciones y el formato de cada documento.

## Control de Notificaciones

Ruta: **Administración → Control de Notificaciones** (`/administracion/notificaciones`).

- Interruptores globales: correo, push, sonido y avisos visuales.
- Tráfico: notificaciones push, correos (enviados, fallidos y omitidos),
  mensajes de chat y reportes, con cifras de hoy, últimos 7 días y total.
- Estado del espejo NoSQL: transporte, proyecto y último error.
- Preferencias por usuario: push, sonido, sonido del chat y avisos visuales.
  El botón «Heredar» borra las excepciones y vuelve a la configuración global.

Cada persona también puede ajustar su sonido:

- En la **campana** (panel de notificaciones) con los interruptores
  «Alertas push», «Sonido» y «Avisos visuales».
- En el **chat**, con el botón de altavoz de la cabecera (solo sonido del chat).

Las notificaciones nuevas se cuentan en la campana. Al abrir una se marca como
leída; «Marcar todas como leídas» limpia el contador. El sonido es opcional y
está apagado por defecto, para evitar estímulos auditivos inesperados.

La campana muestra un punto rojo cuando hay un solo aviso, el número cuando hay
entre 2 y 99, y `+99` cuando hay más. Al pulsar un aviso se abre un modal con su
detalle (sin recargar la página) y el aviso queda marcado como leído tanto en el
modal como en la lista. El chat usa el mismo criterio de conteo (`+99`).

La lista y el contador se actualizan por AJAX cada 10 segundos, solo si la
pestaña está visible y solo cuando hay cambios; nunca recargan la página ni
repintan la lista sin necesidad.

Abrir una notificación **no recarga la página**: solo la marca como leída. Si el
aviso es del chat, el botón «Abrir» muestra la conversación en el chat flotante;
para otros enlaces, «Abrir» navega de forma explícita.

## Moderación

Ruta: **Administración → Moderación** (`/administracion/moderacion`).

- Recopila los reportes hechos en el chat en modo **solo lectura**: no crea,
  edita ni elimina nada.
- Cada fila muestra fecha, quién reporta, autor del mensaje, motivo y
  conversación.
- «Detalle» abre el motivo y el mensaje completos.
- «Ir al chat» abre el chat flotante directamente en la conversación
  (`?conversacion=ID`), donde el administrador puede moderar.

## Pruebas

```bash
php artisan test --filter=NotificacionesTest
php artisan test --filter=ModeracionTest
```

Las pruebas usan `FIREBASE_FIRESTORE_DRIVER=off` (ver `phpunit.xml`) y un doble
en memoria (`tests/Support/FakeFirestoreGateway.php`) para comprobar qué se
sincroniza sin tocar Firebase.
