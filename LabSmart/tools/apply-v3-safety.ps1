$ErrorActionPreference = 'Stop'
$taskProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$taskMigration = Join-Path $taskProjectRoot 'database\elabftw\002_v3_ingest_safety.sql'
$taskMysqlCommand = 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" "$MYSQL_DATABASE"'
Get-Content -LiteralPath $taskMigration -Raw -Encoding UTF8 | docker exec -i smartlab-v4-elabftw-mysql sh -lc $taskMysqlCommand
if ($LASTEXITCODE -ne 0) { throw 'V3 migration failed. The existing database and files were not reset.' }
Write-Host 'V3 ingest safety migration applied.'
