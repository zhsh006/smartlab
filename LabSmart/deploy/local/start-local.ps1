param([switch]$Build)

$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is unavailable. Start Docker Desktop and try again.'
}
docker info --format '{{.ServerVersion}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Start Docker Desktop and try again.' }

Push-Location $taskRoot
try {
    $taskComposeArgs = @('compose', '--env-file', 'deploy/local/.env',
        '-f', 'deploy/local/docker-compose.yml',
        '-f', 'deploy/local/docker-compose.preview.yml')
    if ($Build) {
        & docker @taskComposeArgs build --pull=false elabftw
        if ($LASTEXITCODE -ne 0) { throw 'Image build failed.' }
    }
    & docker @taskComposeArgs up -d --wait --wait-timeout 60 mysql
    if ($LASTEXITCODE -ne 0) { throw 'The local database did not become healthy.' }
    & (Join-Path $taskRoot 'tools\apply-v3-safety.ps1')
    & (Join-Path $taskRoot 'tools\apply-wb-conditions.ps1')
    & docker @taskComposeArgs up -d --wait --wait-timeout 60 elabftw
    if ($LASTEXITCODE -ne 0) { throw 'The local services did not become healthy.' }

    $taskPreviewScript = Join-Path (Split-Path $taskRoot -Parent) 'preview-v1\preview-server.js'
    $taskPreviewListener = Get-NetTCPConnection -LocalPort 5180 -State Listen -ErrorAction SilentlyContinue
    if (-not $taskPreviewListener -and (Test-Path -LiteralPath $taskPreviewScript)) {
        $taskNodeCommand = Get-Command node -ErrorAction SilentlyContinue
        $taskNodePath = if ($taskNodeCommand) { $taskNodeCommand.Source } else {
            Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
        }
        if (Test-Path -LiteralPath $taskNodePath) {
            $taskLogPrefix = Join-Path $taskRoot ('.local\preview-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
            Start-Process -FilePath $taskNodePath -ArgumentList ('"' + $taskPreviewScript + '"') `
                -WorkingDirectory (Split-Path $taskPreviewScript -Parent) -WindowStyle Hidden `
                -RedirectStandardOutput ($taskLogPrefix + '.log') `
                -RedirectStandardError ($taskLogPrefix + '.err.log') | Out-Null
        } else {
            Write-Warning 'Node.js is unavailable; the separate design preview was not started.'
        }
    }
    Write-Host 'Full local system: https://localhost:3159/'
    Write-Host 'Separate design demo: http://127.0.0.1:5180/ (no real persistence)'
} finally {
    Pop-Location
}
