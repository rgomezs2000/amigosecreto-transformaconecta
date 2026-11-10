# Factibilidad y diseño — Amigo Secreto con registro de obsequios

**Proyecto:** `amigosecreto-transformaconecta`
**Stack:** Laravel 12 (backend) + Bootstrap 5.3 y jQuery 3.7 por CDN (frontend, sin NPM)
**Base de datos:** MySQL del servidor, un solo motor (InnoDB, utf8mb4)
**Acceso:** usuario o correo + clave ahora; Google más adelante
**Fecha:** 2026-11-09
**Estado:** documento de factibilidad. No incluye desarrollo.

---

## 1. Veredicto

**Sí, es totalmente factible.** Todo lo que describes se resuelve con Laravel estándar, sin paquetes exóticos, sin servicios pagos y sin NPM. No hay ningún requisito imposible ni algorítmicamente difícil.

Pero hay **dos puntos** donde el sistema se cae si se implementa "como se imagina normalmente", y son los que hay que decidir antes de programar:

| Punto | Riesgo si se hace mal | Solución |
|---|---|---|
| Cómo se sortea | Que los últimos en "sacar" no tengan opción válida (callejón sin salida) | Generar **todo** el reparto de una sola vez, y al usuario solo mostrarle su fila |
| Anonimato | Fuga por un endpoint mal protegido (IDOR): A descubre quién lo tiene a él | Autorización explícita por política + pruebas automatizadas |

El resto (eventos, fechas, regalos, valores, roles, administrador) es CRUD y reglas de negocio: trabajo conocido y acotado.

**Complejidad:** media-baja. **Estimación MVP sólido:** 11–15 días-persona, ya incluyendo autenticación usuario/clave, notificaciones básicas y despliegue (ver §12).

---

## 2. Alcance confirmado

**Roles**

- **Participante:** se le asigna (al azar) *un* amigo secreto al que le regala. Ve **solo** a quién le regala. No ve quién le regala a él, ni las parejas, ni los regalos de nadie.
- **Administrador:** acceso total y anónimo ante los participantes. Ve **todas** las parejas, los registros de obsequios, los valores y los totales. Configura usuarios, eventos, fechas, apertura/cierre, sorteo y revelación. **No participa** en el sorteo: solo controla el sistema.
- **Acceso:** todos entran con **usuario o correo + clave**. El administrador es el único que registra usuarios; Google solo autentica cuentas existentes (§10).

**Reglas del evento**

- **Solo participantes principales.** No hay invitados ni participantes adicionales: quien no tenga cuenta creada por el administrador, no entra.
- **Sin exclusiones.** No se configuran parejas ni familiares "prohibidos"; el único par imposible es uno consigo mismo.
- **Sorteo individual y manual.** El administrador habilita el módulo; cada participante entra cuando quiere, saca una sola vez y no puede volver a sacar. Nada de cron, ni automático, ni al abrir el evento.
- **Anonimato en una sola dirección.** El participante sabe a quién le regala; nunca quién le regala a él.
- **Avisos:** en pantalla siempre; correo y WhatsApp son opcionales y nunca revelan quién le regala a quién (§15).

**Flujo**

1. El administrador crea las cuentas (usuario o correo + clave temporal), el evento y las fechas.
2. Inscribe a los participantes y abre el evento.
3. Habilita el **módulo de sorteo**.
4. Cada participante entra, cambia la clave temporal, saca su amigo secreto (una sola vez) y ve solo ese nombre.
5. Desde la fecha de inicio de la dinámica hasta el día del intercambio, cada participante registra:
   - **1 regalo de intercambio** (producto + valor), una sola vez.
   - **N consumibles** (obsequios pequeños durante la dinámica).
6. El administrador ve todo el detalle en cualquier momento; los receptores no ven nada.
7. El día del intercambio (o cuando el administrador lo dispare) se **revelan los nombres**.

**Interpretaciones validadas**

- "A le toca B; B no sabe que le toca A" → el reparto es una **permutación**: cada uno da a uno y recibe de uno. A nunca sabe quién lo eligió.
- "El administrador será anónimo" → los participantes no saben quién administra ni ven sus acciones; el administrador **sí ve todo**, es inherente a su rol.
- "El regalo es único y una sola vez" → **un registro** por participante, no duplicable. Si se puede editar antes del cierre es la decisión 6 de §14.

---

## 3. El punto crítico: sortear de a uno sin trabarse

El requisito es claro: el administrador abre el evento y **habilita el módulo de sorteo**; cada participante entra **cuando quiere**, saca **una sola vez** y ya no puede volver a sacar. Nada automático: ni cron, ni al abrir el evento, ni por sesión.

El problema es la aritmética, no la interfaz. Si cada persona saca de lo que queda, el último puede quedarse sin opción:

Ejemplo con 3 participantes (A, B, C):

```
A saca primero → puede sacar B o C (no A)
  Supongamos A → B
B saca → no puede B (él mismo), no puede B (ya salió) → solo A o C
  Si B → A  ⇒ a C solo le queda C … inválido (se sacaría a sí mismo)
  Si B → C  ⇒ a C le queda A … válido
```

Con 3 personas se traba la mitad de las veces; con 4 o más baja, pero nunca es cero. Y si a alguien le toca sacar de último y no hay opción, se entera de que el sistema lo dejó para el final: la confianza se rompe igual que si se reiniciara el sorteo.

### Cómo se cumple el requisito sin caer en la trampa

Hay dos implementaciones que respetan tu diseño (**módulo manual, cada uno saca una vez, no simultáneo**). La diferencia está en **cuándo se calcula** el reparto, no en cuándo se revela.

**Opción A — el reparto se calcula en el primer ingreso (recomendada).**

1. El administrador habilita el módulo. Todavía no se calcula nada.
2. La **primera persona** que entra al módulo dispara el cálculo completo: se construye **una permutación sin puntos fijos** (un *derangement*), por ejemplo con el algoritmo de Sattolo —recorre de atrás hacia adelante intercambiando con un índice **estrictamente menor**— y se guardan las *n* parejas en **una sola transacción**. El resultado es un ciclo único: `A→C→D→B→A`.
3. Cada participante, al entrar, recibe **su** fila y queda marcado con `drawn_at`. Puede volver a **ver** a su amigo, pero no volver a **sacar**.
4. Si alguien nunca entra, igual tiene amigo secreto asignado; los demás no se ven afectados.

Desde la perspectiva de cada usuario es exactamente lo que pediste: entra, saca, no vuelve a sacar. Y el sistema nunca se traba.

```
Ejemplo válido con 4 participantes:
  A → C
  B → A
  C → D
  D → B
  Regalos únicos, receptores únicos, nadie se tiene a sí mismo.
```

**Opción B — sorteo realmente incremental.**

Cada quien elige entre lo que queda, y el sistema **rechaza en silencio** las elecciones que dejarían al último sin opción (solo puede ocurrir cuando queda una persona y su propio nombre sigue disponible). Es fielmente "de a uno", pero tiene dos costos:

- Si alguien no entra nunca, esa persona queda **sin amigo secreto** y el reparto incompleto.
- La última elección puede "corregirse" sin que el usuario lo note: es correcto, pero es una maña invisible.

**Recomendación:** A. Cumple el requisito tal como lo describes y elimina los dos costos de B. Si aun así prefieres B, es implementable: suma ~1 día y hay que decidir qué pasa cuando alguien no entra.

**Consecuencia en ambas:** el botón queda atado a `drawn_at`; una vez usado, el módulo muestra el amigo y no un botón nuevo. Eso es lo que impide "volver a sacar si no me gustó".

