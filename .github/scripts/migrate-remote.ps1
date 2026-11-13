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

$guionInicio = Get-Date

function Step([string]$mensaje) {
  Write-Host ''
  Write-Host ("==> {0}   [+{1:N1}s]" -f $mensaje, ((Get-Date) - $guionInicio).TotalSeconds)
}

function Invoke-Native([string]$exe, [string[]]$argumentos, [string]$que) {
  Write-Host "> $exe $($argumentos -join ' ')"
  $inicio = Get-Date
  # Ver la nota en deploy-remote.ps1: 2>&1 necesita ErrorActionPreference
  # distinto de 'Stop' en Windows PowerShell 5.1.
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

function Find-Tool([string]$nombre) {
  $cmd = Get-Command -Name $nombre -CommandType Application -ErrorAction SilentlyContinue
  if (-not $cmd) { return $null }
  return @($cmd)[0].Source
}

try {
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
  Write-Host ("Migraciones y seeders al día en {0:N1}s." -f ((Get-Date) - $guionInicio).TotalSeconds)
  if ($traza) { try { Stop-Transcript | Out-Null } catch { } }
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ERROR: $($_.Exception.Message)"
  if ($_.InvocationInfo -and $_.InvocationInfo.PositionMessage) {
    Write-Host $_.InvocationInfo.PositionMessage
  }
  Write-Host ("Migraciones abortadas tras {0:N1}s." -f ((Get-Date) - $guionInicio).TotalSeconds)
  if ($traza) { try { Stop-Transcript | Out-Null } catch { } }
  exit 1
}
