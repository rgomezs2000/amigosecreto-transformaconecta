<#
  Script que se ejecuta EN EL SERVIDOR de producción (Windows + XAMPP).
  Lo invoca .github/workflows/deploy.yml a través de una sesión SSH.

  Los marcadores __DEPLOY_PATH__, __PHP_BIN__, __COMPOSER_BIN__ y __REMOTE_LOG__
  los sustituye el workflow antes de enviarlo. Se puede probar en local así:

      pwsh -NoProfile -File .github/scripts/deploy-remote.ps1

  (sin sustituir los marcadores fallará a propósito en la comprobación de ruta.)
#>

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Sin esto, al escribir en la tubería de SSH los acentos salen como "fall?".
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }

$guionInicio = Get-Date

# Marca el inicio de cada paso con el tiempo transcurrido. Si SSH corta por
# tiempo, la traza del servidor muestra en qué paso se quedó y cuánto tardaron
# los anteriores.
function Step([string]$mensaje) {
  Write-Host ''
  Write-Host ("==> {0}   [+{1:N1}s]" -f $mensaje, ((Get-Date) - $guionInicio).TotalSeconds)
}

# Ejecuta un programa, deja ver su salida y falla si el código de salida no es 0.
# La salida pasa por Write-Host a propósito: así queda en la traza del servidor,
# que es lo único recuperable cuando la conexión se agota por tiempo (Posh-SSH
# solo devuelve la salida cuando el comando termina).
function Invoke-Native([string]$exe, [string[]]$argumentos, [string]$que) {
  Write-Host "> $exe $($argumentos -join ' ')"
  $inicio = Get-Date
  # El 2>&1 es deliberado (así el stderr también queda en la traza, que es donde
  # Composer escribe sus errores), pero hay que bajar ErrorActionPreference
  # dentro del bloque: en Windows PowerShell 5.1 redirigir el stderr de un
  # ejecutable nativo genera ErrorRecords y con 'Stop' abortan el script entero.
  $eapPrevio = $ErrorActionPreference
  $codigo = 1
  try {
    $ErrorActionPreference = 'Continue'
    & $exe @argumentos 2>&1 | ForEach-Object { Write-Host $_ }
    $codigo = $LASTEXITCODE
  }
  finally {
    $ErrorActionPreference = $eapPrevio
  }
  Write-Host ("  {0}: {1:N1}s, código de salida {2}" -f $que, ((Get-Date) - $inicio).TotalSeconds, $codigo)
  if ($codigo -ne 0) { throw "$que falló con código de salida $codigo." }
}

# Busca un ejecutable en el PATH y devuelve su ruta completa (o $null).
function Find-Tool([string]$nombre) {
  $cmd = Get-Command -Name $nombre -CommandType Application -ErrorAction SilentlyContinue
  if (-not $cmd) { return $null }
  return @($cmd)[0].Source
}

# Compara las extensiones que exige composer.lock con las que PHP tiene
# cargadas. Así el fallo se explica solo, en vez de dejar que Composer reviente
# con "requires ext-sodium * -> it is missing from your system".
function Assert-PhpExtensions([string]$php, [string]$deployPath) {
  $lockFile = Join-Path $deployPath 'composer.lock'
  if (-not (Test-Path -LiteralPath $lockFile)) { return }

  $cargadas = @((& $php -m) | Where-Object { $_ -and $_ -notmatch '^\[' } | ForEach-Object { $_.Trim().ToLowerInvariant() })

  $requeridas = @()
  foreach ($paquete in (Get-Content -LiteralPath $lockFile -Raw | ConvertFrom-Json).packages) {
    if (-not $paquete.require) { continue }
    foreach ($propiedad in $paquete.require.PSObject.Properties) {
      if ($propiedad.Name -like 'ext-*') { $requeridas += $propiedad.Name.Substring(4).ToLowerInvariant() }
    }
  }
  $requeridas = @($requeridas | Sort-Object -Unique)

  $faltan = @($requeridas | Where-Object { $cargadas -notcontains $_ })
  if ($faltan.Count -eq 0) {
    Write-Host "composer.lock exige $($requeridas.Count) extensiones de PHP: todas están cargadas."
    return
  }

  throw ("Faltan extensiones de PHP que composer.lock exige: " + ($faltan -join ', ') + ". " +
    "Actívalas en el php.ini del servidor (línea 'extension=<nombre>' sin ';' al inicio), " +
    "comprueba que exista php_<nombre>.dll en la carpeta ext y reinicia Apache. " +
    "Atajo: Actions → Extensiones PHP → Run workflow indicando la extensión.")
}