**Caso n = 2:** el reparto es obligatoriamente A↔B, así que el anonimato es matemáticamente imposible. **Mínimo recomendado: 4 participantes.** Con 3 funciona pero es muy predecible. El sistema debe bloquear el sorteo con menos de 2 y advertir con menos de 4.

---

## 4. Modelo de datos propuesto

```
users ──< event_participants >── events
                                    │
                                    ├──< assignments >── users (giver, receiver)
                                    │        ├──1 exchange_gift
                                    │        └──< consumable_gifts
                                    └──< audit_logs
```

| Tabla | Campos clave | Para qué |
|---|---|---|
| `users` | `name`, `username`, `email` (opcional), `password`, `role` (`admin`/`participant`) | Cuentas. Rol simple: basta una columna, no hace falta un paquete de permisos. Detalle completo en §10.2. |
| `events` | `name`, `year` (único), `status`, `registration_opens_at`, `registration_closes_at`, `dynamic_starts_at`, `exchange_at`, `min_value`, `max_value`, `consumable_value_cap`, `reveal_shows_value`, `currency` | Ciclo de vida y configuración del evento. |
| `event_participants` | `event_id`, `user_id`, `confirmed_at`, `status` | Inscripción. `unique(event_id, user_id)`. |
| `assignments` | `event_id`, `giver_id`, `receiver_id`, `drawn_at`, `revealed_at` | El reparto. `unique(event_id, giver_id)`, `unique(event_id, receiver_id)`, `check giver_id <> receiver_id`. |
| `exchange_gifts` | `assignment_id` **UNIQUE**, `product`, `description`, `value`, `registered_at` | El regalo único del intercambio. El índice único hace **imposible** registrarlo dos veces. |
| `consumable_gifts` | `assignment_id`, `description`, `quantity`, `value`, `given_on`, `registered_at` | N consumibles. Sin límite (o con tope configurable). |
| `audit_logs` | `user_id`, `event_id`, `action`, `payload`, `created_at` | Quién sorteó, reinició, reveló, editó. Indispensable cuando hay dinero de por medio. |
| `notifications` | `id` (uuid), `type`, `notifiable`, `data` (JSON), `read_at` | Avisos en pantalla (tabla estándar de Laravel, `php artisan notifications:table`). |
| `push_subscriptions` | `user_id`, `endpoint`, `public_key`, `auth_token` | Solo si se implementa Web Push real. |

**Por qué dos tablas de regalos y no una con `type`:** la regla "el regalo de intercambio es único, los consumibles son N" queda garantizada **en la base de datos** con un `UNIQUE` real, no solo en el código. Si mañana hay un doble clic, una petición repetida o un bug, la base lo rechaza igual. (Una sola tabla con `type` no puede tener un único parcial en MySQL; existiría un truco con columna nula, pero dos tablas es más claro.)

**Notas:**

- Todo cuelga de `event_id`: se guarda el evento del año y el historial de años anteriores (uno por año, sin simultáneos).
- `assignments` guarda IDs; el nombre del amigo secreto se resuelve por relación. No se duplica el nombre (evita inconsistencias si alguien cambia de nombre).
- Valores monetarios en `decimal(10,2)`, nunca `float`.
- Fechas en `America/Caracas` (aplicación y sesión MySQL `-04:00`, sin horario de verano); las fechas del dominio van como `DATETIME`.
- Moneda canónica **USD**; el equivalente en **VES** se calcula con la tasa del día (`exchange_rates`). Ver `docs/adaptacion-especificacion-roger.md`.

### 4.1 Notas para MySQL (un solo motor: el del servidor)

En el equipo local verifiqué **MariaDB 10.4.32** en `C:\xampp\mysql\bin`, escuchando en el puerto 3306; el servidor usará el MySQL que ya tiene instalado. Laravel trae la conexión `mysql` configurada con `utf8mb4` / `utf8mb4_unicode_ci`, `strict => true` e InnoDB por defecto. El cambio es de **configuración** (`.env`), no de código.

**Lo que MySQL obliga a decidir:**

| Tema | Implicación |
|---|---|
| Índices únicos parciales | **No existen** en MySQL/MariaDB → las dos tablas de regalos (`exchange_gifts` con `UNIQUE(assignment_id)`) no son una preferencia estética: son la única forma limpia de garantizar "un solo regalo de intercambio". |
| `CHECK` | Se aplica en MySQL ≥ 8.0.16 y MariaDB ≥ 10.2. En MySQL 5.7 se **ignora en silencio**, así que la garantía real sigue siendo la aplicación + los índices únicos. |
| `UNIQUE` y colación | `utf8mb4_unicode_ci` es **case-insensitive**: `Juan` y `juan` chocan. Refuerza la normalización del `username` (§10.2). |
| Borrado | Usar `restrictOnDelete` en `assignments` y regalos, **no** `cascade`: un borrado accidental debe fallar, no arrastrar el historial. |
| Concurrencia | MySQL permite escrituras concurrentes. El sorteo debe bloquear la fila del evento (`SELECT ... FOR UPDATE`) dentro de la transacción y reintentar ante *deadlock* (`DB::transaction($fn, 3)`). |
| Reloj | Agregar `'timezone' => '+00:00'` a la conexión (Laravel no lo pone por defecto) o usar columnas `datetime`; si no, `TIMESTAMP` convierte con la zona del servidor y las comparaciones por fecha se corren. |
| Dinero | `decimal(10,2)`, nunca `float`. |
| Modo estricto | Mantener `strict => true`: rechaza datos inválidos en vez de truncarlos en silencio. |

**Configuración concreta:**

```sql
CREATE DATABASE amigosecreto_transformaconecta
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'amigosecreto'@'localhost' IDENTIFIED BY '<clave-fuerte>';
GRANT ALL PRIVILEGES ON amigosecreto_transformaconecta.* TO 'amigosecreto'@'localhost';
```

```dotenv
# Usa "mariadb" si el servidor es MariaDB, "mysql" si es MySQL
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amigosecreto_transformaconecta
DB_USERNAME=amigosecreto
DB_PASSWORD=<clave-fuerte>
```

> En `.env`, los comentarios van en una línea aparte: un `#` al final del valor termina formando parte del valor.

**No usar `root`.** XAMPP lo trae sin clave; reutilizarlo en producción es una puerta abierta.

**Respaldos:** `mysqldump` antes del sorteo y antes del día del intercambio. Es el respaldo más barato y el que salva el evento.

**La versión del servidor manda.** Como se usa el MySQL que ya está instalado y no se va a instalar otro, antes de escribir código conviene comprobar cuatro cosas; con eso se cierra el diseño:

```sql
SELECT VERSION();                                  -- 5.7 / 8.0 / MariaDB 10.x
SELECT @@sql_mode;
SHOW VARIABLES LIKE 'innodb_default_row_format';   -- dynamic = índices largos OK
SHOW VARIABLES LIKE 'character_set_server';        -- debe ser utf8mb4
```

Qué implica cada resultado:

- **MySQL ≥ 8.0.16 o MariaDB ≥ 10.2** → `CHECK` se aplica de verdad.
- **MySQL 5.7** → `CHECK` se acepta pero **no se valida**. La regla "nadie se saca a sí mismo" queda garantizada por la aplicación y por los índices únicos, no por el `CHECK`.
- **`ONLY_FULL_GROUP_BY`** no depende del motor: con `strict => true`, Laravel fija el `sql_mode` de sesión y **lo incluye igual** en MariaDB y en MySQL. Las consultas de totales deben agrupar completo desde el primer día.
- **MariaDB 10.4** (la del XAMPP local) está fuera de soporte desde 2024. Funciona, pero si también es la de producción es deuda técnica que conviene anotar.

