# Adaptación: "Amigo Secreto Inclusivo" (TEA Grado 1 · Venezuela)

**Origen:** especificación enviada por el compañero de Roger.
**Base:** `docs/factibilidad-amigo-secreto.md` (diseño) + código ya construido (migraciones, modelos, seeders, MySQL).
**Estado:** decisiones cerradas y base actualizada (tablas nuevas, moneda y zona).

---

## 1. Resumen ejecutivo

La especificación **encaja** con la arquitectura: el sorteo ciego, la revelación, el contador y los estados ya están modelados. Pero agrega **dos dominios que no existen** (lista de deseos y pistas) y un **eje transversal** (accesibilidad cognitiva) que no estaba en el diseño original.

Los **cuatro conflictos** ya están resueltos (§3) y no bloquean nada.

| | Cantidad |
|---|---|
| Requisitos que ya se cumplen | 6 |
| Requisitos nuevos implementados en la base | 5 |
| Conflictos resueltos | 4 |
| Decisiones pendientes | 0 |

---

## 2. Lo que ya se cumple sin cambiar nada

| Requisito del compañero | Cómo queda cubierto |
|---|---|
| Impedir autoasignaciones | `CHECK (giver_id <> receiver_id)` en la base + validación en el servicio |
| **Impedir A↔B (cruzadas dobles)** | Sale **gratis**: el algoritmo documentado (Sattolo) genera un **ciclo único**, y un ciclo de 3 o más nunca contiene un intercambio mutuo. Es exactamente el "por defecto se evita" de la spec. |
| Botón "Descubrir a mi Amigo Secreto" | El reparto se calcula antes; el botón solo revela. Hoy `drawn_at` limita **volver a sortear**, no **volver a ver**. |
| "Ver de nuevo" sin límite | Ya previsto: el módulo muestra la tarjeta cada vez que se entra. |
| Revelación con ambos nombres ("tu amigo fue X", "a ti te regaló Y") | `assignments` guarda las dos direcciones; la revelación solo activa la visibilidad. |
| Contador hasta el evento | `events.exchange_at` existe. |
| Sin sonidos ni avisos intrusivos | La notificación en pantalla ya es la vía principal; el sonido queda **desactivado por defecto**. |
| Cada usuario sabe en qué fase está | `events.status` con estados explícitos (borrador → inscripción → sorteo → sorteado → cerrado → revelado). |

---

## 3. Conflictos resueltos

| # | La spec del compañero dice | Decisión tomada |
|---|---|---|
| C1 | El sorteo es **automático** | **Manual**, como se planteó: el administrador activa el evento y el usuario entra al módulo. Sin cron ni sesión. El cálculo es atómico en el primer ingreso (§3 del documento base), así nadie se queda sin opción. |
| C2 | Paso 1 es "Creación/Unión" | **El administrador crea las cuentas.** No hay registro ni código de invitación; "Unión" es el primer inicio de sesión. |
| C3 | "El grupo" | **Un evento a la vez, uno por año.** Sin grupos simultáneos. |
| C4 | No menciona consumibles ni valores | **Se mantienen** topes, consumibles y el reporte del administrador. |

---

## 4. Lo que falta en el modelo de datos

Nada de esto rompe lo construido: son tablas y columnas nuevas.

### 4.1 Lista de deseos (`wishlists`)

Una por participante y evento.

| Campo | Para qué |
|---|---|
| `event_participant_id` (único) | Una lista por persona y evento |
| `likes` (texto) | "Lo que sí me gusta / Deseo" |
| `avoid` (texto) | **Restricciones sensoriales y alérgenos** (texturas, olores, gluten, caseína) |
| `categories` (JSON) | Selector rápido: café, chocolates, libros, ropa… |
| `updated_at` | Fecha de la última edición |

**Visibilidad por defecto:** solo la ve quien le regala y el administrador. Si la lista fuera pública del grupo, no habría fuga de parejas, pero expone datos sensibles de salud a 30 personas. Recomiendo restringirla.

