<#
  Activa una extensión de PHP en el php.ini que el servidor carga de verdad.

  Lo invoca .github/workflows/php-extension.yml con la extensión como entrada.
  El marcador __PHP_EXTENSION__ lo sustituye el workflow.

  Es idempotente: si la extensión ya está cargada no toca nada. Antes de editar
  guarda una copia del php.ini y, si la verificación posterior falla, restaura el
  original y explica el motivo.
#>

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }

function Step([string]$mensaje) {
  Write-Host ''
  Write-Host "==> $mensaje"
}

function Find-Tool([string]$nombre) {
  $cmd = Get-Command -Name $nombre -CommandType Application -ErrorAction SilentlyContinue
  if (-not $cmd) { return $null }
  return @($cmd)[0].Source
}

function Get-Cargadas([string]$php) {
  return @((& $php -m) | Where-Object { $_ -and $_ -notmatch '^\[' } | ForEach-Object { $_.Trim().ToLowerInvariant() })
}

try {
  $extension = '__PHP_EXTENSION__'
  $phpOverride = '__PHP_BIN__'

  Step '[1/4] Extensión solicitada'
  if ([string]::IsNullOrWhiteSpace($extension) -or $extension -like '__PHP*') {
    throw 'No se indicó la extensión. Ejecuta el workflow "Extensiones PHP" con la entrada extension (por ejemplo sodium).'
  }
  $extension = $extension.Trim().ToLowerInvariant()
  Write-Host "Extensión: $extension"

  Step '[2/4] PHP y estado actual'
  $php = if ($phpOverride -and $phpOverride -notlike '__PHP*') { $phpOverride } else { Find-Tool 'php' }
  if (-not $php) {
    throw "No se encontró 'php' en el PATH de la sesión SSH. Define la variable PHP_BIN del repositorio con la ruta completa de php.exe."
  }
  Write-Host "php: $php"
  Write-Host (((& $php -v) -join "`n").Trim())

  if ((Get-Cargadas $php) -contains $extension) {
    Write-Host ''
    Write-Host "La extensión '$extension' ya está activa. Nada que hacer."
    exit 0
  }
  Write-Host "La extensión '$extension' NO está activa."

  Step '[3/4] Localizar el php.ini en uso'
  $iniInfo = ((& $php '--ini') -join "`n")
  Write-Host $iniInfo

  $coincidencia = [regex]::Match($iniInfo, '(?m)^\s*Loaded Configuration File:\s*(.+?)\s*$')
  if (-not $coincidencia.Success) { throw 'No se pudo leer la salida de "php --ini".' }

  $iniPath = $coincidencia.Groups[1].Value
  if ($iniPath -eq '(none)' -or -not (Test-Path -LiteralPath $iniPath)) {
    throw ("PHP no está cargando ningún php.ini (Loaded Configuration File: '$iniPath'). " +
      "Copia php.ini-development a php.ini en $(Split-Path -Parent $php) y vuelve a intentarlo.")
  }
  Write-Host "php.ini en uso: $iniPath"

  # Si ya está declarada y aun así no se carga, editar más no sirve.
  $declaradas = @(Select-String -Path $iniPath -Pattern '^\s*extension\s*=\s*(.+?)\s*$' | ForEach-Object {
      $valor = $_.Matches[0].Groups[1].Value.Trim().Trim('"').ToLowerInvariant()
      ($valor -replace '\.dll$', '') -replace '^php_', ''
    })
  if ($declaradas -contains $extension) {
    throw ("'$extension' ya aparece activa en $iniPath pero PHP no la carga. " +
      'Suele ser extension_dir mal configurado o que falta la DLL; revisa los avisos de arranque que muestra "php -m".')
  }

  $phpDir = Split-Path -Parent $php
  $candidatos = @(
    (Join-Path $phpDir ('ext\php_' + $extension + '.dll')),
    (Join-Path $phpDir ('ext\' + $extension + '.dll'))
  )
  $dll = $candidatos | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
  if (-not $dll) {
    throw ("No se encontró la DLL de '$extension'. Rutas comprobadas: " + ($candidatos -join ', ') + '. ' +
      'Si extension_dir apunta a otra carpeta, búscala allí; si la DLL no existe, hay que instalarla.')
  }
  Write-Host "DLL: $dll"

  Step '[4/4] Activar y verificar'
  $marca = Get-Date -Format 'yyyyMMdd-HHmmss'
  $backup = "$iniPath.bak-$marca"
  Copy-Item -LiteralPath $iniPath -Destination $backup -Force
  Write-Host "Copia de seguridad: $backup"

  Add-Content -LiteralPath $iniPath -Value '' -Encoding ASCII
  Add-Content -LiteralPath $iniPath -Value '; Activado por el workflow "Extensiones PHP"' -Encoding ASCII
  Add-Content -LiteralPath $iniPath -Value "extension=$extension" -Encoding ASCII

  if ((Get-Cargadas $php) -notcontains $extension) {
    Copy-Item -LiteralPath $backup -Destination $iniPath -Force
    throw ("Se añadió 'extension=$extension' a $iniPath pero PHP sigue sin cargarla. " +
      "Se restauró el archivo original. Revisa extension_dir y los avisos de arranque.")
  }
  Write-Host "Verificado: 'php -m' ya lista '$extension'."

  # Restart-Service no tiene tiempo límite; acotarlo evita que el workflow se
  # quede colgado esperando a que Apache se detenga.
  $servicioApache = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
  if ($servicioApache) {
    $limite = [TimeSpan]::FromSeconds(90)
    $servicioApache.Refresh()
    if ($servicioApache.Status -ne 'Stopped') {
      $servicioApache.Stop()
      try { $servicioApache.WaitForStatus('Stopped', $limite) }
      catch { Write-Host 'Apache no se detuvo a tiempo; reinícialo a mano para que la web cargue la extensión.' }
    }
    $servicioApache.Refresh()
    if ($servicioApache.Status -ne 'Running') {
      $servicioApache.Start()
      try { $servicioApache.WaitForStatus('Running', $limite) }
      catch { Write-Host 'Apache no arrancó a tiempo; arráncalo a mano desde el panel de XAMPP.' }
    }
    Write-Host 'Apache reiniciado: la extensión queda disponible también para la web.'
  }
  else {
    Write-Host 'Apache no está instalado como servicio; reinícialo a mano para que la web la cargue.'
  }

  Write-Host ''
  Write-Host "Extensión '$extension' activada."
  Write-Host "Para revertir: Copy-Item -LiteralPath '$backup' -Destination '$iniPath' -Force"
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ERROR: $($_.Exception.Message)"
  if ($_.InvocationInfo -and $_.InvocationInfo.PositionMessage) {
    Write-Host $_.InvocationInfo.PositionMessage
  }
  Write-Host 'Activación abortada.'
  exit 1
}
