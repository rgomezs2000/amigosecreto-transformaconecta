# Flujo de casos de reportes

Los reportes del chat tienen tres estados:

1. **Por atender**: se crea cuando un usuario reporta un mensaje, a una persona o
   una conversación/grupo.
2. **Atendiendo**: un administrador abre el detalle y pulsa **Tomar caso**.
   Queda asociado a ese administrador y puede gestionarlo.
3. **Cerrado**: solo el administrador que tomó el caso puede cerrarlo, con una
   observación de cierre obligatoria y confirmación expresa.

## Tomar el caso

En el detalle del reporte, el botón **Tomar caso** abre un formulario con una
observación opcional. Al confirmar:

- se guarda `assigned_to`, `taken_at` y `attention_observation`;
- el estado pasa a **Atendiendo**;
- los demás administradores ya no pueden tomarlo ni cerrarlo.

## Cerrar el caso

El botón **Cerrar caso** solo aparece para el administrador asignado. El modal
pide:

- observación de cierre (obligatoria);
- confirmación «Confirmo que el reporte quedó cerrado».

Si se marca la confirmación y se pulsa **Sí, cerrar caso**, se guarda
`closed_at`, `closed_by` y `close_observation`, y el estado pasa a **Cerrado**.
Si se pulsa **No cerrar**, no se modifica nada.

El servidor vuelve a comprobar que el usuario sea el asignado, que el caso siga
en atención y que la confirmación y la observación estén presentes.

## Rutas

- `POST /moderacion/reportes/{report}/tomar`
- `POST /moderacion/reportes/{report}/cerrar`

La lista muestra el estado y el detalle muestra asignación, fechas y ambas
observaciones.
