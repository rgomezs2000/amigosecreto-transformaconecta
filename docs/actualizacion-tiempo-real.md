# Actualización en tiempo real no invasiva

Las tablas y contadores se refrescan solos, pero **nunca interrumpen** lo que la
persona está haciendo. El mecanismo está pensado para que el sistema no se
sienta “recargado” ni pierda trabajo en curso.

## Regla de oro

Una actualización solo ocurre si:

- la pestaña está visible y hay conexión;
- no hay un modal, un diálogo de Bootbox ni un panel lateral abierto;
- no hay un campo de texto, selector o área editable enfocada;
- la persona lleva al menos **8 segundos sin interactuar** (teclado, clic o
  cambio en un campo);
- la tabla está visible (no está en una pestaña oculta);
- la tabla no tiene cambios pendientes marcados con `data-live-dirty="1"`.

Si alguna condición falla, se omite el ciclo. No se fuerza nada.

## Tablas

`public/js/live-tables.js` se carga al final de todas las páginas y detecta las
tablas DataTables con datos en servidor. Cada **30 segundos** intenta
actualizarlas, una por una y con una pequeña separación entre tablas.

Conserva:

- la página actual;
- la búsqueda y el orden;
- las filas seleccionadas con checkbox (vuelve a marcarlas después del
  refresco).

Cubre, entre otras:

- Roles, Permisos, Categorías, Módulos, Usuarios.
- Reportes de moderación, bloqueos entre usuarios y vetados de grupo.

Para excluir una tabla: `data-live="off"` en el `<table>`.

Para pausar todo desde un módulo:

```js
window.LiveTables.pause();
window.LiveTables.resume();
```

## Matriz de perfiles

La matriz es una tabla local con cambios preparados sin aplicar. Su refresco es
propio:

- consulta el servidor cada 30 segundos;
- **no se ejecuta si hay cambios sin aplicar** o si hay un modal/campo enfocado;
- solo vuelve a cargar cuando los datos del servidor cambiaron de verdad;
- conserva la búsqueda y los filtros.

## Contadores

- **Control de Notificaciones**: `GET /administracion/notificaciones/contadores`
  devuelve el tráfico y actualiza las tarjetas (hoy, 7 días, total, fallidos y
  conversaciones) cada 20 segundos. Solo cambia texto; no recarga la página.
- **Moderación**: `GET /moderacion/contadores` devuelve reportes, bloqueos
  activos y vetos activos, y actualiza los tres contadores cada 20 segundos.

## Eventos

`GET /eventos/estado` devuelve el estado de las tarjetas. Cada 25 segundos, si
no hay formularios abiertos ni campos enfocados:

- actualiza nombre, estado, fecha de intercambio, participantes y fecha de
  creación de las tarjetas existentes;
- si aparece o desaparece un evento, o cambia el estado, reemplaza **solo el
  bloque de tarjetas** con el HTML que devuelve el servidor, mediante AJAX y sin
  recargar la página;
- los botones de las tarjetas siguen funcionando porque sus manejadores están
  delegados en el contenedor.

El formulario de creación y sus modales **no se tocan mientras están abiertos**.
Ningún refresco automático hace `location.reload()` ni una navegación completa.
