# CI/CD en Windows Server 2025: push a `main` → pruebas → producción

**Repositorio:** `rgomezs2000/amigosecreto-transformaconecta` (público)
**Rama que despliega:** `main`
**Servidor:** EC2 `ec2-3-142-153-156.us-east-2.compute.amazonaws.com`, **Windows Server 2025**

---

## 1. Cómo funciona

```
 push a main
      │
      ▼
 ┌──────────────┐   falla   ┌──────────────────┐
 │  Pruebas     │──────────▶│ No se despliega  │
 │ (Linux + MySQL)          └──────────────────┘
 └──────────────┘
      │ ok
      ▼
 ┌────────────────────────────────────────────────────────┐
 │ Runner Windows de GitHub → SSH con Posh-SSH            │
 │  1. Conexión y validaciones de la ruta                 │
 │  2. Pull: fetch + checkout + reset --hard + clean      │
 │  3. composer install                                   │
 │  4. php artisan optimize:clear                         │
 │  5. config:cache + view:cache (sin route:cache)        │
 │  6. Reinicio de Apache (si está como servicio)         │
 │  7. Desconexión (finally)                              │
 └────────────────────────────────────────────────────────┘
```

Las **migraciones y los seeders no** forman parte del despliegue: se ejecutan a mano con el workflow **Migraciones** (ver §5.1).

El job de pruebas corre en **Ubuntu** (es independiente del sistema del servidor) y usa MySQL 8 como servicio. El despliegue corre en un runner **Windows** y se conecta al servidor con **Posh-SSH** usando usuario y contraseña.

Archivos:

- [.github/workflows/deploy.yml](../.github/workflows/deploy.yml) — pruebas y despliegue completo
- [.htaccess](../.htaccess) — bloquea todo lo que no sea `public/` si sirves desde `htdocs`
- [public/web.config](../public/web.config) — reglas de IIS (Apache lo ignora)

---

## 2. Requisitos en el servidor

| Componente | Detalle |
|---|---|
| **OpenSSH Server** | Es una característica opcional de Windows; hay que instalarla |
| **Git for Windows** | Debe quedar en el PATH del sistema (no solo del usuario) |
| **PHP 8.2+** | XAMPP (`C:\xampp\php\php.exe`) o PHP para IIS con FastCGI |
| **Composer** | `composer` en el PATH, o `composer.phar` |
| **Servidor web** | IIS con el módulo **URL Rewrite**, o Apache de XAMPP |
| **Runner de despliegue** | `windows-2025` de GitHub; el job instala **Posh-SSH** |
| **Usuario SSH** | Con acceso al proyecto y, si quieres reiniciar IIS/Apache, permisos de administrador |

---

## 3. OpenSSH y autenticación

OpenSSH ya está instalado en el servidor. Como te conectas con **contraseña**, el pipeline usará la misma.

| Dónde | Nombre | Valor |
|---|---|---|
| Variable | `SSH_USER` | `Administrator` |
| Secreto | `SSH_PASSWORD` | La contraseña de ese usuario |

El despliegue usa **Posh-SSH** desde un runner de Windows: abre la sesión con usuario y contraseña, ejecuta el script remoto y la cierra en `finally`.

### Requisito en el servidor

Windows OpenSSH debe permitir contraseñas. Revisa `C:\ProgramData\ssh\sshd_config`:

```
PasswordAuthentication yes
```

Si tuviste que cambiarlo, reinicia el servicio:

```powershell
Restart-Service sshd
```

### Advertencia de seguridad

La contraseña viaja cifrada dentro del túnel SSH, pero la autenticación por contraseña sigue siendo el eslabón más débil de un servidor con el puerto 22 abierto:

- Usa una contraseña larga y única. Si es la de `Administrator`, un intento de fuerza bruta exitoso te da el servidor completo.
- Limita el puerto 22 a las IPs necesarias o instala un bloqueo por intentos fallidos.
- Si más adelante quieres pasar a llave, no cambia nada del resto: generas el par, pones la pública en `C:\ProgramData\ssh\administrators_authorized_keys` (con su ACL) y cambias `password:` por `key:` en el workflow.

Mientras uses contraseña, va **siempre** en secretos, nunca en variables.

---

## 4. Variables y secretos en GitHub

Settings → Secrets and variables → Actions.

**Variables**

