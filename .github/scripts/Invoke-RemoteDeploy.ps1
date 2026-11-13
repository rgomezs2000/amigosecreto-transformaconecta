<#
  Envía y ejecuta un script PowerShell en el servidor de producción por SSH.

  Uso desde un workflow (shell: pwsh):

      ./.github/scripts/Invoke-RemoteDeploy.ps1 `
        -RemoteScriptPath '.github/scripts/deploy-remote.ps1' `
        -TimeoutSeconds 1800

  Toma de las variables de entorno: SSH_HOST, SSH_PORT, SSH_USER, SSH_PASSWORD,
  DEPLOY_PATH y, opcionalmente, PHP_BIN y COMPOSER_BIN.

  POR QUÉ SE COMPRIME
  -------------------
  El shell por defecto de OpenSSH en Windows es cmd.exe, que rechaza cualquier
  línea de comandos de más de 8191 caracteres con "The command line is too
  long.". Un script de ~10 KB codificado con -EncodedCommand (base64 de UTF-16)
  ocupa ~25 KB y siempre falla. Comprimido con GZip baja a ~4 KB y el servidor lo
  descomprime en memoria, sin escribir archivos temporales ni depender de SFTP.
#>

[CmdletBinding()]
param(
  [Parameter(Mandatory = $true)]
  [string]$RemoteScriptPath,

  [int]$TimeoutSeconds = 1800
)

$ErrorActionPreference = 'Stop'

function Get-RequiredEnv([string]$nombre) {
  $valor = [Environment]::GetEnvironmentVariable($nombre)
  if ([string]::IsNullOrWhiteSpace($valor)) {
    throw "Falta la variable de entorno $nombre (configúrala en Settings → Secrets and variables → Actions)."
  }
  return $valor
}

function ConvertTo-PsLiteral([string]$valor) {
  # Comillas simples dentro de una cadena literal de PowerShell se duplican.
  if ($null -eq $valor) { return '' }
  return ($valor -replace "'", "''")
}

# ---- 1. Datos de conexión ---------------------------------------------------
$sshHost = Get-RequiredEnv 'SSH_HOST'
$sshUser = Get-RequiredEnv 'SSH_USER'
$sshPassword = Get-RequiredEnv 'SSH_PASSWORD'
$deployPath = Get-RequiredEnv 'DEPLOY_PATH'
$sshPort = if ([string]::IsNullOrWhiteSpace($env:SSH_PORT)) { 22 } else { [int]$env:SSH_PORT }

# ---- 2. Cargar la plantilla y sustituir los marcadores ----------------------
if (-not [IO.Path]::IsPathRooted($RemoteScriptPath) -and $env:GITHUB_WORKSPACE) {
  $candidato = Join-Path $env:GITHUB_WORKSPACE $RemoteScriptPath
  if (Test-Path -LiteralPath $candidato) { $RemoteScriptPath = $candidato }
}

if (-not (Test-Path -LiteralPath $RemoteScriptPath)) {
  throw "No se encontró el script remoto: $RemoteScriptPath"
}

# Ruta de la traza que el script deja EN EL SERVIDOR. Si el comando se agota por
# tiempo, Posh-SSH no devuelve nada; leyendo este archivo sabemos dónde se quedó.
# Lleva marca de tiempo para no leer nunca la traza de una ejecución anterior.
$remoteLog = if ([string]::IsNullOrWhiteSpace($env:REMOTE_LOG)) {
  'C:\Windows\Temp\dsh-deploy-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log'
} else { $env:REMOTE_LOG }

$remoteScript = (Get-Content -LiteralPath $RemoteScriptPath -Raw).
  Replace('__DEPLOY_PATH__', (ConvertTo-PsLiteral $deployPath)).
  Replace('__PHP_BIN__', (ConvertTo-PsLiteral $env:PHP_BIN)).
  Replace('__COMPOSER_BIN__', (ConvertTo-PsLiteral $env:COMPOSER_BIN)).
  Replace('__PHP_EXTENSION__', (ConvertTo-PsLiteral $env:PHP_EXTENSION)).
  Replace('__REMOTE_LOG__', (ConvertTo-PsLiteral $remoteLog))

# ---- 3. Validar la sintaxis ANTES de conectar ------------------------------
# Un error de PowerShell en el servidor se vería como un "ExitStatus 1" mudo.
$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseInput($remoteScript, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors -and $parseErrors.Count -gt 0) {
  $detalle = ($parseErrors | ForEach-Object { "línea $($_.Extent.StartLineNumber): $($_.Message)" }) -join '; '
  throw "El script remoto tiene errores de sintaxis: $detalle"
}