Lo que **no** depende de la versión: `utf8mb4` + `utf8mb4_unicode_ci`, InnoDB, `decimal(10,2)`, índices únicos compuestos y `SELECT ... FOR UPDATE`. Eso funciona igual de MySQL 5.7 en adelante.

**Pruebas:** si todo es MySQL, `phpunit.xml` debe dejar de forzar SQLite en memoria y apuntar a una base de pruebas aparte (`amigosecreto_test`) con `RefreshDatabase`. Cuesta unos segundos más por corrida y elimina el clásico "en pruebas pasaba".

**Datos existentes:** la base SQLite actual solo tiene las tablas por defecto, sin datos del sistema. Al pasar a MySQL se parte de cero con `php artisan migrate`.

---

## 5. Reglas de negocio

| # | Regla |
|---|---|
| RN-1 | Cada participante da a **exactamente uno** y recibe de **exactamente uno**, nunca de sí mismo. |
| RN-2 | El reparto se genera **una sola vez** por evento, en una transacción. Volver a pedirlo devuelve el existente (idempotente). Solo un administrador puede reiniciarlo, y queda en auditoría. |
| RN-3 | El participante ve **solo** su amigo secreto. Nunca la relación inversa ("quién me tiene"), ni las parejas, ni los regalos ajenos. |
| RN-4 | Los obsequios solo se registran entre `dynamic_starts_at` y `exchange_at`, y solo con el evento en estado `sorteado`. Se valida en el servidor, no solo ocultando el botón. |
| RN-5 | Un solo regalo de intercambio por participante (producto + valor obligatorios), **editable** hasta el cierre. Consumibles: **sin límite de cantidad** hasta el día del intercambio, con valor **opcional**; el administrador puede fijar un tope de valor por evento. |
| RN-6 | El valor del regalo de intercambio se valida contra `min_value`/`max_value`; el de los consumibles, contra `consumable_value_cap` si está configurado. Se totaliza por participante para el administrador; el participante solo ve su propio total. |
| RN-7 | La revelación solo la dispara el administrador y muestra **el nombre del que le regaló y el regalo de intercambio**. Los consumibles y los valores **no** se revelan. |
| RN-8 | El estado del evento condiciona todo: no se sortea en borrador, no se registra en borrador, no se revela antes de tiempo. |
| RN-9 | Después del sorteo, la nómina queda **congelada**. Agregar, quitar o desactivar a alguien obliga a reiniciar y volver a sortear (con aviso y auditoría). |
| RN-10 | Toda acción sensible del administrador queda registrada: sorteo, reinicio, revelación, edición de valores. |
| RN-11 | El módulo de sorteo se **habilita manualmente** por el administrador y solo si el evento no tiene reparto. Cada participante entra por su cuenta, saca **una sola vez** (`drawn_at`) y no puede volver a sacar; sí puede volver a **ver** a su amigo. Nada se calcula por cron, ni al abrir el evento, ni por sesión. |
| RN-12 | El administrador **no participa**: sus cuentas no entran en el reparto. Solo los participantes inscritos. |
| RN-13 | Solo **un evento activo a la vez**, uno por año. No hay eventos simultáneos; los anteriores quedan como historial. |
| RN-14 | **Notificaciones**: en pantalla siempre; correo y WhatsApp según lo que configure el administrador. Ninguna revela el par ni el regalo (§15.4). El sorteo sigue siendo manual; solo recordatorios opcionales usan cron. |

---

## 6. Anonimato: qué es posible y qué no

### Matriz de visibilidad

| Información | Participante | Administrador |
|---|---|---|
| A quién le regala (su amigo secreto) | ✅ solo el suyo | ✅ todos |
| Quién le regala a él ("quién me tiene") | ❌; al revelarse, ✅ | ✅ |
| Parejas completas del evento | ❌ | ✅ |
| Sus propios obsequios y valores | ✅ | ✅ |
| Obsequios que recibe | ❌; al revelarse, **solo el regalo de intercambio** | ✅ |
| Obsequios y valores de terceros | ❌ | ✅ |
| Total gastado por cada participante | solo el suyo | ✅ todos |
| Identidad/rol del administrador | ❌ | — |

### Los tres niveles de anonimato (elige uno)

1. **Anonimato entre participantes, el administrador ve todo.** Es lo que describes y lo que recomiendo. El dato existe en la base; el anonimato se garantiza con **control de acceso**, no con criptografía.
2. **Ni el administrador ve las parejas** (solo ve montos y reportes agregados). Técnicamente posible, pero débil: quien administra suele tener acceso a la base o a los backups. Sería seguridad "de mentira".
3. **Anonimato criptográfico** (sobres sellados / *commit-reveal*), donde ni el administrador puede ver las parejas antes de la revelación. Es factible pero es un proyecto aparte y no lo necesitas.

**Advertencia honesta:** con el nivel 1, cualquiera con acceso a la base de datos o a un backup ve todas las parejas. Eso es aceptable siempre que sea una decisión consciente. Si el requisito real fuera "ni el administrador debe poder ver", habría que rediseñar con cifrado y sería mucho más caro.

### Dónde se fuga el anonimato en la práctica

No se fuga por las matemáticas: se fuga por **código**. Los vectores reales:

- Endpoint que devuelve "todas las asignaciones" y el participante puede llamarlo.
- IDOR: cambiar `/mi-regalo/12` a `/mi-regalo/13` y ver el de otro. Se arregla con *policies* de Laravel, no con la URL.
- Enviar el objeto completo al frontend (`$assignment->toArray()` incluye `giver_id` y `receiver_id`).
- Mensajes de error o logs con nombres (`APP_DEBUG=true` en producción).
- Un listado de participantes ordenado de forma que revele algo, o un contador de regalos por persona.

**Mitigación concreta:** una `AssignmentPolicy` (`view` = `$user->id === $assignment->giver_id || $user->isAdmin()`), *API Resources* que solo expongan campos permitidos, y pruebas automatizadas que inicien sesión como cada participante e intenten leer datos ajenos esperando 403/404.

---

## 7. Ciclo de vida del evento

```
 borrador ──▶ inscripción ──▶ sorteo habilitado ──▶ sorteado ──▶ cerrado ──▶ revelado
    │              │                 │                  │            │
    │              │                 │                  │            └─ se cerró el registro / llegó la fecha
    │              │                 │                  └─ reparto calculado; cada uno saca una vez y registra obsequios
    │              │                 └─ el admin habilita el módulo; el primer ingreso calcula el reparto
    │              └─ se inscriben participantes, se confirman
    └─ el admin configura fechas, cupos, valores
```

| Estado | Quién entra | Qué se puede hacer | Qué NO |
|---|---|---|---|
| `borrador` | Admin | Configurar fechas, cargar usuarios | Inscribirse, sortear |
| `inscripción` | Admin / participantes | Inscribirse, confirmar, editar datos | Sortear con < 2 (ideal < 4) |
| `sorteo habilitado` | Admin / participantes | El primer ingreso calcula el reparto y pasa a `sorteado` | Ver parejas ajenas |
| `sorteado` | Participantes | Revelar su amigo, registrar 1 regalo + N consumibles | Ver parejas ajenas, cambiar nómina |
| `cerrado` | Solo lectura | Ver reportes; listo para el evento | Registrar más obsequios |
| `revelado` | Participantes + admin | Ver quién le regaló (y su historial, si se configura) | — |

**No hay transiciones automáticas.** Ni cron ni `schedule`: el administrador habilita el sorteo, cierra el registro y dispara la revelación; los participantes solo disparan su propio ingreso al módulo. Es exactamente el requisito de "manual".

