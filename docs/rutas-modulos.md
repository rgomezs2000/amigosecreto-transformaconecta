# Rutas de los módulos

Las páginas de los módulos **no tienen una ruta escrita a mano** en
`routes/web.php`. Su URL sale del maestro de Módulos, así que se mantiene
consistente sola:

| En el maestro de Módulos | Resultado |
| --- | --- |
| Módulo «Perfil», slug `perfil`, categoría General (sin slug) | `/perfil` |
| Módulo «Roles», slug `roles`, categoría Maestros (`maestros`) | `/maestros/roles` |
| Se cambia el slug a `mi-perfil` | La página pasa a `/mi-perfil` y `/perfil` responde **301** |
| Se crea un módulo nuevo | Su ruta existe al instante (página «en construcción») |
| Se elimina un módulo | Su ruta deja de existir (404) |
| Se cambia el slug de la categoría | Cambian todas las rutas de sus módulos (301 desde las anteriores) |

## Piezas

1. **`modules.slug`** es la parte mutable de la URL.
2. **`modules.code`** es la identidad fija del módulo. Se genera una sola vez a
   partir del slug inicial (`Module::codeFor()`) y no cambia aunque el slug o el
   nombre se renombren. Todo el código que necesita «el módulo de perfil», «el
   módulo de usuarios», etc. busca por `code` (`Module::activeByCode()`), nunca
   por slug.
3. **`Module::path()` / `Module::url()`** son la única fuente de verdad de la
   URL pública: `/[categoria]/[modulo]`, sin categoría cuando la categoría no
   tiene slug (General y Sesión). Inicio, Login y Salir conservan sus rutas de
   sistema (`/`, `/login`, `/logout`).
4. **`module_redirects`** guarda la ruta anterior cuando cambia un slug (de
   módulo o de categoría). El middleware responde 301 hacia la URL actual, de
   modo que los enlaces compartidos siguen funcionando.
5. **La ruta comodín** `GET /{path}` (última de `routes/web.php`) la atiende el
   middleware `module.access`:

   ```
   ruta → Module::findByPath() → módulo (404 si no existe, login si no hay
          sesión, 403 si no tiene acceso) → ModulePageController
   ```

6. **`App\Support\ModulePages`** relaciona el `code` de un módulo con la
   pantalla ya construida (controlador y acción). Un módulo sin entrada ahí
   sigue siendo válido: muestra la página «en construcción».

## Cómo se enlaza desde el código

Para enlazar o redirigir a la página de un módulo se usa el ayudante
`module_url()`, que resuelve el slug actual:

```php
'redirect' => module_url('perfil'),

<a href="{{ module_url('usuarios') }}">Usuarios</a>
```

Si el módulo ya no existe o está inactivo, `module_url()` devuelve la URL de
respaldo (por defecto, el panel). **No** se usan nombres de ruta del tipo
`route('usuarios.index')` para páginas de módulo: esas rutas ya no existen.

## Agregar una pantalla nueva a un módulo

1. Crear el controlador con su acción `index`.
2. Añadir la entrada en `App\Support\ModulePages::PAGES`, usando el `code` del
   módulo:

   ```php
   'mi-modulo' => ['controller' => MiModuloController::class, 'action' => 'index'],
   ```

3. Listo: la URL sigue saliendo del maestro de Módulos y no hace falta tocar
   `routes/web.php`.

Los módulos que no necesitan código propio (los que aún no se construyen) no se
registran en `ModulePages`: el maestro de Módulos basta para que tengan ruta.

## Pruebas

`tests/Feature/ModuleRoutesTest.php` fija el contrato: slug actual, cambio de
slug con 301, ruta histórica, módulo nuevo, módulo eliminado, módulo inactivo,
acceso denegado, invitado y cambio de slug de la categoría.
