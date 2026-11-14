$ErrorActionPreference = 'Stop'
$taskWbRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$taskWbMigration = Join-Path $taskWbRoot 'database\elabftw\003_wb_conditions.sql'
$taskWbMysql = 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" "$MYSQL_DATABASE"'
Get-Content -LiteralPath $taskWbMigration -Raw -Encoding UTF8 | docker exec -i smartlab-v4-elabftw-mysql sh -lc $taskWbMysql
if ($LASTEXITCODE -ne 0) { throw 'WB conditions migration failed; existing data was retained.' }
Write-Host 'WB conditions migration applied.'