---

## 8. Cómo se garantiza "no repetir" y "una sola vez"

Tres capas, de la más fuerte a la más débil:

1. **Base de datos (la definitiva):**
   - `unique(event_id, giver_id)` → nadie entrega dos veces.
   - `unique(event_id, receiver_id)` → nadie recibe dos veces (esto es exactamente tu "si sale un usuario, no puede volver a asignar otro").
   - `check(giver_id <> receiver_id)` → nadie se saca a sí mismo.
   - `unique(assignment_id)` en `exchange_gifts` → un solo regalo de intercambio, aunque lleguen dos peticiones simultáneas.
2. **Transacción + idempotencia:** el sorteo y el registro de regalos corren dentro de una transacción; un doble clic devuelve el resultado existente en lugar de duplicar. Si dos peticiones compiten, la restricción única rechaza la segunda y el sistema responde con lo ya creado.
3. **Validación en la aplicación:** mensajes claros ("ya registraste tu regalo de intercambio", "el sorteo ya se realizó"), fechas, estados y rangos de valor.

---

## 9. Encaje con el stack pedido

Ya verifiqué el proyecto en disco:

| Requisito | Estado real | Nota |
|---|---|---|
| Laravel | 12.71.0 instalado, PHP 8.2.12 | ✅ Compatible con PHP 8.2+ |
| Bootstrap por CDN | No instalado | ✅ Solo agregar `<link>` en el layout |
| jQuery por CDN | No instalado | ✅ Solo agregar `<script>` |
| Sin NPM | `package.json` y `vite.config.js` existen pero no se usan | ✅ Se ignoran; **no** usar `@vite()` |
| Base de datos | **MySQL del servidor**; en el equipo local es MariaDB 10.4.32 con el puerto 3306 activo. El `.env` ya apunta a la base `amigosecreto_transformaconecta` | ✅ Un solo motor: MySQL, sin SQLite (ver §4.1) |
| Autenticación | **No hay** scaffolding de auth | ⚠️ Se implementa usuario/clave en la fase 0 (ver §10) |
| Zona horaria | `America/Caracas` | ✅ Configurada en app y sesión MySQL `-04:00` |

**Sobre la autenticación (el único faltante real):** queda definida en detalle en el §10. En resumen: login propio con **usuario y clave** (`Auth::attempt`, vistas Bootstrap por CDN, ~150 líneas), sin NPM y sin depender de correo.

- `laravel/ui` 4.x sí soporta Laravel 12 (9–13), pero su login es **por correo**: habría que modificarlo. Para este caso, el login propio es más directo.
- `laravel/socialite` (rama 5.x, exige `illuminate ^12.0|^13.0`) es la vía para OAuth después. Solo Composer, no toca NPM ni Vite.
- Laravel Fortify (solo backend, sin NPM) queda como opción si más adelante se quiere 2FA o recuperación por correo sin escribirla a mano.

**Recomendación de producto:** que el **administrador cree las cuentas**, en lugar de registro abierto. Así nadie ajeno se cuela en el sorteo y se controla exactamente quién participa.

**Riesgo del CDN:** si el evento es presencial y el lugar no tiene internet, la interfaz se ve sin estilos. Mitigación barata: descargar Bootstrap y jQuery a `public/vendor/` y cargarlos localmente (sigue siendo sin NPM, solo son archivos estáticos).

**Snippets de referencia (CDN):**

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
```

jQuery se usaría para: revelar el amigo secreto sin recargar, agregar consumibles en un modal y validar en vivo. Todo con *fallback* a un `POST` normal, para que funcione aunque JavaScript falle.

### 9.1 Hosting gratuito: qué exigir

El sistema es pequeño —pocas tablas, sin archivos subidos, sin colas, sin cron—, así que cualquier host que pase estos filtros sirve. El problema es que la mayoría de los gratuitos no los pasa:

| Filtro | Por qué | Qué pasa si falla |
|---|---|---|
| **PHP ≥ 8.2** | Laravel 12 lo exige | La aplicación no arranca |
| **HTTPS gratis** (Let's Encrypt) | Usuario y clave viajan en cada login; Google exige callback HTTPS | Credenciales sin cifrar; Google no funciona |
| **Raíz web en `public/`** (o poder dejar la app fuera de `public_html`) | Laravel guarda `.env` y el código fuera de `public/` | Un `.env` descargable expone la base, la `APP_KEY` y las credenciales de Google |
| **SSH o Composer** | Sin eso no hay `composer install` ni `php artisan migrate` | Toca subir `vendor/` compilado y migrar desde local |
| **Salida HTTPS permitida** | Google OAuth llama a `googleapis.com` | El botón de Google falla; el login normal sigue funcionando |

**MySQL gratis** suele venir con una sola base y nombres prefijados por el host. Alcanza de sobra: el sistema completo pesa unos pocos MB.

**El riesgo real no es el disco, es el día del evento.** Los hostings gratuitos duermen el sitio por inactividad, limitan procesos y pueden suspender la cuenta. Para una dinámica de semanas funciona; para el día del intercambio, prueba unos días antes y ten el reporte exportado.

**Nunca** dejes la raíz web en la carpeta del proyecto para "que funcione". Es la forma más común de publicar un `.env`. Si el host no permite cambiar la raíz, mueve la aplicación fuera de `public_html` y deja solo el contenido de `public/` adentro.

**Recomendación:** si no aparece un hosting gratis con **PHP 8.2 + HTTPS + SSH**, un plan compartido de USD 2–3 al mes cuesta menos que una tarde de configuración y elimina el riesgo de que el sitio duerma justo el día del evento.

### 9.2 VPS con créditos de AWS o GCP (¿es gratis?)

Sí, es factible y para Laravel es **mejor que un hosting compartido**: en un VPS tienes PHP 8.2+, la raíz web en `public/`, Composer, migraciones y SSL. Con 1 GB de RAM alcanza de sobra para este sistema.

Pero "gratis" tiene fecha de vencimiento:

| | AWS | Google Cloud |
|---|---|---|
| Crédito | Hasta **USD 200** (100 al crear + 100 por actividades), válido **12 meses** | **USD 300** por **90 días** |
| Si no se usa | El plan gratuito cierra a los **6 meses** o al agotar el crédito | La cuenta de prueba cierra a los 90 días o al agotar el crédito |
| Al cerrarse | Cuenta cerrada, con periodo de gracia para recuperar | Recursos detenidos, **30 días** para recuperarlos; después se borran |
| Cobro | Plan gratuito: no puede generar cargos. Plan de pago: sí al agotar el crédito | La prueba no cobra; al pasar a pago, sí |

**La pregunta correcta no es "¿es gratis?" sino "¿el crédito alcanza hasta el día del intercambio?"** Si el evento cae dentro de 2–3 meses, los USD 300 de GCP lo cubren con holgura. Si cae dentro de 6 meses, el crédito ya venció y te quedas sin servidor justo cuando lo necesitas.

**Costos que el crédito no cubre o que se comen el saldo:**

- **El dominio.** Google OAuth exige HTTPS con un nombre válido, y Let's Encrypt no emite certificados para una IP. Un dominio cuesta ~USD 10 al año. Truco gratis: `sslip.io` o `nip.io` resuelven `mi-servidor.34.123.45.67.sslip.io` a tu IP, y Let's Encrypt sí emite para ese nombre.
- **La IP pública.** AWS la cobra aparte desde 2024: consume crédito aunque la instancia sea "económica".
- **Base de datos administrada.** RDS o Cloud SQL se comen el crédito rápido. **Instala MySQL/MariaDB en la misma VM**: el sistema es pequeño y no necesita más.
- **Salida de datos.** Irrelevante para 30 personas.

**Lo que te toca hacer a ti** (y que un hosting compartido hace por ti): abrir solo 22/80/443, mantener el sistema actualizado, no exponer MySQL a internet y tener respaldos. Es medio día de trabajo si ya sabes, y una fuente de problemas si no.

**Arquitectura mínima recomendada:** una sola VM Ubuntu LTS, 1–2 GB de RAM, nginx + PHP-FPM 8.3 + MariaDB, Certbot para SSL, MySQL escuchando solo en `127.0.0.1`, `mysqldump` diario y **swap** de 1–2 GB (con 1 GB de RAM, MySQL y PHP juntos se quedan cortos sin swap). Snapshot de la VM antes del día del evento.

**Veredicto:** viable y recomendable si el evento cae dentro de la ventana del crédito. Si no, o si no quieres administrar un servidor, un VPS de USD 5 al mes o un hosting compartido de USD 2–3 sale más barato que el tiempo de operación.

### 9.3 Despliegue en el EC2 actual

Servidor: `ec2-3-142-153-156.us-east-2.compute.amazonaws.com` (región us-east-2). Es el **DNS público por defecto de EC2**, no un dominio propio. Eso tiene tres consecuencias concretas:

| Consecuencia | Detalle |
|---|---|
| **HTTPS: sí se puede** | Let's Encrypt valida el **control** del nombre por HTTP-01, no la propiedad. Con el puerto 80 abierto, `certbot` emite para ese hostname. Lo que **no** aplica es AWS Certificate Manager ni un certificado comercial: para eso hace falta un dominio propio. |
| **Google OAuth: no** | Google rechaza redirect URIs que no sean un "top private domain", y `amazonaws.com` es de Amazon. El error exacto es *"Invalid Redirect: must use a domain that is a valid top private domain"*. Para Google necesitas un dominio real (~USD 10/año). |
| **El nombre cambia** | Al **detener e iniciar** la instancia sin Elastic IP cambia la IP pública y con ella el hostname: el certificado deja de valer y los enlaces guardados se rompen. Con **Elastic IP** el nombre se mantiene (AWS cobra la IPv4 pública, cubierta por el crédito mientras dure). |

**Antes de publicar nada**, revisa qué hay instalado:

```bash
php -v && composer -V && mysql --version
nginx -v 2>&1 || apache2 -v 2>&1
```

Laravel 12 exige **PHP ≥ 8.2** y estas extensiones: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `openssl`, `tokenizer`, `ctype`, `json`, `bcmath`, `fileinfo`.

**Runbook mínimo:**

1. **Elastic IP** asociada a la instancia (si no, el hostname cambia al reiniciar).
2. **Security group:** 22 solo desde tu IP, 80 y 443 abiertos; **3306 nunca**.
3. **MySQL local**, escuchando en `127.0.0.1`, con usuario dedicado y `utf8mb4_unicode_ci`.
4. Código en `/var/www/amigosecreto`, docroot en `/var/www/amigosecreto/public`.
5. Permisos: `storage/` y `bootstrap/cache/` escribibles por el usuario del servidor web.
6. `.env` con `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con el hostname real y sesiones en base de datos.
7. `composer install --no-dev --optimize-autoloader`, `php artisan key:generate`, `migrate --force`, y luego `config:cache`, `route:cache`, `view:cache`.
8. **HTTPS con certbot**, después de que el vhost responda por HTTP.
9. Swap de 1–2 GB si la instancia tiene 1 GB de RAM.
10. Respaldo diario (`mysqldump`) **descargado fuera de la instancia**.