# ---- 4. Comprimir y codificar el payload -----------------------------------
$bytes = [Text.Encoding]::UTF8.GetBytes($remoteScript)
$memoria = [IO.MemoryStream]::new()
$gzip = [IO.Compression.GZipStream]::new($memoria, [IO.Compression.CompressionLevel]::Optimal, $true)
$gzip.Write($bytes, 0, $bytes.Length)
$gzip.Dispose()
$payload = [Convert]::ToBase64String($memoria.ToArray())
$memoria.Dispose()

# cmd.exe acepta 8191 caracteres como máximo. Se deja margen para el prefijo.
$limiteSeguro = 7000
if ($payload.Length -gt $limiteSeguro) {
  throw ("El script remoto comprimido ocupa $($payload.Length) caracteres y supera el límite seguro de $limiteSeguro. " +
    "Divide $(Split-Path -Leaf $RemoteScriptPath) en pasos más pequeños.")
}

Write-Host ("Script remoto: {0} caracteres, {1} comprimido." -f $remoteScript.Length, $payload.Length)

# Descompone en memoria y ejecuta. Add-Type es necesario en Windows PowerShell
# 5.1, que no trae cargado System.IO.Compression. La primera línea fuerza UTF-8
# al escribir en la tubería de SSH; si no, los acentos salen como "fall�".
$decodificador = @(
  'try { Add-Type -AssemblyName System.IO.Compression -ErrorAction Stop } catch { }'
  'try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }'
  "`$m = [IO.MemoryStream]::new([Convert]::FromBase64String('$payload'))"
  '$g = [IO.Compression.GZipStream]::new($m, [IO.Compression.CompressionMode]::Decompress)'
  'Invoke-Expression ([IO.StreamReader]::new($g)).ReadToEnd()'
) -join ('; ')

$command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' + $decodificador + '" 2>&1'

# ---- 5. Conectar, ejecutar y desconectar -----------------------------------
Import-Module Posh-SSH

$securePassword = ConvertTo-SecureString $sshPassword -AsPlainText -Force
$credential = [pscredential]::new($sshUser, $securePassword)

Write-Host "Conectando a ${sshHost}:${sshPort} como ${sshUser}..."

$session = New-SSHSession `
  -ComputerName $sshHost `
  -Port $sshPort `
  -Credential $credential `
  -AcceptKey

try {
  Write-Host "Ejecutando script remoto (límite ${TimeoutSeconds}s)..."
  $inicio = Get-Date

  try {
    $result = Invoke-SSHCommand -SessionId $session.SessionId -Command $command -TimeOut $TimeoutSeconds
  }
  catch {
    Write-Host ''
    Write-Host ("El comando remoto falló o agotó el tiempo tras {0:N0}s." -f ((Get-Date) - $inicio).TotalSeconds)
    Write-Host $_.Exception.Message
    Write-Host ''
    Write-Host "--- últimas líneas de la traza en el servidor ($remoteLog) ---"

    $tailCommand = 'powershell -NoProfile -Command "Get-Content -LiteralPath ' + $remoteLog + ' -Tail 100" 2>&1'
    try {
      $tail = Invoke-SSHCommand -SessionId $session.SessionId -Command $tailCommand -TimeOut 120
      if ($tail.Output) { $tail.Output | ForEach-Object { Write-Host $_ } }
      if ($tail.Error) { $tail.Error | ForEach-Object { Write-Host $_ } }
    }
    catch {
      Write-Host "No se pudo leer la traza: $($_.Exception.Message)"
    }
    throw
  }

  if ($result.Output) {
    $result.Output | ForEach-Object { Write-Host $_ }
  }

  if ($result.Error) {
    Write-Host '--- stderr del servidor ---'
    $result.Error | ForEach-Object { Write-Host $_ }
  }

  if ($result.ExitStatus -ne 0) {
    throw "El script remoto falló con ExitStatus $($result.ExitStatus). Revisa las líneas 'ERROR:' del log anterior."
  }

  Write-Host ("Script remoto completado correctamente en {0:N0}s." -f ((Get-Date) - $inicio).TotalSeconds)
}
finally {
  if ($session) {
    Remove-SSHSession -SessionId $session.SessionId | Out-Null
    Write-Host 'Sesión SSH cerrada.'
  }
}
