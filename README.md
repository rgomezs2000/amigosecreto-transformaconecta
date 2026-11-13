<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## Despliegue (CI/CD)

Cada push a `main` ejecuta [deploy.yml](.github/workflows/deploy.yml): primero las
pruebas en Ubuntu y, si pasan, el despliegue por SSH al servidor Windows
(XAMPP + Apache). Las migraciones son manuales
([migrate.yml](.github/workflows/migrate.yml)).

| Archivo | Dónde corre | Qué hace |
| --- | --- | --- |
| [Invoke-RemoteDeploy.ps1](.github/scripts/Invoke-RemoteDeploy.ps1) | runner | Carga, valida, comprime y envía el script; revisa el código de salida |
| [deploy-remote.ps1](.github/scripts/deploy-remote.ps1) | servidor | Pull, Composer, cachés, `storage:link` y reinicio de Apache |
| [migrate-remote.ps1](.github/scripts/migrate-remote.ps1) | servidor | `migrate --force` y `db:seed --force` |
| [enable-php-extension.ps1](.github/scripts/enable-php-extension.ps1) | servidor | Activa una extensión en el `php.ini` del servidor (manual) |

### Variables del repositorio

| Variable | Obligatoria | Uso |
| --- | --- | --- |
| `SSH_HOST` | sí | Host del servidor |
| `SSH_PORT` | no | Puerto SSH (por defecto `22`) |
| `SSH_USER` | sí | Usuario SSH |
| `DEPLOY_PATH` | sí | Ruta del proyecto en el servidor |
| `PHP_BIN` | no | Ruta completa de `php.exe` si no está en el PATH de la sesión SSH |
| `COMPOSER_BIN` | no | Ruta completa de `composer.bat`/`composer.phar` si no está en el PATH |

`SSH_PASSWORD` va como *secret*.

### Diagnóstico

El log muestra la versión de PHP, las extensiones cargadas y la versión de
Composer antes de tocar nada. Cada comando que falla se registra con su ruta, sus
argumentos, su salida y su código de salida, seguido de una línea `ERROR: ...` con
la causa. El `stderr` del servidor se fusiona en el log, así que un fallo de PHP o
de Composer ya no aparece como un `ExitStatus 1` mudo.

Si el servidor tiene varios PHP, define `PHP_BIN` para fijar el que usan Composer
y `php artisan`; el workflow avisa cuando la versión detectada no cumple el
`^8.2` que exige [composer.json](composer.json).

### Cuando el despliegue se agota por tiempo

Posh-SSH solo devuelve la salida **cuando el comando termina**, así que un
`CommandTimeout` dejaba el log completamente ciego. Por eso el script remoto:

- escribe una **traza en el servidor** (`C:\Windows\Temp\dsh-deploy.log`, o la
  ruta de la variable de entorno `REMOTE_LOG`) con `Start-Transcript`;
- marca cada paso con el tiempo transcurrido (`==> [2/6] Composer [+3,2s]`) y
  cada comando con su duración y código de salida
  (`composer install: 42,7s, código de salida 0`).

Si el comando remoto falla o se agota, el runner abre una segunda conexión y
muestra las últimas 100 líneas de esa traza, así que siempre se ve en qué paso se
quedó. El límite actual es de 3600 s (el job de GitHub, 75 min).

La primera instalación completa de Composer en el servidor es lenta: baja unos
100 paquetes, entre ellos `google/apiclient-services` y `grpc/grpc`, que son
grandes. Si se repite a menudo, conviene ejecutar `composer install` una vez a
mano en el servidor o configurar un token de GitHub para Composer
(`composer config --global github-oauth.github.com <token>`), que evita los
límites de descarga anónima y acelera mucho las instalaciones.

### Dos límites del servidor que condicionan estos scripts

- **Línea de comandos de `cmd.exe`: 8191 caracteres.** El shell por defecto de
  OpenSSH en Windows es `cmd.exe`, así que el script remoto no puede viajar en
  crudo (`-EncodedCommand` de un script de 10 KB ocupa ~25 000 caracteres y falla
  con `The command line is too long.`). Por eso se envía comprimido con GZip y se
  descomprime en memoria en el servidor.
- **Windows PowerShell 5.1 destroza las comillas dobles** al pasarlas a un
  ejecutable nativo. En `deploy-remote.ps1` no uses `php -r "echo ..."` con
  comillas dobles dentro: usa `php -v` / `php -m` o construye el código PHP sin
  comillas, o PHP recibirá código roto.

### Extensiones de PHP que faltan

`composer install` se detiene si el servidor no tiene cargada alguna extensión
que exija `composer.lock`. El caso típico en este proyecto:

```
lcobucci/jwt requires ext-sodium * -> it is missing from your system
```

El despliegue lo comprueba *antes* de llamar a Composer y nombra las que faltan.
Para resolverlo hay dos caminos.

**A mano (1 minuto, en el servidor por RDP):** abrir `C:\xampp\php\php.ini`,
buscar `;extension=sodium`, quitarle el `;` (o añadir `extension=sodium` al
final), guardar y reiniciar Apache. Comprobar con `php -m | findstr sodium`.

**Desde Actions:** *Actions → Extensiones PHP → Run workflow*, con `sodium` como
entrada. El workflow busca el `php.ini` que PHP carga de verdad, guarda una copia
(`php.ini.bak-<fecha>`), añade la línea, comprueba con `php -m` que la extensión
carga y reinicia Apache. Si la comprobación falla, restaura el original y explica
el motivo. Es idempotente: si ya está activa, no toca nada.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