**Aviso de seguridad:** el hostname es público y conocido; los escaneos automáticos empiezan en minutos. `APP_DEBUG=true` en producción filtra credenciales en pantalla, y una raíz web en la carpeta del proyecto publica el `.env`. Son los dos errores más caros y los más comunes.

**Sobre OAuth:** cuando toque Google, el callback será `https://<dominio-real>/auth/google/callback`. El hostname actual **no** sirve para Google, así que compra el dominio antes de esa fase. Mientras tanto, usuario y clave funcionan sin problema.

---

## 10. Autenticación e identidad

**Decisión confirmada:** login con **usuario o correo** en un solo campo y clave; **Google** más adelante, sin rehacer lo construido.

### 10.1 Cómo se entra ahora

| Aspecto | Decisión |
|---|---|
| Credencial | **usuario o correo**, los dos en un solo campo |
| Política de clave | Mínimo **10 caracteres**, con mayúscula y minúscula |
| Quién crea las cuentas | Solo el **administrador** (el único que registra usuarios); entrega la clave temporal por WhatsApp o en persona |
| Primer ingreso | Obliga a **cambiar la clave** (`must_change_password`) |
| Hash | bcrypt (cast `hashed` de Laravel), nunca reversible |
| Intentos fallidos | 5 por minuto por identificador+IP, con bloqueo temporal |
| Sesión | Se regenera el ID al entrar; `remember me` opcional; al salir se invalida |
| Error de login | Genérico: "usuario o clave incorrectos" |
| Recuperación | **No hay** por correo en el MVP: el administrador resetea y fuerza cambio |
| Baja | **Desactivar** (`is_active`), nunca borrar |

**Un solo campo, dos identificadores.** El formulario tiene un único input (`login`) que acepta usuario o correo, y el servidor decide según el contenido:

- Si el texto **contiene `@`** → se busca por `email`.
- Si **no** contiene `@` → se busca por `username`.

El `@` es el separador natural, y funciona porque el `username` no lo admite (`^[a-z0-9._-]{3,20}$`). Así no hay ambigüedad ni forma de que un usuario se registre con el correo de otro.

Dos detalles de implementación:

- **Normalizar a minúsculas antes de buscar**, tanto el usuario como el correo. La comparación es de identificadores; la **clave, en cambio, distingue mayúsculas**.
- **Error siempre genérico** y un hash señuelo cuando la cuenta no existe, para que el tiempo de respuesta no revele si el identificador está registrado.

`Auth::attempt` no sirve tal cual con dos columnas: se resuelve con una consulta + `Hash::check` + `Auth::login`, dentro del servicio que después reutilizará Google.

**Política de clave.** Mínimo 10 caracteres, con al menos una mayúscula y una minúscula, sin exigir símbolos ni números. Se aplica al cambiarla y no se recorta el texto. Ojo con la interpretación: bcrypt **ya** distingue mayúsculas de minúsculas; la regla de mezclar ambas se agrega para que la política sea visible y no quede en una sola letra repetida. Si solo se quería "que distinga mayúsculas", esa parte ya está cubierta por el hash y la regla extra es opcional.

**Por qué usuario y no solo correo:** no todos los participantes tienen correo o quieren darlo, el usuario se dicta por WhatsApp y no se depende de un servidor de correo para arrancar. El correo queda **opcional**, útil para notificaciones y para vincular Google.

**Por qué no borrar usuarios:** borrar arrastra en cascada sus asignaciones y obsequios, y rompe el historial del evento. Se **desactiva**.

### 10.2 Esquema mínimo de identidad

```sql
users
  id, name,
  username              UNIQUE NOT NULL,
  email                 NULL UNIQUE,
  password              NULL,              -- NULL = cuenta sin clave local (futuro Google)
  email_verified_at     TIMESTAMP NULL,    -- requisito para enlazar Google sin pedir clave
  role                  'admin' | 'participant',
  is_active             BOOLEAN DEFAULT 1,
  must_change_password  BOOLEAN DEFAULT 0,
  last_login_at         TIMESTAMP NULL,
  phone                 NULL,              -- para WhatsApp / wa.me
  notify_email          BOOLEAN DEFAULT 1,
  notify_whatsapp       BOOLEAN DEFAULT 0,
  timestamps
```