try {
  # Traza en el servidor: si el comando se agota por tiempo, el runner lee este
  # archivo para poder decir en qué paso se quedó.
  $remoteLog = '__REMOTE_LOG__'
  $traza = $false
  if ($remoteLog -and $remoteLog -notlike '__*') {
    try {
      Start-Transcript -Path $remoteLog -Force | Out-Null
      $traza = $true
      Write-Host "Traza del servidor: $remoteLog"
    }
    catch {
      Write-Host "Aviso: no se pudo iniciar la traza en $remoteLog ($($_.Exception.Message))."
    }
  }

  $deployPath = '__DEPLOY_PATH__'
  $phpOverride = '__PHP_BIN__'
  $composerOverride = '__COMPOSER_BIN__'

  if (-not (Test-Path -LiteralPath $deployPath)) { throw "No existe la ruta: $deployPath" }
  Set-Location -LiteralPath $deployPath
  if (-not (Test-Path -LiteralPath '.git')) { throw "No es un repositorio Git: $deployPath" }

  Step '[0/6] Entorno del servidor'
  Write-Host "Ruta: $deployPath"
  Write-Host "PowerShell: $($PSVersionTable.PSVersion)"

  $php = if ($phpOverride) { $phpOverride } else { Find-Tool 'php' }
  $git = Find-Tool 'git'
  $composer = if ($composerOverride) { $composerOverride } else { Find-Tool 'composer' }

  if (-not $php) {
    throw "No se encontró 'php' en el PATH de la sesión SSH. Añade la carpeta de PHP (por ejemplo C:\xampp\php) al PATH del sistema y reinicia el servicio sshd, o define la variable PHP_BIN del repositorio."
  }
  if (-not $git) {
    throw "No se encontró 'git' en el PATH de la sesión SSH."
  }

  if ($composer) {
    $composerExe = $composer
    $composerArgs = @()
    Write-Host "composer: $composer"
  }
  else {
    $phar = Join-Path $deployPath 'composer.phar'
    if (-not (Test-Path -LiteralPath $phar)) {
      throw "No se encontró 'composer' en el PATH de la sesión SSH ni composer.phar en $deployPath. Instala Composer o define la variable COMPOSER_BIN del repositorio."
    }
    $composerExe = $php
    $composerArgs = @($phar)
    Write-Host "Composer no está en PATH; se usa $phar"
  }

  Write-Host "php: $php"
  Write-Host "git: $git"

  # Ojo: nada de 'php -r "...comillas dobles..."'. Windows PowerShell 5.1
  # elimina las comillas dobles al pasarlas a un ejecutable nativo y PHP recibe
  # código roto ("PHP Parse error: syntax error, unexpected token"). 'php -v' y
  # 'php -m' dan la misma información sin comillas.
  $phpInfo = ((& $php -v) -join "`n").Trim()
  Write-Host $phpInfo

  $phpVersion = 'desconocida'
  if ($phpInfo -match 'PHP (\d+)\.(\d+)\.(\d+)') {
    $phpMajor = [int]$Matches[1]
    $phpMinor = [int]$Matches[2]
    $phpVersion = '{0}.{1}.{2}' -f $Matches[1], $Matches[2], $Matches[3]
  }

  $phpExtensions = (((& $php -m) | Where-Object { $_ -and $_ -notmatch '^\[' }) -join ', ').Trim()
  Write-Host "Versión de PHP: $phpVersion"
  Write-Host "Extensiones PHP: $phpExtensions"

  if ($phpVersion -ne 'desconocida' -and ($phpMajor -lt 8 -or ($phpMajor -eq 8 -and $phpMinor -lt 2))) {
    Write-Host "ADVERTENCIA: composer.json exige PHP ^8.2 y el servidor reporta $phpVersion. Composer fallará hasta que se actualice PHP."
  }

  Invoke-Native $composerExe ($composerArgs + @('--version', '--no-ansi')) 'composer --version'

  $envFile = Join-Path $deployPath '.env'
  if (-not (Test-Path -LiteralPath $envFile)) {
    throw "Falta .env en $deployPath. Créalo a partir de .env.example antes del primer despliegue."
  }
  if (-not (Select-String -Path $envFile -Pattern '^APP_KEY=base64:' -Quiet)) {
    throw 'APP_KEY no está generada en .env. Ejecuta: php artisan key:generate'
  }
  if (-not (Select-String -Path $envFile -Pattern '^DB_CONNECTION=(mysql|mariadb)' -Quiet)) {
    throw 'DB_CONNECTION debe ser mysql o mariadb en .env; ahora apunta a sqlite o falta.'
  }

  Step '[1/6] Pull del repositorio'
  Invoke-Native $git @('fetch', 'origin', 'main', '--prune') 'git fetch'
  Invoke-Native $git @('checkout', '--force', 'main') 'git checkout'
  Invoke-Native $git @('reset', '--hard', 'origin/main') 'git reset'
  # -e composer.phar: si Composer vive como composer.phar en la raíz del
  # proyecto (no está versionado), 'git clean -fd' lo borraría y el paso de
  # Composer fallaría sin dejar rastro en el log.
  Invoke-Native $git @('clean', '-fd', '-e', 'composer.phar') 'git clean'
  Invoke-Native $git @('pull', '--ff-only', 'origin', 'main') 'git pull'
  Write-Host "Commit desplegado: $((& $git rev-parse --short HEAD) -join '')"

  Step '[2/6] Composer'
  Assert-PhpExtensions $php $deployPath

  # Revalidar: 'git clean' u otro paso pudo haber borrado composer.phar.
  if ($composerArgs.Count -gt 0) {
    if (-not (Test-Path -LiteralPath $composerArgs[0])) {
      throw "Desapareció $($composerArgs[0]) durante el despliegue. Vuelve a colocarlo o instala Composer globalmente."
    }
  }
  elseif (-not (Test-Path -LiteralPath $composerExe)) {
    throw "Desapareció el ejecutable de Composer: $composerExe"
  }

  Invoke-Native $composerExe ($composerArgs + @(
      'install',
      '--no-dev',
      '--no-interaction',
      '--prefer-dist',
      '--no-progress',
      '--optimize-autoloader'
    )) 'composer install'

  Step '[3/6] Limpieza de cachés'
  Invoke-Native $php @('artisan', 'route:clear') 'route:clear'
  Invoke-Native $php @('artisan', 'config:clear') 'config:clear'
  Invoke-Native $php @('artisan', 'view:clear') 'view:clear'
  Invoke-Native $php @('artisan', 'optimize:clear') 'optimize:clear'

  Step '[4/6] Regeneración de cachés'
  # route:cache deshabilitado: en Apache/Windows provoca 405 en "/" (Supported methods: HEAD)
  Invoke-Native $php @('artisan', 'config:cache') 'config:cache'
  Invoke-Native $php @('artisan', 'view:cache') 'view:cache'

  Step '[5/6] Enlace de almacenamiento'
  if (-not (Test-Path -LiteralPath (Join-Path $deployPath 'public\storage'))) {
    Invoke-Native $php @('artisan', 'storage:link') 'storage:link'
    Write-Host 'Enlace public/storage creado.'
  }
  else {
    Write-Host 'public/storage ya existe.'
  }

  Step '[6/6] Apache'
  $servicioApache = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
  if (-not $servicioApache) {
    Write-Host 'Apache no está instalado como servicio; se omite el reinicio.'
  }
  else {
    # Restart-Service NO tiene tiempo límite: si un proceso hijo no termina, se
    # queda esperando para siempre y el despliegue muere por CommandTimeout sin
    # decir nada. Con WaitForStatus el tiempo está acotado y el fallo se explica.
    $limite = [TimeSpan]::FromSeconds(90)
    $servicioApache.Refresh()

    if ($servicioApache.Status -ne 'Stopped') {
      Write-Host ("Deteniendo Apache (máximo {0:N0}s, estado actual {1})..." -f $limite.TotalSeconds, $servicioApache.Status)
      $servicioApache.Stop()
      try { $servicioApache.WaitForStatus('Stopped', $limite) }
      catch {
        $servicioApache.Refresh()
        $estadoApache = $servicioApache.Status
        throw ("Apache no se detuvo en {0:N0}s; quedó en estado {1}. Suele haber un php.exe colgado: revisa el Administrador de tareas o reinicia el servicio a mano." -f $limite.TotalSeconds, $estadoApache)
      }
    }

    $servicioApache.Refresh()
    if ($servicioApache.Status -ne 'Running') {
      Write-Host 'Arrancando Apache...'
      $servicioApache.Start()
      try { $servicioApache.WaitForStatus('Running', $limite) }
      catch {
        $servicioApache.Refresh()
        throw ("Apache no arrancó en {0:N0}s; se quedó en estado {1}." -f $limite.TotalSeconds, $servicioApache.Status)
      }
    }

    Write-Host ("Apache en marcha ({0:N1}s)." -f ((Get-Date) - $guionInicio).TotalSeconds)
  }

  Write-Host ''
  Write-Host ("Despliegue completado en {0:N1}s." -f ((Get-Date) - $guionInicio).TotalSeconds)
  if ($traza) { try { Stop-Transcript | Out-Null } catch { } }
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ERROR: $($_.Exception.Message)"
  if ($_.InvocationInfo -and $_.InvocationInfo.PositionMessage) {
    Write-Host $_.InvocationInfo.PositionMessage
  }
  Write-Host ("Despliegue abortado tras {0:N1}s." -f ((Get-Date) - $guionInicio).TotalSeconds)
  if ($traza) { try { Stop-Transcript | Out-Null } catch { } }
  exit 1
}
