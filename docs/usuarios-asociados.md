# Usuarios asociados: consulta de parejas

Módulo de **solo lectura** para la administración. Muestra, por evento, cada
usuario con el amigo secreto que le fue asignado: quién regala a quién. No abre
fichas, no muestra listas de deseos ni obsequios y no modifica nada.

## Formulario

Igual que **Participantes**, en una sola columna. Encima del formulario va la
sección con el estado del evento: **etiqueta de fase con su color**, etiqueta
del estado del evento, nombre, año, fecha de intercambio, el mensaje de la fase
y los contadores de invitados, activos, pendientes, asignados y sin asignar.

1. **Evento**: por defecto el actual y en curso; el selector lista todos los
   eventos, también los históricos.
2. **Buscar usuario**: filtra por el usuario (nombre, correo o usuario de
   acceso) o por el nombre del amigo secreto.
3. **Limpiar**: vacía la búsqueda y devuelve la consulta al evento actual.

## Tabla

DataTable paginada en el servidor, de solo lectura, con cuatro columnas:

| Columna | Contenido |
| --- | --- |
| Id | Id del usuario (quien regala). |
| Nombre | Nombre del usuario. |
| Estatus de participación | Etiqueta con el estado de su participación: Activo, Rechazado, Descartado, Expulsado, Retirado o En espera. |
| Amigo secreto | Nombre del amigo secreto asignado. |

Si el evento todavía no tiene sorteo, la tabla lo indica («Las parejas aparecen
cuando se realiza el sorteo»).

## Apertura y cierre por evento

- El módulo se activa cuando existe al menos un evento. Sin eventos, la página
  explica cómo crearlo.
- Consulta por defecto el evento actual y en curso.
- Los eventos terminados se consultan en modo histórico: la sección de estado
  indica «El evento ya finalizó. La información queda disponible en solo
  lectura» y los datos se mantienen disponibles.
- Si el evento está suspendido se avisa y la consulta también es de solo
  lectura.

## Endpoint

| Método | Ruta | Para qué |
| --- | --- | --- |
| GET | `/usuarios-asociados/datos` | DataTable de parejas del evento (`evento` como parámetro). |

Exige sesión, el módulo **Usuarios asociados** concedido y rol de
administración. La página la sirve el enrutador de módulos en
`/amigo-secreto/usuarios-asociados`.

## Piezas

- `app/Http/Controllers/UsuariosAsociadosController.php`: consulta y DataTable.
- `app/Http/Controllers/Concerns/ResuelveEventoConsulta.php`: evento actual y
  evento elegido, compartido con Participantes.
- `app/Http/Controllers/Concerns/ResuelveEstadoEvento.php`: estado, fase y
  contadores del evento para la sección superior.
- `resources/views/partials/fase-badge.blade.php`: etiqueta de fase con color.
- `app/Support/ModulePages.php`: asocia el módulo a su controlador.
- `resources/views/usuarios-asociados/`: página.
- `public/js/usuarios-asociados.js`: DataTable, búsqueda, evento y limpiar.
- `tests/Feature/UsuariosAsociadosTest.php`: acceso, eventos, búsqueda,
  paginación, estatus de participación e histórico.

## Pruebas

```bash
php artisan test --filter=UsuariosAsociadosTest
```