Tres detalles que parecen menores y son caros de cambiar después:

- **`password` debe admitir NULL desde ahora.** Si mañana alguien entra solo con Google, no tendrá clave local. La migración por defecto lo deja `NOT NULL`.
- **`email` opcional pero único.** Varios `NULL` no chocan con el índice único (MySQL y SQLite lo permiten), así que los participantes sin correo conviven sin problema.
- **`email_verified_at` se conserva** (Laravel ya la trae). Es la señal de que el correo está confirmado y la condición para enlazar Google sin pedir la clave actual.

Y uno de comportamiento: **normalizar `username` a minúsculas al guardar**. SQLite compara el `UNIQUE` distinguiendo mayúsculas y MySQL no; sin normalizar, `Juan` y `juan` podrían coexistir en desarrollo y chocar en producción (o al revés).

### 10.3 Lo que hace falta para el login (y nada más)

- Tabla `users` como la de arriba.
- Rutas `GET/POST /login` y `POST /logout`.
- Un `LoginService` que resuelve usuario-o-correo, verifica con `Hash::check` y llama a `Auth::login`.
- Un `RateLimiter` para el login (en `AppServiceProvider`).
- Un middleware `role:admin` con su alias en `bootstrap/app.php`.
- `User::isAdmin()` y `User::hasPassword()`.
- Una regla de validación de clave: mínimo 10, mayúscula y minúscula.

Son ~150 líneas de PHP y dos vistas Bootstrap. No requiere paquetes, ni NPM, ni correo.

### 10.4 Google después, sin rehacer nada

**Solo Google.** Valida el correo del usuario y devuelve un identificador estable, así que es el proveedor más simple y suficiente. Apple y Facebook quedan descartados.

Lo único que hay que hacer ahora es **no cerrarse puertas**:

| Ya (fase 0) | Después (Google) |
|---|---|
| `password` y `email` nullable, `email_verified_at` presente | Tabla `social_accounts` con `provider = 'google'` |
| Login aislado en un `LoginService` | El mismo servicio usa `Auth::login($user)` |
| Rutas `auth/google/redirect` y `auth/google/callback` reservadas | `laravel/socialite` |
| `APP_URL` correcto y HTTPS en producción | Credenciales en Google Cloud Console |
| Throttle en el login | Reutilizar el mismo en el callback |

```sql
social_accounts
  id, user_id, provider, provider_user_id,
  UNIQUE(provider, provider_user_id)
```

**`provider_user_id` es el `sub` de Google**, no el correo. El `sub` no cambia aunque la persona cambie de correo; el correo sí.

**Google no crea cuentas. Decisión cerrada.** El único que registra usuarios es el administrador. Google —y el login con clave— solo **entran** a cuentas que ya existen. Si el correo de Google no corresponde a ninguna cuenta, se muestra "tu cuenta de Google no está registrada, contacta al administrador" y **no se crea nada**. Es lo que impide que cualquiera con un Gmail entre al sorteo.

**Orden de búsqueda al entrar con Google** (el orden importa):

1. Se busca en `social_accounts` por `(provider = 'google', provider_user_id = sub)`. Si existe, se entra con esa cuenta.
2. Si no existe, se busca en `users` por el correo que devolvió Google. Si hay cuenta y se puede enlazar, se enlaza y se entra.
3. Si no hay coincidencia, se rechaza. **Nunca se crea un usuario.**

**Enlace de la cuenta (la trampa).** Google verifica el correo, pero eso no autoriza a enlazar por coincidencia. Dos caminos:

- Si el administrador marcó el correo como **verificado**, se enlaza directo.
- Si no, se pide **una vez** la clave local antes de vincular.

Así, un correo mal escrito por el administrador no termina entregando una cuenta —posiblemente de administrador— a un tercero.

**Consecuencia operativa:** un participante **sin correo** no puede usar Google; entra con usuario y clave. El administrador decide a quién le carga correo.

**En Google Cloud Console:** proyecto, pantalla de consentimiento, credencial OAuth 2.0 de tipo "Aplicación web", y URIs de redirección `http://localhost/auth/google/callback` en desarrollo y `https://tu-dominio/auth/google/callback` en producción. Scopes: `openid email profile`.

**Una vez enlazada**, el usuario puede entrar con clave o con Google, indistintamente.

**Costo:** `laravel/socialite` por Composer (+0,5–1 día). Nada de NPM.

### 10.5 El administrador "anónimo"

- El área `/admin` tiene su propio login y **no aparece enlazada** desde la interfaz de participantes.
- Misma tabla `users` y mismo *guard*, separados por rol (`role = admin`) y middleware.
- En pantallas públicas no se muestra quién administra ni qué acciones hizo; solo el efecto visible ("el sorteo ya se realizó").

### 10.6 Riesgos propios de la autenticación

| Riesgo | Mitigación |
|---|---|
| Fuerza bruta o claves débiles | Throttle 5/min, mínimo 10 caracteres, cambio obligatorio de la temporal |
| Clave temporal que nadie cambia | `must_change_password` obligatorio en el primer ingreso |
| Enlace OAuth indebido (robo de cuenta) | Vincular solo con prueba de posesión (clave actual o correo verificado) |
| Sesión fijada o robada | Regenerar ID al entrar; cookies `http_only`, `same_site=lax`, `secure` con HTTPS |
| Clave viajando sin cifrar | HTTPS en producción; en local no aplica |
| Usuario desactivado con sesión abierta | El middleware verifica `is_active` en cada petición |
| Un solo campo con dos identificadores: ambigüedad | Regla del `@` + normalización a minúsculas |
| Enumeración de usuarios por tiempo de respuesta | Error genérico + hash señuelo cuando la cuenta no existe |
| Cuenta enlazada a Google por un correo mal escrito | Enlazar solo con correo verificado o con la clave actual |
| Alguien con Gmail entra sin haber sido invitado | Google **no crea cuentas**; solo autentica las existentes |

---

## 11. Pantallas y rutas propuestas

**Participante**

| Ruta | Pantalla |
|---|---|
| `GET/POST /login` | Inicio de sesión (usuario **o** correo + clave) |
| `POST /logout` | Cerrar sesión |
| `GET/POST /mi-clave` | Cambio de clave (obligatorio en el primer ingreso) |
| `GET /` | Mi evento: estado, fechas, cuenta regresiva, lista de participantes (solo nombres) |
| `GET /evento/{event}/sorteo` | Módulo de sorteo (lo habilita el admin; el primer ingreso calcula el reparto) |
| `POST /evento/{event}/sorteo` | Sacar mi amigo secreto (una sola vez; queda `drawn_at`) |
| `GET /evento/{event}/mi-amigo` | Ficha: nombre (volver a verlo no vuelve a sortear) |
| `GET/POST /evento/{event}/regalos` | Mis obsequios: 1 intercambio + N consumibles |
| `GET /evento/{event}/revelacion` | Después del evento: quién me regaló |

**Administrador** (`/admin`, middleware de rol)

