$ErrorActionPreference = 'Stop'
$taskPreviewRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$taskPreviewKey = Join-Path $taskPreviewRoot '.local\remote-preview\tunnel-key.pem'
$taskPreviewPidFile = Join-Path $taskPreviewRoot '.local\remote-preview\tunnel.pid'
if (-not (Test-Path -LiteralPath $taskPreviewKey)) { throw 'The dedicated preview tunnel key is missing.' }
$taskPreviewListener = @(Get-NetTCPConnection -LocalAddress '127.0.0.1' -LocalPort 3160 -State Listen -ErrorAction SilentlyContinue)
if ($taskPreviewListener.Count -gt 0) {
    $taskPreviewOwner = Get-CimInstance Win32_Process -Filter "ProcessId=$($taskPreviewListener[0].OwningProcess)"
    if ($taskPreviewOwner.Name -ne 'ssh.exe' -or $taskPreviewOwner.CommandLine -notlike '*tunnel-key.pem*' -or $taskPreviewOwner.CommandLine -notlike '*h3clab@172.18.21.142*') {
        throw 'Port 3160 is used by another application. No process was changed.'
    }
    Write-Host 'Preview tunnel is already running: https://localhost:3160/'
    return
}
$taskPreviewSsh = (Get-Command ssh.exe).Source
$taskPreviewProcess = Start-Process -FilePath $taskPreviewSsh -ArgumentList @(
    '-N','-T','-i','.local/remote-preview/tunnel-key.pem',
    '-L','127.0.0.1:3160:127.0.0.1:3160',
    '-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes',
    '-o','ExitOnForwardFailure=yes','-o','BatchMode=yes',
    '-o','ServerAliveInterval=30','-o','ServerAliveCountMax=3',
    '-E','.local/remote-preview/tunnel.log','h3clab@172.18.21.142'
) -WorkingDirectory $taskPreviewRoot -WindowStyle Hidden -PassThru
$taskPreviewProcess.Id | Set-Content -LiteralPath $taskPreviewPidFile
Write-Host 'Preview tunnel started: https://localhost:3160/'