| Nombre | Valor de ejemplo |
|---|---|
| `SSH_HOST` | `ec2-3-142-153-156.us-east-2.compute.amazonaws.com` |
| `SSH_PORT` | `22` (opcional; si no existe, se usa 22) |
| `SSH_USER` | `Administrator` (o el usuario que uses) |
| `DEPLOY_PATH` | `C:\xampp\htdocs\amigosecreto-transformaconecta` |

**Secretos**

| Nombre | Valor |
|---|---|
| `SSH_PASSWORD` | La contraseña del usuario `Administrator` |
| `SSH_FINGERPRINT` | `SHA256:...` del host (recomendado) |

Huella del host:

```bash
ssh-keyscan -t ed25519 ec2-3-142-153-156.us-east-2.compute.amazonaws.com | ssh-keygen -lf -
```

---

## 5. Preparación del proyecto (una vez)

```powershell
# Si la carpeta ya existe (el servidor ya está funcionando), salta el clone.
cd C:\xampp\htdocs
git clone https://github.com/rgomezs2000/amigosecreto-transformaconecta.git
cd C:\xampp\htdocs\amigosecreto-transformaconecta

# Si ya tienes .env, NO lo sobrescribas: solo revisa los valores.
Copy-Item .env.example .env
notepad .env   # APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, GOOGLE_*

composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
```

> **El `.env` no viene en el repositorio.** Si la carpeta ya existía y saltaste el clone, verifica que `.env` exista y apunte a MySQL. Sin eso, Laravel usa SQLite por defecto y el despliegue falla al limpiar la caché con `database.sqlite does not exist`.
>
> ```powershell
> Test-Path .env
> Get-Content .env | Select-String 'APP_KEY|DB_CONNECTION|DB_DATABASE'
> ```

Valores mínimos del `.env` de producción:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://ec2-3-142-153-156.us-east-2.compute.amazonaws.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=amigosecreto_transformaconecta
DB_USERNAME=amigosecreto
DB_PASSWORD=...
```

> Si sirves la app desde `.../public` sin VirtualHost, `APP_URL` debe incluir el subdirectorio:
> `http://ec2-.../amigosecreto-transformaconecta/public`.

### IIS (referencia; hoy el servidor usa Apache)

1. Instala el módulo **URL Rewrite**.
2. Crea el sitio con raíz física en `C:\inetpub\wwwroot\amigosecreto\public` (**solo `public`**).
3. Da permiso de escritura a `IIS_IUSRS` en `storage` y `bootstrap\cache` (el script también lo intenta).
4. Para HTTPS **no uses certbot** (es de Linux): en Windows se usa **win-acme** (`wacs.exe`) o **Certify The Web**, que integran Let's Encrypt con IIS.

### XAMPP / Apache

**Recomendado: un VirtualHost con la raíz en `public\`.** Así el `.env` y `vendor\` quedan fuera de la web y las rutas son limpias (`/login` en vez de `/amigosecreto-transformaconecta/public/login`).

En `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName ec2-3-142-153-156.us-east-2.compute.amazonaws.com
    DocumentRoot "C:/xampp/htdocs/amigosecreto-transformaconecta/public"

    <Directory "C:/xampp/htdocs/amigosecreto-transformaconecta/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Reinicia Apache y comprueba `http://ec2-.../login`.

**Si prefieres no tocar el VirtualHost**, la app queda en `http://ec2-.../amigosecreto-transformaconecta/public/login`, pero **todo el proyecto es accesible por HTTP**, incluido el `.env`. El `.htaccess` de la raíz que agregué bloquea todo excepto `public/`; comprueba que `AllowOverride All` esté activo para `htdocs`.

> **Nunca** dejes la raíz del sitio en la carpeta del proyecto. `public\web.config` protege el `.env` en IIS; en Apache la protección es el VirtualHost o el `.htaccess` de la raíz.

Si Apache está instalado como servicio (XAMPP Control Panel → Apache → Service), el deploy lo reinicia solo. Si lo arrancas a mano desde el panel, el script avisa que no pudo reiniciarlo, pero el código igual se actualiza.

### 5.1 Migraciones y seeders (manual)

El despliegue automático **no toca la base de datos**. Para aplicarlas:

**Actions → Migraciones → Run workflow**

- `php artisan migrate --force` ejecuta **solo las migraciones pendientes** (las que no están en la tabla `migrations`). No repite las aplicadas y no borra datos.
- El flag `--force` **no significa "volver a ejecutar"**: en producción Laravel pide confirmación y, al no haber terminal interactiva, `--force` la omite. Es obligatorio para que corra desde el pipeline.
- Los seeders solo se ejecutan si marcas la casilla `seed`. `AdminUserSeeder` usa `updateOrCreate`, así que repetirlo no duplica al administrador.
- Nada de `migrate:fresh` ni `migrate:refresh` en producción: eso sí borra las tablas.