| Ruta | Pantalla |
|---|---|
| `GET/POST /admin/login` | Acceso del administrador (separado, sin enlace público) |
| `GET /admin/eventos` | Lista, crear, abrir, cerrar, habilitar sorteo, revelar |
| `POST /admin/eventos/{e}/habilitar-sorteo` | Habilitar o deshabilitar el módulo de sorteo |
| `GET /admin/eventos/{e}` | Detalle: participantes, estado, fechas, acciones |
| `GET /admin/eventos/{e}/parejas` | **Solo admin:** quién le da a quién |
| `GET /admin/eventos/{e}/obsequios` | Todos los registros, valores y totales por persona |
| `GET /admin/eventos/{e}/obsequios/export` | Exportar CSV/Excel |
| `GET /admin/usuarios` | CRUD de usuarios |
| `GET /admin/auditoria` | Sorteos, reinicios, revelaciones |

**Extra útil para el día del evento:** una pantalla "modo revelación" que muestre las parejas una por una en pantalla grande, con un botón para pasar a la siguiente.

---

## 12. Plan por fases y estimación

| Fase | Contenido | Días-persona |
|---|---|---|
| 0. Base | Auth usuario/clave (login, sesión, throttle, clave temporal), roles, middleware, **MySQL** (BD, usuario dedicado, charset, `.env`), layout Bootstrap/jQuery por CDN, menú, zona horaria | 1.5 |
| 1. Eventos y participantes | CRUD, estados, fechas, inscripción/confirmación | 1.5 – 2 |
| 2. Sorteo y privacidad | Módulo de sorteo manual, cálculo del reparto al primer ingreso, idempotencia, Policies, pruebas de anonimato | 1.5 – 2 |
| 3. Obsequios | Regalo único + consumibles, ventana de fechas, valores, totales | 1.5 – 2 |
| 4. Administración | Parejas, reportes, export, auditoría | 1 – 1.5 |
| 5. Revelación final y pulido | Pantalla de revelación, UX, mensajes, pruebas integrales | 1 |
| 6. Despliegue | VPS si se elige, SSL, backups, primera migración | 0.5 – 1 |
| 7. Notificaciones | Avisos en pantalla, correo, WhatsApp `wa.me` | 1 – 2 |
| **Total MVP** | | **11 – 15 días** |

Sin apuros y con pruebas serias: **2 a 3 semanas** de una persona. No hay dependencias de terceros que puedan fallar ni licencias que pagar.

**OAuth no está en ese total.** Se suma cuando exista HTTPS y el proveedor elegido: **+0,5 a 1 día por proveedor**, sin tocar el resto del sistema (§10.4).

---

## 13. Riesgos y mitigaciones

| # | Riesgo | Impacto | Mitigación |
|---|---|---|---|
| 1 | Sorteo individual → callejón sin salida | Alto | Calcular el reparto completo en el primer ingreso (opción A), o *look-ahead* si se elige la B; ver §3 |
| 2 | Fuga de anonimato por autorización (IDOR) | **Alto** | Policies + pruebas por cada participante + Resources que limiten campos |
| 3 | Doble registro del regalo único | Medio | `UNIQUE` en base + idempotencia |
| 4 | Fechas y zonas horarias mal comparadas | Medio | `America/Caracas` en app + sesión MySQL `-04:00`, fechas de dominio en `DATETIME` |
| 5 | Sin internet en el evento → interfaz sin estilos | Medio | Copiar Bootstrap/jQuery a `public/vendor` |
| 6 | `APP_DEBUG=true` en producción filtra datos | Alto | `APP_DEBUG=false`, `.env` fuera de git |
| 7 | Cambios de nómina después del sorteo | Medio | Congelar nómina; reinicio auditado (RN-9) |
| 8 | El administrador participa en el sorteo | Medio | Decisión de producto: si participa, sabe todo igual; solo ocultar su rol |
| 9 | Solo 2 o 3 participantes | Bajo | Advertir; mínimo 4 recomendado |
| 10 | Datos personales y valores de dinero | Medio | Habeas Data (Ley 1581 de 2012): consentimiento, acceso restringido, backups, política de retención |
| 11 | Una notificación filtra el par o el regalo | **Alto** | Solo avisos propios; el de "alguien te registró algo" no existe (§15.4) |
| 12 | Pérdida de datos en el día del evento | Alto | Backup previo a la revelación; exportar reportes antes |
| 13 | Fuerza bruta o claves débiles | Alto | Throttle 5/min, mínimo 10 caracteres, cambio obligatorio de la temporal |
| 14 | Enlace OAuth indebido (robo de cuenta) | Alto | Vincular solo con prueba de posesión (clave actual o correo verificado) |
| 15 | Sin HTTPS en producción | Alto | Certificado, cookies `secure`; la clave viaja cifrada |
| 16 | Usuario desactivado con sesión abierta | Bajo | El middleware verifica `is_active` en cada petición |
| 17 | Borrar usuarios rompe el historial del evento | Medio | Desactivar, no borrar |
| 18 | Colación case-insensitive duplica usuarios (`Juan`/`juan`) | Medio | Normalizar `username` y `email` a minúsculas al guardar |
| 19 | Deadlock al insertar el reparto completo | Bajo | Transacción con reintentos + `FOR UPDATE` sobre el evento |
| 20 | `root` sin clave en XAMPP | **Alto** | Usuario dedicado con clave fuerte; no exponer el 3306 |
| 21 | MariaDB 10.4 fuera de soporte en producción | Medio | MySQL 8 o MariaDB LTS en producción |
| 22 | El MySQL del servidor es más viejo que el de desarrollo (5.7 vs 8.0) | Medio | Pre-flight de versión, esquema portable y no depender de `CHECK` |
| 23 | Un identificador que parece correo pero es usuario (o al revés) | Bajo | Regla del `@`: con `@` es correo, sin `@` es usuario |
| 24 | Un correo mal escrito enlaza una cuenta ajena por Google | **Alto** | Enlazar solo con correo verificado o exigir la clave actual |
| 25 | Hosting gratuito con PHP < 8.2 | **Alto** | Filtrar por PHP 8.2 antes de contratar |
| 26 | `.env` accesible si la raíz web es la carpeta del proyecto | **Alto** | Raíz en `public/` o aplicación fuera de `public_html` |
| 27 | Sin HTTPS en el hosting | **Alto** | Exigir SSL gratis; sin HTTPS no se lanza |
| 28 | El host duerme o suspende el sitio el día del evento | Alto | Probar días antes; exportar reportes y respaldo |
| 29 | Sin SSH ni Composer en el host | Medio | Subir `vendor/` compilado y migrar desde local |
| 30 | El crédito de AWS/GCP vence antes del evento | **Alto** | Calcular la fecha del evento; snapshot y plan B de migración |
| 31 | Al cerrarse la prueba, los datos se borran (GCP: 30 días de gracia) | Alto | Respaldo descargado fuera de la nube, no solo snapshot |
| 32 | VM de 1 GB sin swap: MySQL tumba la app | Medio | Swap de 1–2 GB o instancia de 2 GB |
| 33 | Dominio no cubierto por el crédito; Let's Encrypt no emite para una IP | Medio | Dominio propio o `sslip.io`/`nip.io` |
| 34 | El hostname de EC2 cambia al reiniciar sin Elastic IP | **Alto** | Asociar Elastic IP |
| 35 | Google OAuth rechaza el hostname de EC2 | **Alto** | Dominio real antes de la fase OAuth |
| 36 | Escaneo automatizado apenas se publica la URL | Medio | `APP_DEBUG=false`, `.env` fuera de la raíz, firewall |
| 37 | El correo cae en spam sin dominio ni SPF/DKIM | Medio | Proveedor SMTP con dominio verificado |
| 38 | AWS bloquea el puerto 25: no hay correo directo desde el EC2 | Medio | SMTP externo en el puerto 587 |
| 39 | Automatizar WhatsApp con librerías no oficiales | **Alto** | Usar `wa.me` o la Cloud API; nunca WhatsApp Web automatizado |