### 4.2 Pistas (`clues`)

| Campo | Para qué |
|---|---|
| `assignment_id` | A qué pareja pertenece la pista |
| `template_key` | `color`, `gusto`, `adivinanza` (las tres plantillas) |
| `body` (texto corto) | Lo que se completa en la plantilla |
| `sent_at`, `read_at` | Orden y lectura |

**Regla de privacidad:** la pista la escribe quien regala, pero el receptor **no sabe de quién es**. La consulta se hace por `assignment.receiver_id = yo`, nunca exponiendo el `giver_id`.

### 4.3 Nota de revelación (`assignments.reveal_note`)

La "carta o notas acumuladas" de la pantalla 5. Texto opcional que solo se muestra al revelarse.

### 4.4 Preferencias de accesibilidad (`users`)

| Campo | Valores |
|---|---|
| `theme` | `calm` (por defecto), `dark` |
| `font_scale` | `100`, `115`, `130` |
| `reduce_motion` | booleano, por defecto **true** si el sistema lo pide |
| `sound_enabled` | booleano, por defecto **false** |

### 4.5 Configuración del evento

| Campo | Para qué |
|---|---|
| `allow_mutual_pairs` | `false` por defecto (evita A↔B). Si se permite, el algoritmo cambia de ciclo único a derangement simple. |

### 4.6 Moneda y tasa (`exchange_rates`)

La moneda canónica es **USD**: todos los valores se guardan en dólares. **VES es solo presentación**, convertida con la tasa del día.

| Campo | Para qué |
|---|---|
| `rate_date` | Día de la tasa |
| `base` / `quote` | `USD` / `VES` |
| `rate` | Valor de la tasa |
| `source` | `oficial` (BCV) o `paralelo` |
| `fetched_at` | Cuándo se consultó |

Fuente confirmada: **DolarAPI Venezuela**, endpoint `https://ve.dolarapi.com/v1/dolares/oficial`, que devuelve un objeto único sin clave:

```json
{"moneda":"USD","fuente":"oficial","promedio":899.2705,"fechaActualizacion":"2026-11-10T00:00:00-04:00"}
```

Se guarda el histórico por día (sincronizar dos veces el mismo día no duplica) y se expone con `php artisan exchange:sync`. Si la API falla, el sistema sigue mostrando la última tasa conocida. La moneda del evento queda en `USD` y la zona en `America/Caracas`.

---

## 5. El flujo de 5 pasos, mapeado a lo que ya existe

| Paso de la spec | Estado del evento | Condición del usuario | Pantalla |
|---|---|---|---|
| 1. Inicio de sesión | `draft` → `registration` | Está inscrito | Login (lo crea el admin) |
| 2. Sorteo Ciego | `draw_enabled` → `drawn` | Tiene asignación y ya la reveló | "Descubrir" |
| 3. Lista de Deseos | `drawn` | Tiene lista guardada | Formulario de dos secciones |
| 4. Intercambio de Pistas | `drawn` | Tiene pistas enviadas o recibidas | Buzón |
| 5. Gran Revelación | `revealed` | — | Dos tarjetas + nota |

El **stepper** no es un dato nuevo: se calcula con el estado del evento más lo que el usuario ya hizo. Eso evita una tabla de progreso que se desincroniza.

---

## 6. Accesibilidad TEA Grado 1: requisitos verificables

La spec da principios; aquí están traducidos a algo comprobable.

**Previsibilidad**

- El estado y el siguiente paso siempre visibles por texto, no solo por color.
- Ninguna pantalla cambia sola: sin refresco automático que inyecte contenido, sin modales que aparezcan sin pulsar.
- Las acciones destructivas o irreversibles piden confirmación explícita con texto claro.

**Lenguaje**

