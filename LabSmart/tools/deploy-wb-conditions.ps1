$ErrorActionPreference = 'Stop'
$taskWbDeployRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$taskWbPython = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
Push-Location $taskWbDeployRoot
try {
    docker info --format '{{.ServerVersion}}' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop Linux engine must be running before deployment.' }
    $taskWbCompose = @('compose','--env-file','deploy/local/.env','-f','deploy/local/docker-compose.yml','-f','deploy/local/docker-compose.preview.yml')
    & docker @taskWbCompose up -d --wait --wait-timeout 60 mysql
    if ($LASTEXITCODE -ne 0) { throw 'The local database is not healthy.' }
    & $taskWbPython .local/backup-wb-conditions.py
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath '.local/change-backups/20261014-wb-conditions/before-database.sql')) { throw 'A verified database backup is required before migration.' }
    & docker @taskWbCompose build --pull=false elabftw
    if ($LASTEXITCODE -ne 0) { throw 'Image build failed; existing application was retained.' }
    & ./tools/apply-v3-safety.ps1
    & ./tools/apply-wb-conditions.ps1
    & docker @taskWbCompose up -d --no-deps --wait --wait-timeout 60 elabftw
    if ($LASTEXITCODE -ne 0) { throw 'The new local application did not become healthy.' }
    docker cp tools/validate-wb-conditions.php smartlab-v4-elabftw:/elabftw/cache/validate-wb-conditions.php
    docker exec smartlab-v4-elabftw php /elabftw/cache/validate-wb-conditions.php
    if ($LASTEXITCODE -ne 0) { throw 'WB conditions database checks failed; inspect logs before use.' }
    Write-Host 'Local WB conditions deployed and checked: https://localhost:3159/'
} finally { Pop-Location }