**Orden recomendado:** si un push incluye una migración, ejecuta **Migraciones** inmediatamente después del despliegue. Conviene que las migraciones sean compatibles hacia atrás (columnas nuevas nullable o con default) para que la app no falle durante ese intervalo.

---

## 6. El punto que más va a fallar

**El security group.** GitHub Actions corre desde IPs dinámicas, así que el puerto 22 debe aceptarlas. Si hoy solo está abierto para tu IP, el deploy va a dar *timeout*.

Opciones:

- Abrir 22 con autenticación **solo por llave** y `fail2ban` (o el equivalente en Windows).
- Usar **AWS Systems Manager** en vez de SSH, sin abrir puertos.

También revisa:

| Síntoma | Causa |
|---|---|
| `Permission denied` | Contraseña incorrecta, o el usuario no tiene permitido SSH |
| `git: command not found` | Git no está en el PATH del sistema |
| `php: no se reconoce` | PHP no está en el PATH; el script busca `C:\xampp\php\php.exe` |
| `composer: no se reconoce` | Composer no está en el PATH ni en las rutas habituales |
| `No se pudo reiniciar el servidor web` | Apache no está instalado como servicio `Apache2.4` (el deploy igual termina) |
| `Database file at path ...database.sqlite does not exist` | Falta `.env` o tiene `DB_CONNECTION=sqlite`: el servidor está usando SQLite por defecto |
| `405 The GET method is not supported for route /. Supported methods: HEAD` | Caché de rutas vieja o generada con `route:cache` en Apache/Windows. Ejecuta `php artisan route:clear` |
| `Falta .env en ...` | El servidor no tiene `.env` (no está en el repositorio) |
| `DB_CONNECTION debe ser mysql o mariadb` | El `.env` del servidor apunta a SQLite |
| `Se requiere PHP 8.2` | XAMPP con PHP viejo: Laravel 12 no arranca con menos |
| `dubious ownership` | `git config --global --add safe.directory C:\...` |

---

## 7. Primer despliegue

1. Completa los pasos 3, 4 y 5.
2. Push del workflow:

```bash
git add .github docs/despliegue-ci-cd.md public/web.config .htaccess
git commit -m "ci: despliegue automático a producción en Windows Server"
git push origin main
```

3. Revisa **Actions**. Deben pasar `Pruebas` y `Desplegar a producción`.

---

## 8. Rollback

```powershell
cd C:\xampp\htdocs\amigosecreto-transformaconecta
git log --oneline -5
git reset --hard <commit_anterior>

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Esto revierte el código, **no la base de datos**. Las migraciones aplicadas se quedan; por eso conviene que sean aditivas y tener un respaldo antes de cambios de esquema.

---

## 9. Riesgos de desplegar en cada push

| Riesgo | Mitigación |
|---|---|
| No hay staging: un commit malo llega a producción | Trabajar en ramas y fusionar a `main` cuando esté probado |
| Las migraciones corren automáticamente | **No**: hay que lanzar el workflow **Migraciones** después de cada push con cambios de esquema |
| Un push durante el evento cambia el sistema | Congelar `main` esos días o usar `workflow_dispatch` |
| El servidor cae a mitad del despliegue | El siguiente push lo recupera; tener respaldo |
| IPs de GitHub dinámicas | Abrir 22 solo por llave, o pasar a SSM |

Si quieres una barrera sin tocar el workflow: **Settings → Environments → `production` → Required reviewers**.

---

## 10. Notas

- **No hay build de frontend**: Bootstrap, jQuery, Font Awesome, Bootbox y DataTables van por CDN; no se ejecuta `npm`.
- **El pipeline no toca el `.env`**: `git reset --hard` no afecta archivos ignorados.
- El repositorio es público, así que el servidor clona por HTTPS sin credenciales.
- Los scripts corren con `-ExecutionPolicy Bypass`; no hay que cambiar la política de la máquina.
- **`route:cache` está desactivado** en el pipeline: en Apache/Windows serializa la ruta `/` con solo `HEAD` y devuelve 405. Sin esa caché la app funciona igual; el impacto en rendimiento es mínimo.
- La base de pruebas vive solo en el runner de GitHub; nunca en producción.