- Frases cortas, voz activa, sin ironía, sin dobles sentidos, sin "panita", sin "vacilar".
- Errores que dicen **qué pasó y qué hacer**: "La clave debe tener al menos 10 caracteres. Tienes 6."
- Nada de ambigüedad: "Paso completado" en vez de "¡Listo!" a secas.

**Carga sensorial**

- Paleta calmada: `#F9FAFB` y `#1E293B`, acento verde menta o azul suave.
- **Sin rojo puro ni amarillo fluorescente.** Los errores de Bootstrap usan `#dc3545`; hay que reemplazarlo por un tono más suave con icono y texto.
- Sin animaciones por defecto; el confeti de la revelación es opcional y respeta `prefers-reduced-motion`.
- Sin sonido en las notificaciones.

**Lectura**

- Base 16px mínimo, `line-height` 1.5, ancho de línea acotado.
- Ajuste de tamaño 100/115/130 % guardado en el perfil.
- Foco visible en todos los controles; botones de al menos 44×44 px.

**Formularios**

- Etiqueta visible siempre (no usar el placeholder como etiqueta).
- Errores junto al campo, no solo arriba de la página.
- Nada de campos obligatorios escondidos.

---

## 7. Riesgos nuevos

| Riesgo | Mitigación |
|---|---|
| Las pistas anónimas se usan para acosar | Plantillas cerradas, longitud máxima, el admin puede ver y borrar, y quedan registradas |
| La lista de deseos expone datos de salud (alérgenos) | Visibilidad restringida a quien regala + admin; consentimiento explícito al guardarla |
| Dos revelaciones simultáneas confunden | Dos tarjetas separadas y rotuladas: "A quién le regalaste" y "Quién te regaló" |
| El contador genera ansiedad | Días y horas, sin segundos ni parpadeos |
| Con 2 participantes A↔B es inevitable | Bloquear el sorteo con menos de 3; con menos de 4, advertir |

---

## 8. Plan y estimación ajustada

| Fase | Contenido | Días |
|---|---|---|
| Base | Auth, roles, MySQL, layout accesible | 2 |
| Evento y participantes | CRUD, estados, inscripción | 2 |
| Sorteo ciego | Servicio, política de anonimato, "descubrir" | 2 |
| **Lista de deseos** | Formulario, categorías, visibilidad restringida | 1 |
| **Pistas guiadas** | Plantillas, buzón, privacidad | 1.5 |
| **Accesibilidad** | Tema calmado, tipografía, movimiento, foco, copy | 1.5 |
| Obsequios, topes y valores | Regalo único, consumibles, totales en USD y VES | 2 |
| Revelación final | Dos tarjetas, nota, confeti opcional | 1 |
| Despliegue y pruebas | SSL, respaldos, pruebas de anonimato | 1.5 |
| **Total** | | **15 – 17 días** |

---

## 9. Decisiones resueltas

| # | Pregunta | Respuesta |
|---|---|---|
| 1 | Sorteo automático o manual | **Manual**, por módulo; el admin activa el evento. Sin cron ni sesión |
| 2 | Código de unión o admin crea | **El admin crea las cuentas** |
| 3 | Un grupo o varios | **Uno por año**, sin simultáneos |
| 4 | Consumibles y valores | **Se mantienen**, con topes |
| 5 | País / moneda / zona | **USD** canónico, **VES** por API, `America/Caracas` |
| 6 | Visibilidad de la lista de deseos | **Solo quien te regala y el admin** |

---

## 10. Estado y siguiente paso

**Hecho en la base:** `wishlists`, `clues`, `exchange_rates`, `assignments.reveal_note`, preferencias de accesibilidad en `users`, `events.allow_mutual_pairs`, moneda `USD` y zona `America/Caracas`.

**Siguiente, en orden:**

1. Servicio de sorteo (manual, ciclo único, idempotente) y prueba de anonimato.
2. Login y CRUD de participantes y evento del administrador.
3. Tema accesible **antes** de las vistas: si se deja al final, hay que rehacerlas.
4. Las 5 pantallas del flujo.
