<#
  Script que se ejecuta EN EL SERVIDOR de producción (Windows + XAMPP).
  Lo invoca .github/workflows/migrate.yml a través de una sesión SSH.

  Los marcadores __DEPLOY_PATH__ y __PHP_BIN__ los sustituye el workflow antes
  de enviarlo. Se puede probar en local así:

      pwsh -NoProfile -File .github/scripts/migrate-remote.ps1
#>

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }

function Step([string]$mensaje) {
  Write-Host ''
  Write-Host "==> $mensaje"
}

function Invoke-Native([string]$exe, [string[]]$argumentos, [string]$que) {
  Write-Host "> $exe $($argumentos -join ' ')"
  & $exe @argumentos
  if ($LASTEXITCODE -ne 0) { throw "$que falló con código de salida $LASTEXITCODE." }
}

function Find-Tool([string]$nombre) {
  $cmd = Get-Command -Name $nombre -CommandType Application -ErrorAction SilentlyContinue
  if (-not $cmd) { return $null }
  return @($cmd)[0].Source
}

try {
  $deployPath = '__DEPLOY_PATH__'
  $phpOverride = '__PHP_BIN__'

  if (-not (Test-Path -LiteralPath $deployPath)) { throw "No existe la ruta: $deployPath" }
  Set-Location -LiteralPath $deployPath

  Step '[0/3] Entorno del servidor'
  Write-Host "Ruta: $deployPath"

  $php = if ($phpOverride) { $phpOverride } else { Find-Tool 'php' }
  if (-not $php) {
    throw "No se encontró 'php' en el PATH de la sesión SSH. Añade la carpeta de PHP (por ejemplo C:\xampp\php) al PATH del sistema y reinicia el servicio sshd, o define la variable PHP_BIN del repositorio."
  }
  Write-Host "php: $php"
  Invoke-Native $php @('-v') 'php -v'

  $envFile = Join-Path $deployPath '.env'
  if (-not (Test-Path -LiteralPath $envFile)) { throw "Falta .env en $deployPath." }
  if (-not (Select-String -Path $envFile -Pattern '^DB_CONNECTION=(mysql|mariadb)' -Quiet)) {
    throw 'DB_CONNECTION debe ser mysql o mariadb en .env.'
  }

  Step '[1/3] Configuración'
  Invoke-Native $php @('artisan', 'config:clear') 'config:clear'

  Step '[2/3] Migraciones pendientes'
  Invoke-Native $php @('artisan', 'migrate', '--force') 'migrate'

  Step '[3/3] Seeders'
  Invoke-Native $php @('artisan', 'db:seed', '--force') 'db:seed'

  Invoke-Native $php @('artisan', 'config:cache') 'config:cache'

  Write-Host ''
  Write-Host 'Migraciones y seeders al día.'
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ERROR: $($_.Exception.Message)"
  if ($_.InvocationInfo -and $_.InvocationInfo.PositionMessage) {
    Write-Host $_.InvocationInfo.PositionMessage
  }
  Write-Host 'Migraciones abortadas.'
  exit 1
}
