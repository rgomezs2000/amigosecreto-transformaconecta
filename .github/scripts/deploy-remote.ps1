<#
  Script que se ejecuta EN EL SERVIDOR de producción (Windows + XAMPP).
  Lo invoca .github/workflows/deploy.yml a través de una sesión SSH.

  Los marcadores __DEPLOY_PATH__, __PHP_BIN__ y __COMPOSER_BIN__ los sustituye
  el workflow antes de enviarlo. Se puede probar en local así:

      pwsh -NoProfile -File .github/scripts/deploy-remote.ps1

  (sin sustituir los marcadores fallará a propósito en la comprobación de ruta.)
#>

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Step([string]$mensaje) {
  Write-Host ''
  Write-Host "==> $mensaje"
}

# Ejecuta un programa, deja ver su salida y falla si el código de salida no es 0.
function Invoke-Native([string]$exe, [string[]]$argumentos, [string]$que) {
  Write-Host "> $exe $($argumentos -join ' ')"
  & $exe @argumentos
  if ($LASTEXITCODE -ne 0) { throw "$que falló con código de salida $LASTEXITCODE." }
}

# Busca un ejecutable en el PATH y devuelve su ruta completa (o $null).
function Find-Tool([string]$nombre) {
  $cmd = Get-Command -Name $nombre -CommandType Application -ErrorAction SilentlyContinue
  if (-not $cmd) { return $null }
  return @($cmd)[0].Source
}

try {
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

  Invoke-Native $php @('-v') 'php -v'

  $phpVersion = ((& $php -r 'echo PHP_VERSION;') -join '').Trim()
  if ([string]::IsNullOrWhiteSpace($phpVersion)) { $phpVersion = 'desconocida' }
  $phpExtensions = ((& $php -r 'echo implode(", ", get_loaded_extensions());') -join '').Trim()

  Write-Host "Versión de PHP: $phpVersion"
  Write-Host "Extensiones PHP: $phpExtensions"

  if ($phpVersion -match '^(\d+)\.(\d+)') {
    $phpMajor = [int]$Matches[1]
    $phpMinor = [int]$Matches[2]
    if ($phpMajor -lt 8 -or ($phpMajor -eq 8 -and $phpMinor -lt 2)) {
      Write-Host "ADVERTENCIA: composer.json exige PHP ^8.2 y el servidor reporta $phpVersion. Composer fallará hasta que se actualice PHP."
    }
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
  if (Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue) {
    Restart-Service -Name 'Apache2.4'
    Write-Host 'Apache reiniciado.'
  }
  else {
    Write-Host 'Apache no está instalado como servicio; se omite el reinicio.'
  }

  Write-Host ''
  Write-Host 'Despliegue completado.'
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ERROR: $($_.Exception.Message)"
  if ($_.InvocationInfo -and $_.InvocationInfo.PositionMessage) {
    Write-Host $_.InvocationInfo.PositionMessage
  }
  Write-Host 'Despliegue abortado.'
  exit 1
}
