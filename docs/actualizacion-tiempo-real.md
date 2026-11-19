# Actualización en tiempo real

El sistema se actualiza sin recargar la página. Cada componente conserva su
estado (página, búsqueda, orden, selección y formularios) y los refrescos se
pausan cuando hay interacción.

## Anticaché global

`public/js/live-refresh.js` añade un parámetro `_` con la marca de tiempo a
todas las peticiones **GET del mismo origen**:

- jQuery/AJAX, incluidas las DataTables;
- `fetch`, incluidas las consultas públicas de disponibilidad.

No modifica POST, peticiones externas ni URLs que ya traen `_ts` o `_`.

## Qué se refresca

- **Menú lateral:** consulta `/menu/estado` a los 12 segundos y luego cada 30
  segundos. Solo reemplaza el HTML si la firma cambió, el menú está cerrado y
  no hay modales ni offcanvas abiertos.
- **Notificaciones:** contador cada 7 segundos; la lista se actualiza al abrir
  el panel y cuando cambia.
- **Chat:** mensajes cada 1,6–15 segundos según errores; resumen cada 4,5–30
  segundos. Solo parchea diferencias.
- **Tasa BCV:** cada 60 segundos consulta `/tasa-bcv`. Actualiza el encabezado,
  la referencia en bolívares del presupuesto, la ficha de Eventos y cualquier
  elemento marcado con `data-bcv-rate` o `data-bcv-usd`.
- **DataTables:** cada 15 segundos, solo en reposo (5 segundos sin interacción),
  sin modales, sin pestaña oculta y sin operaciones AJAX en curso. Conserva
  página, orden, búsqueda y selección.

## No invasivo

- Nunca recarga la página completa.
- No interrumpe formularios enfocados, modales, diálogos ni offcanvas abiertos.
- No actualiza si la pestaña está oculta.
- Los cambios de menú se aplican solo cuando el menú está cerrado.
- El parámetro anticaché no cambia la lógica del servidor; Laravel lo ignora.

## Implementación

- `public/js/live-refresh.js`: anticaché global y menú vivo.
- `app/Http/Controllers/ShellController.php`: HTML y firma del menú.
- `routes/web.php`: ruta `/menu/estado`.
- `public/js/alertas.js`: sondeo de notificaciones.
- `public/js/live-tables.js`: refresco no invasivo de DataTables.
- `public/js/chat.js`: sondeo incremental del chat.
