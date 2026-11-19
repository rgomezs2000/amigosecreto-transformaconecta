# Módulos de consulta de la administración

Tres módulos de solo lectura para la administración que comparten la misma
página y la misma ficha en modal:

| Módulo | Código | Se activa | Contenido de la ficha |
| --- | --- | --- | --- |
| **Lista de deseos** | `lista-de-deseos` | Desde la fase **Unión** | Participante + su lista de deseos |
| **Usuarios obsequiados** | `usuarios-obsequiados` | Desde **Intercambio de pistas** | Participante + obsequio recibido + consumibles |
| **Pistas** | `pistas` | Desde **Intercambio de pistas** | Participante + pistas recibidas + pistas enviadas |

## Página

Encima del formulario va la **sección del evento**: etiqueta de fase con su
color, etiqueta del estado del evento, nombre, año, fecha de intercambio, el
mensaje de la fase y los contadores (invitados, activos, pendientes, asignados
y sin asignar).

El formulario tiene:

1. **Evento**: todos los eventos; por defecto el actual y en curso.
2. **Buscar usuario**: por nombre, correo o usuario.
3. **Estatus de participación**: Todos, En espera, Activo, Rechazado, Retirado,
   Expulsado o Descartado.
4. **Limpiar**: vacía búsqueda y filtro, y vuelve al evento actual.

La tabla se pagina en el servidor y muestra Id, Nombre, Usuario, **Estatus de
participación** (etiqueta con color) y la acción **Ver lista de deseos**, **Ver
obsequios** o **Ver pistas**.

Antes de la fase correspondiente, la página muestra un aviso y oculta la tabla;
el selector de evento y el formulario siguen visibles para poder cambiar de
evento.

## Ficha en modal

- **Participante**: datos del usuario y de su participación (la misma ficha del
  módulo Participantes).
- **Lista de deseos** (módulo de deseos): deseos, descripción, enlace y fecha en
  una DataTable paginada.
- **Obsequio** (módulo de obsequiados): descripción, monto en **US$**, monto
  convertido a **Bs.** a la tasa del día y fecha de registro.
- **Consumibles** (módulo de obsequiados): DataTable paginada con fecha,
  descripción, cantidad, monto en US$ y monto en Bs.
- **Pistas del amigo secreto** (módulo de pistas): las que el amigo secreto le
  envía al usuario.
- **Pistas al amigo secreto** (módulo de pistas): las que el usuario le envía a
  su amigo secreto, con el destinatario.

Los obsequios de **Usuarios obsequiados** son los que **recibe** cada usuario de
su amigo secreto (asignación donde es receptor). La tasa de bolívares se
consulta al momento de la petición con `dollar_rate()`: no se guarda en la base
de datos.

## Acceso

Solo administración (o superadministración) con el módulo concedido. Además, los
endpoints de fichas de Participantes aceptan cualquiera de los cuatro módulos de
monitoreo, siempre con rol de administración.

## Endpoints

| Método | Ruta | Para qué |
| --- | --- | --- |
| GET | `/monitoreo/{tipo}/datos` | DataTable de usuarios (`tipo`: `deseos`, `obsequios` o `pistas`) con `evento` y `estado`. |
| GET | `/participantes/{participant}/ficha` | Pestaña del participante. |
| GET | `/participantes/{participant}/lista-deseos` | Lista de deseos. |
| GET | `/participantes/{participant}/pistas?tipo=recibidas\|enviadas` | DataTable de pistas. |
| GET | `/participantes/{participant}/obsequios?rol=recibido` | Obsequio recibido y equivalentes. |
| GET | `/participantes/{participant}/obsequios/consumibles?rol=recibido` | DataTable de consumibles recibidos. |

## Piezas

- `app/Http/Controllers/MonitoreoAdminController.php`: página, tabla y filtros.
- `app/Http/Controllers/ListaDeseosController.php`: en modo administración
  delega en el módulo de consulta.
- `app/Http/Controllers/ParticipantesController.php`: endpoints compartidos de
  fichas; `rol=recibido` para los obsequios del usuario.
- `app/Http/Controllers/Concerns/ResuelveEstadoEvento.php` y
  `ResuelveEventoConsulta.php`: estado del evento y evento elegido.
- `resources/views/monitoreo/`: página y modal con pestañas.
- `public/js/monitoreo.js`: DataTables, filtros, pestañas y modal.
- `resources/views/partials/fase-badge.blade.php`: etiquetas de fase con color.
- `app/Support/ModulePages.php`: mapas de `pistas` y `usuarios-obsequiados`.
- `tests/Feature/MonitoreoAdminTest.php`: acceso, filtros, activación por fase,
  obsequios recibidos, pistas y etiqueta de fase.
