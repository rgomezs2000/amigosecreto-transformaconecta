# Restablecer la dinámica del amigo secreto

Módulo de administración que deja el sistema en cero para empezar un evento
nuevo. Es un proceso **distinto** al de «Reiniciar» de Eventos:

| Proceso | Alcance |
| --- | --- |
| `evento:reiniciar {year}` | Borra el evento de un año y su dinámica. Conserva usuarios y chats generales. |
| `dinamica:reiniciar` | Borra todo lo ocurrido en el sistema. Conserva los CRUD, los módulos, los roles, la matriz de accesos y al superadministrador. |

## Acceso

El módulo **no** está disponible para cualquier administrador. Solo lo abre el
superadministrador, que es la cuenta intocable del sistema:

- se identifica por `ADMIN_USERNAME` (por defecto `admin`);
- si ese usuario no existe, se toma el administrador más antiguo;
- el acceso se concede **por usuario** (`user_permission_module`), nunca por
  perfil, así que el perfil Administrador no lo hereda;
- el middleware `superadmin` y el controlador lo vuelven a comprobar en cada
  petición.

El superadministrador tampoco se puede eliminar, bloquear ni cambiar de rol
desde el CRUD de Usuarios.

Ruta: `/administracion/restablecer-dinamica` (módulo «Restablecer dinámica»,
categoría Administración).

## Qué se elimina

Eventos y participaciones; listas de deseos, pistas, asignaciones, regalos de
intercambio y consumibles; conversaciones, mensajes, adjuntos (registros y
archivos de `storage/app/public/chat`), reacciones; moderación, reportes,
bloqueos, invitaciones, suspensiones y vetados de grupo; notificaciones,
preferencias por usuario, correos registrados, auditoría; y las cuentas de
todos los usuarios menos el superadministrador (con sus sesiones y tokens).

## Qué se conserva

- CRUD: perfiles (roles), permisos, categorías, módulos y la matriz de accesos.
- Configuración global de notificaciones.
- La cuenta del superadministrador y sus permisos individuales.
- Las tasas de cambio registradas (`exchange_rates`), que son un caché externo.

## Cómo se ejecuta

Desde el panel: el botón **Restablecer Amigo Secreto** abre el modal de
confirmación; al aceptar, el servidor lanza el comando y el mismo modal muestra
la barra de progreso y la bitácora hasta que termina o falla.

Desde consola:

```bash
php artisan dinamica:reiniciar            # pide confirmación
php artisan dinamica:reiniciar --force    # sin preguntar
```

## Progreso y bitácora

- Estado de la ejecución: `storage/app/dinamica/<run>.json` (paso, total,
  porcentaje y últimas líneas). El panel lo lee para mover la barra; si el
  archivo no se mueve, avisa que el proceso no arrancó.
- Bitácora permanente del proceso en su propio canal de Laravel:
  `storage/logs/dinamica.log`.

El borrado va en **una sola transacción**: si algo falla, no se elimina nada y
el modal lo informa. Los archivos de los adjuntos se borran después de
confirmar la transacción.

## Configuración

`config/dinamica.php` (todas con variable de entorno):

| Clave | Env | Por defecto | Para qué |
| --- | --- | --- | --- |
| `async` | `DINAMICA_ASYNC` | `true` | Ejecuta el comando en un proceso aparte. En `false` corre en la misma petición. |
| `php_binary` | `DINAMICA_PHP_BINARY` | detectado | Ejecutable de PHP para el proceso aparte. |
| `path` | `DINAMICA_PATH` | `storage/app/dinamica` | Carpeta de estados de ejecución. |
| `log` | `DINAMICA_LOG` | `dinamica` | Canal de Laravel de la bitácora. |
| `stale_after` | `DINAMICA_STALE_AFTER` | `5` | Minutos para considerar colgada una ejecución en curso. |
| `keep_days` | `DINAMICA_KEEP_DAYS` | `7` | Días que se guardan los estados anteriores. |

Si el servidor no permite abrir procesos, el sistema ejecuta el comando en la
misma petición y el modal muestra el resultado al terminar.

## Límites conocidos

- Se ejecuta una sola vez a la vez: mientras haya una ejecución viva, un nuevo
  inicio responde 409.
- El espejo de Firestore no se vacía; solo se borra lo que está en la base de
  datos. Si el chat usa Firestore, quedan documentos huérfanos de mensajes que
  ya no existen.
