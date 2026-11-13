# Entorno local: `.env`, caché de configuración y el error de la clave

## El error

```
No application encryption key has been specified
```

Aparece cuando una petición arranca **sin leer el `.env`**: Laravel cae a sus
valores por defecto (`APP_ENV=production`, sin `APP_KEY`) y falla lo primero que
necesita la clave (las cookies de sesión). Por eso el log dice
`production.ERROR` aunque el `.env` diga `local`.

No es que la clave esté mal ni que falte en el archivo: en este equipo el
`.env` se lee correctamente casi siempre, pero en XAMPP (Windows + Apache con
hilos) **la lectura puede fallar de forma intermitente**, sobre todo con varias
peticiones a la vez. Se comprobó con carga: 96 peticiones en paralelo → 2 fallos
con este error. También afectó a otro proyecto del mismo XAMPP
(`planificadordiario`), así que es del entorno, no de este código.

## Solución aplicada

La configuración está **cacheada** (`bootstrap/cache/config.php`). Con la caché
presente, las peticiones **no leen el `.env`** y el fallo desaparece: se midió
con 144 peticiones concurrentes → 0 errores.

Es el mismo paso que ya hace el despliegue
([deploy-remote.ps1](../.github/scripts/deploy-remote.ps1)).

### Cambiar el `.env` ahora

1. Edita `.env` y guarda.
2. Regenera la caché:

   ```powershell
   php artisan config:cache
   ```

3. Comprueba que quedó bien (debe decir `key OK`):

   ```powershell
   php -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["key"] ? "key OK" : "KEY VACIA: repite config:cache";'
   ```

Si algo sale mal, vuelve a leer el `.env` en cada petición con
`php artisan config:clear` y corrige lo que haga falta.

> La caché guarda los valores del `.env` del momento en que se generó: si cambias
> base de datos, correo o claves, hay que repetir `config:cache`.

### Las pruebas no se ven afectadas

`phpunit.xml` define su propia caché (`bootstrap/cache/config.testing.php`), así
que `php artisan test` **ignora** `bootstrap/cache/config.php` y sigue usando la
base `amigosecreto_test`. Comprobado con la caché activa: 334 pruebas en verde.

### El reinicio de Apache

Reiniciar Apache (Panel de XAMPP → Apache → Stop/Start) limpia cualquier estado
compartido que haya quedado al leer el `.env` a medias. No es obligatorio para
que el arreglo funcione, pero deja el servidor fresco. Requiere permisos de
administrador, por eso no se puede hacer desde una consola sin elevar.

## Comandos de referencia

| Comando | Para qué |
| --- | --- |
| `php artisan config:cache` | Genera la caché y deja de leer `.env` en cada petición |
| `php artisan config:clear` | Borra la caché y vuelve a leer `.env` siempre |
| `php artisan config:show app` | Muestra la configuración efectiva (sirve para comprobar la clave sin mostrarla entera) |
| `php artisan test` | Pruebas; no usan la caché de configuración |