---

## 14. Decisiones

### Ya tomadas

| # | Tema | Respuesta |
|---|---|---|
| 1 | Modalidad del sorteo | **Individual y manual**: módulo que el admin habilita; cada uno saca una vez; sin cron ni automático |
| 2 | Participación del administrador | **No participa**; solo controla el sistema |
| 3 | Exclusiones e invitados | **Sin exclusiones** y **sin invitados**: solo participantes con cuenta |
| 4 | Consumibles | **Sin límite** de cantidad hasta el día del evento; el admin puede fijar un **tope de valor** |
| 5 | Valor del consumible | **Opcional**; sin tope de gasto total por ahora |
| 6 | Editar el regalo de intercambio | **Sí**, hasta el cierre |
| 7 | Qué se revela al final | **El nombre y el regalo de intercambio**; los consumibles no |
| 8 | Eventos | **Uno a la vez, uno por año**; sin simultáneos |
| 10 | Notificaciones | **Cambió**: pantalla siempre, correo y WhatsApp opcionales; ver §15 |
| 11 | Hosting | **EC2 en us-east-2** ya funcionando (`ec2-3-142-153-156...`); dominio real cuando se haga OAuth (§9.1–9.3) |
| 12 | Identificador de login | Un solo campo acepta **usuario o correo** |
| 13 | Entrega de claves | El **administrador** las crea; el usuario las cambia |
| 14 | Política de clave | Mínimo **10 caracteres**, con mayúscula y minúscula |
| 15 | OAuth | **Solo Google**, que valida el correo |
| 16 | Motor en desarrollo | **MySQL**, el mismo del servidor |
| 17 | Motor en producción | El **MySQL ya instalado en el servidor** |
| 18 | Registro de usuarios | Solo el **administrador**; Google y el login **no crean cuentas**, solo entran |

### Pendiente

- **Opción A o B de §3** (cuándo se calcula el reparto). A es la recomendada.
- **Canales de notificación** (§15.6): pantalla y correo ya; WhatsApp por `wa.me` o Cloud API; Web Push sí o no.

---

## 15. Notificaciones

Requisito nuevo del cliente: avisos por **pantalla**, **correo** y/o **WhatsApp**. Es factible, pero los tres canales tienen costos y riesgos muy distintos, y uno de ellos puede romper el anonimato si se hace sin cuidado.

### 15.1 Qué significa "push en pantalla"

Hay dos cosas muy diferentes y conviene no confundirlas:

| | Aviso dentro de la app | Web Push real |
|---|---|---|
| Qué es | Un *toast* o campana mientras la persona usa el sitio | Notificación del navegador aunque la pestaña esté cerrada |
| Infraestructura | Ninguna: base de datos + jQuery + Bootstrap | Service worker, claves VAPID, tabla de suscripciones |
| Costo | ~0,5 día | ~1,5–2 días |
| Límites | Solo si está en la página | iOS: solo con la web instalada como app (16.4+); permiso explícito |

Para un evento de 30 personas, **el aviso dentro de la app suele bastar**; el Web Push real agrega piezas móviles (service worker, suscripciones que caducan) sin cambiar el resultado práctico.

### 15.2 Correo

- Necesita un proveedor SMTP. **No se puede enviar desde el EC2 directo**: AWS bloquea el puerto 25 por defecto en cuentas nuevas.
- Con SMTP en el puerto 587 sí funciona: Brevo, Mailjet o Resend tienen capa gratuita (cientos de correos al día).
- **Sin dominio propio** se envía desde una dirección verificada del proveedor; la entregabilidad mejora mucho con dominio y registros SPF/DKIM. Es el mismo dominio que hace falta para Google OAuth (§9.3), así que ambos requisitos se resuelven juntos.
- Laravel `Notification` + Mail. A este volumen, `QUEUE_CONNECTION=sync` alcanza: sin worker y sin cola.

### 15.3 WhatsApp: el canal caro

| Camino | Qué implica | Costo |
|---|---|---|
| **Enlace `wa.me`** | Se genera `https://wa.me/57...?text=...` y el mensaje queda escrito; una persona lo envía | **Gratis**, ~0,2 día |
| **WhatsApp Cloud API (Meta)** | Cuenta de empresa, número verificado, plantillas aprobadas, webhook HTTPS público, pago por conversación | 1,5–3 días + costo por mensaje |
| Automatización no oficial | Librerías que manejan WhatsApp Web | **No hacerlo**: viola los términos y banean el número |

Para una dinámica de 30 personas, **`wa.me` es la opción sensata**: cero costo, cero aprobaciones, y el mensaje sale de una persona, que para este público inspira más confianza que un bot.

### 15.4 La regla que no se puede romper: el anonimato

Cada notificación es una oportunidad de filtrar quién le regala a quién:

- Al participante solo se le avisa **de lo suyo**: "ya puedes sacar tu amigo secreto", "te falta registrar tu regalo", "el evento cerró". Nunca "X te registró un obsequio".
- La notificación de "alguien registró un obsequio para ti" **no existe**: rompería el diseño completo.
- Al administrador sí se le puede avisar de todo, porque ya lo ve en pantalla.
- Los enlaces del correo no deben llevar IDs adivinables ni el nombre del amigo secreto en la URL.

### 15.5 Cuándo se dispara (y el detalle del cron)

- **Acciones en vivo** (alguien saca, se registra un obsequio, se revela): se disparan desde la acción, sin cron.
- **Recordatorios por fecha** ("faltan 3 días"): necesitan `schedule`/cron. Si el hosting no lo garantiza, la alternativa es avisar cuando la persona entra al sitio, o que el administrador lo dispare a mano.
- El sorteo **sigue siendo manual**: el cron solo tocaría recordatorios opcionales.

### 15.6 Costo y recomendación

| Canal | Días | Riesgo |
|---|---|---|
| En pantalla (toasts + campana) | 0,5–1 | Bajo |
| Correo (proveedor + plantillas) | 0,5–1 | Medio: entregabilidad y spam |
| WhatsApp `wa.me` | 0,2 | Bajo |
| Web Push real | 1,5–2 | Medio: service worker, iOS |
| WhatsApp Cloud API | 1,5–3 | Alto: verificación, plantillas, costo |

**Recomendación por fases:** pantalla + correo ahora (1–2 días); WhatsApp por `wa.me` ya mismo si lo quieren; Web Push y Cloud API solo si el evento crece o se repite cada año.

---

## 16. Recomendación

1. **Elegir la opción A o B de §3** y los canales de notificación de §15.6.
2. **Congelar el alcance del MVP**: auth usuario-o-correo + eventos + reparto + obsequios + revelación + avisos en pantalla. Correo y WhatsApp entran después; sin Google todavía, sin fotos, sin chat.
3. **Empezar por las pruebas del sorteo y del anonimato**, no por la interfaz. Son las dos cosas que, si fallan, hunden el proyecto; la interfaz es lo más fácil de rehacer.
4. **Orden de construcción**: fase 0 → 1 → 2 → 3 → 4 → 5, con el sorteo en un servicio aislado y testeado. Notificaciones (fase 7) y Google van al final; el diseño ya deja el hueco.
5. **Fijar el motor desde el día uno**: el MySQL del servidor también en desarrollo, `utf8mb4_unicode_ci` explícito y la suite contra MySQL en una base de pruebas aparte.

Conclusión: el sistema que describes es **viable, razonablemente pequeño y de riesgo bajo**, siempre que el reparto se genere completo de una vez y el anonimato se trate como un requisito de seguridad con pruebas automáticas, no como un detalle de la interfaz.
