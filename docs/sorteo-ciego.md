# Sorteo ciego, chat del evento y «Ver tu amigo»

Implementación de la fase de sorteo del amigo secreto, siguiendo el informe de
factibilidad (§3, §5, §7, §8 y §15).

## 1. Al iniciar el evento

`Eventos → Iniciar` hace tres cosas además de cambiar el estado a
`sorteo habilitado`:

1. **Chat fijo del evento** (solo lectura, para todos los inscritos) con el
   aviso de apertura: el evento comenzó y el sorteo está abierto.
2. **Aviso en pantalla (push)** a cada participante: «El sorteo está abierto».
3. **Correo** con el mismo mensaje, saludando y explicando que llegó el momento
   de elegir a su amigo secreto, con el botón «Descubrir a mi amigo secreto».

## 2. Chat del evento (solo lectura)

- Es una conversación de tipo `event` con `is_read_only = true`.
- Ahí **solo publica el sistema**. Nadie escribe, adjunta, reacciona, reporta,
  silencia, archiva ni elimina: el servidor responde 403 en todas esas acciones
  y el widget oculta el cuadro de escritura.
- Se fija en el cintillo el último aviso publicado.
- Aparece en la lista de chats de todos los participantes, con el subtítulo
  «Avisos del evento · solo lectura».

## 3. El reparto (sorteo ciego)

Se calcula **una sola vez**, en el primer ingreso al módulo, dentro de una
transacción con la fila del evento bloqueada (informe §3, opción A):

- Solo entran los participantes activos, ordenados y barajados.
- Se usa una **permutación cíclica**: cada quien regala al siguiente de la
  lista barajada. Con eso nadie se saca a sí mismo y **no aparecen parejas
  cruzadas** (A→B y B→A) cuando hay 3 o más participantes.
- Con 2 participantes el único reparto es cruzado: el sistema lo rechaza salvo
  que el evento tenga activada la opción de parejas mutuas.
- El índice único `(event_id, giver_id)` y `(event_id, receiver_id)` y el
  `CHECK giver_id <> receiver_id` de la base impiden duplicados y autosorteos
  aunque lleguen dos peticiones a la vez.
- Al repartir se crean los **chats privados de cada pareja**.

## 4. Vista del participante

`/amigo-secreto/asignar-amigo` (módulo «Sorteo ciego»):

- Botón central **«Descubrir a mi Amigo Secreto»**. La primera vez marca
  `assignments.drawn_at`; después el botón desaparece.
- Tarjeta limpia con el nombre, el usuario y las preferencias anotadas del
  amigo asignado.
- Botón secundario **«Ver de nuevo»**: consulta sin límite, nunca vuelve a
  sortear.
- Contador «Han elegido X de N participantes».

`/amigo-secreto/ver-tu-amigo` (módulo «Ver tu amigo»):

- El mismo amigo, para consultarlo cuantas veces quiera.
- **Pistas** que le ha dejado su amigo secreto incógnito (se consultan siempre
  por `receiver_id`: el anonimato no se rompe).

## 5. Cuándo se abre y cuándo se cierra

| Situación | Estado |
| --- | --- |
| Sin evento | Cerrado |
| Borrador o inscripción | Cerrado (aún no se inicia) |
| Pospuesto en inscripción | Cerrado |
| Pospuesto durante el sorteo | **Sigue abierto** |
| Suspendido | Cerrado temporalmente |
| Sorteo abierto | **Abierto** |
| Ya eligieron todos | Cerrado (avanza a lista de deseos) |
| Cancelado / finalizado | Cerrado |

Al cerrarse por haber elegido todos:

- El evento pasa a **sorteado** y se guarda `draw_closed_at`.
- Se publica en el chat del evento que el sorteo concluyó y comienza la lista
  de deseos.
- Se notifica en pantalla a todos los participantes.

## 6. Correo de aliento del incógnito

Cuando un participante descubre a su amigo, **su amigo secreto incógnito**
(quien le regala a él, no a quien él eligió) le envía un correo con un mensaje
de aliento. Nunca se revela quién lo envía: la firma es «Tu amigo secreto».

## 7. Casar un caso extremo: reiniciar el sorteo

`Eventos → tarjeta (sorteo abierto) → Reiniciar sorteo`. Borra las parejas y
los chats secretos del evento, vuelve el evento a *sorteo habilitado* y deja el
aviso en el chat del evento. Queda registrado en `audit_logs` como
`draw.reset`. Sirve para el caso extremo que describe el informe: un reporte,
la administración desasocia y todos vuelven a elegir.

## 8. Piezas técnicas

| Pieza | Archivo |
| --- | --- |
| Reparto, estado y cierre | `app/Services/SorteoService.php` |
| Chat del evento | `app/Services/EventoChat.php` |
| Vistas | `app/Http/Controllers/SorteoCiegoController.php`, `VerTuAmigoController.php` |
| Pantallas | `resources/views/sorteo/index.blade.php`, `resources/views/amigo/index.blade.php` |
| Base de datos | `chat_conversations.is_read_only`, `events.draw_closed_at`, `assignments.drawn_at` ahora admite nulos |
| Rutas | `/amigo-secreto/asignar-amigo`, `/amigo-secreto/ver-tu-amigo`, `/eventos/{event}/sorteo/reiniciar` |

## 9. Límites conocidos

- No se implementan todavía la lista de deseos, el intercambio de pistas ni la
  gran revelación: el sorteo cierra y anuncia el avance, y esos módulos siguen
  en su lugar.
- El «push» es aviso en pantalla dentro de la aplicación (tabla
  `notifications` + espejo en Firestore), como el resto del sistema; no hay
  Web Push del navegador.
- Si se pospone el evento durante el sorteo, se mantiene abierto; si se
  suspende, se cierra hasta reactivarlo.
