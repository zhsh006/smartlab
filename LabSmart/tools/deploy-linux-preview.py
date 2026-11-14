"""Restore an existing logical backup into the isolated Linux preview only."""
from pathlib import Path
import hashlib, json, os, subprocess

root = Path(__file__).resolve().parents[1]
expected = Path('/home/h3clab/smartlab-previews/wb-20261014')
if root.resolve() != expected:
    raise SystemExit('This script only manages the named isolated preview directory.')
os.chdir(root)
compose = ['docker','compose','--env-file','deploy/linux-preview/.env','-f','deploy/linux-preview/docker-compose.yml']
mysql = ['docker','exec','-i','smartlab-wb-preview-mysql','sh','-lc',
         'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" "$MYSQL_DATABASE"']

def run(args, label, content=None):
    print(label, flush=True)
    result = subprocess.run(args, input=content, capture_output=True)
    with (root/'runtime/deployment.log').open('ab') as log:
        log.write((label+'\n').encode()); log.write(result.stdout); log.write(result.stderr)
    if result.returncode:
        raise RuntimeError(label+' failed:\n'+(result.stdout+result.stderr).decode(errors='replace')[-6000:])
    return result.stdout

for folder in ('uploads','cache','exports','agent-inbox','certs'):
    (root/'runtime'/folder).mkdir(parents=True,exist_ok=True)
base = 'elabftw/elabimg@sha256:500b0bcaed1b3b9f825d5272cd517072cd32f3e22d5dc68ec6f72f5a532cb745'
run(['docker','run','--rm','--pull=never','--user','0:0','--entrypoint','sh',
     '-v',str(root/'runtime')+':/preview-runtime',base,'-c',
     'chown -R 101:101 /preview-runtime/uploads /preview-runtime/cache /preview-runtime/exports /preview-runtime/agent-inbox /preview-runtime/certs; chmod 600 /preview-runtime/certs/key.pem'], 'Prepare isolated writable directories')
run(compose+['build','--pull=false','elabftw'], 'Build current source')
run(compose+['up','-d','--wait','--wait-timeout','180','mysql'], 'Start independent database')
dump = root/'runtime/restore/before-database.sql'
marker = root/'runtime/restore/restored.sha256'
dump_hash = hashlib.sha256(dump.read_bytes()).hexdigest()
if not marker.exists():
    count = run(mysql, 'Check empty restore target', b'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();').decode().splitlines()[-1]
    if count != '0': raise RuntimeError('Restore target is not empty; existing data will not be replaced.')
    run(mysql, 'Restore local 06:12 logical backup', dump.read_bytes())
    marker.write_text(dump_hash)
elif marker.read_text().strip() != dump_hash:
    raise RuntimeError('The database was initialized from a different backup.')
backup = root/'runtime/restore/before-wb-conditions.sql'
if not backup.exists():
    data = run(['docker','exec','smartlab-wb-preview-mysql','sh','-lc',
                'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --no-tablespaces --skip-lock-tables -u"$MYSQL_USER" "$MYSQL_DATABASE"'], 'Back up restored database before migration')
    if b'Dump completed' not in data[-1000:]: raise RuntimeError('Database backup is incomplete.')
    backup.write_bytes(data); backup.chmod(0o600)
for migration in ('002_v3_ingest_safety.sql','003_wb_conditions.sql','004_period_report_feedback.sql'):
    run(mysql, 'Apply '+migration, (root/'database/elabftw'/migration).read_bytes())
run(compose+['up','-d','--no-deps','--wait','--wait-timeout','180','elabftw'], 'Start independent application')
run(['docker','cp',str(root/'tools/validate-wb-conditions.php'),'smartlab-wb-preview:/elabftw/cache/validate-wb-conditions.php'], 'Copy rollback-only checks')
result = run(['docker','exec','smartlab-wb-preview','php','/elabftw/cache/validate-wb-conditions.php'], 'Verify persistence, permissions and snapshots')
print(result.decode(), flush=True)
manifest = json.loads((root/'runtime/restore/upload-hashes.json').read_text())
upload_names = list(manifest)
if any(Path(name).is_absolute() or '..' in Path(name).parts for name in upload_names):
    raise RuntimeError('Invalid upload path in manifest.')
hashes = run(['docker','exec','smartlab-wb-preview','sha256sum']+['/elabftw/uploads/'+name for name in upload_names], 'Verify original uploads inside application permissions').decode().splitlines()
if len(hashes)!=len(upload_names) or any(line.split()[0]!=manifest[name] for name,line in zip(upload_names,hashes)):
    raise RuntimeError('Original upload hash mismatch.')
source_files = ['src/Models/SmartlabWbConditions.php','src/Models/SmartlabBenchRecords.php',
                'src/Services/SmartlabAntibodyDatasheet.php','src/templates/smartlab-wb-quick.html',
                'web/assets/smartlab-wb-quick.js']
for name in source_files:
    data = run(['docker','exec','smartlab-wb-preview','cat','/elabftw/'+name], 'Verify deployed '+name)
    if data != (root/'apps/elabftw'/name).read_bytes(): raise RuntimeError('Deployed source mismatch: '+name)
print('Original upload hashes checked: '+str(len(manifest)), flush=True)
print('Independent preview ready on server loopback port 3160.', flush=True)
