# Ver tu amigo

Página del participante (`/amigo-secreto/ver-tu-amigo`) para seguir su
participación, el sorteo y su amigo secreto.

## Estado de la invitación

Una vez respondida la invitación, la tarjeta **Invitación al evento** y la
etiqueta del resumen muestran el estado real de la participación:

| Estado | Etiqueta |
| --- | --- |
| Pendiente | En espera |
| Aceptada | **Activo** |
| Rechazada | **Rechazado** |
| Descartada por la organización | **Descartado** |
| Expulsada | **Expulsado** |
| Retirada | **Retirado** |

Si no hay participación en el evento mostrado, dice **Sin invitación**. El
motivo de rechazo, retiro o expulsión queda visible en la tarjeta.

## Fases

- **Unión** (activo y sin amigo secreto): «Estás en la fase de unión: favor
  esperar el sorteo.» Mientras espera puede escribir su lista de deseos.
- **Sorteo ciego** (activo y sin amigo descubierto): botón **Sorteo ciego** que
  lleva al módulo de asignación.
- **Amigo asignado y descubierto**: se muestran las pestañas **Mi amigo
  secreto** (nombre), **Lista de deseos del amigo** y **Pistas de mi amigo
  secreto**.
- **Evento avanzado o finalizado**: las mismas pestañas quedan en solo lectura.

La lista de deseos y las pistas se muestran en DataTables paginados (10 por
página) de solo lectura, con buscador y botón para limpiar.

## Qué evento se muestra

1. El de la invitación abierta (`?invitacion=TOKEN`), si pertenece al usuario.
2. El evento más reciente en el que el usuario tiene participación.
3. El evento más reciente del sistema.

Así, después de aceptar una invitación no aparece «Sin invitación» aunque
existan otros eventos del mismo año.

## Piezas

- `app/Http/Controllers/VerTuAmigoController.php`: evento visible, estado,
  amigo, pistas y lista de deseos.
- `resources/views/amigo/index.blade.php`: tarjetas por estado y fase, pestañas
  y tablas.
- `public/js/amigo.js`: DataTables, respuesta a la invitación y retiro.
- `app/Enums/ParticipantStatus.php`: etiquetas de estado.
- `tests/Feature/FlujoUnionTest.php`: estados, fase de unión, botón de sorteo y
  prioridad del evento del participante.
- Obsequios y consumibles: [obsequios-ver-tu-amigo.md](obsequios-ver-tu-amigo.md).
