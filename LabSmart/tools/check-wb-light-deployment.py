"""Read-only local checks: deployed bytes, prior history/RAW, and synthetic UI upload."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'docs/v3-wb-light-20261014'

def sql(query):
    result = subprocess.run(['docker', 'exec', '-i', 'smartlab-v4-elabftw-mysql', 'sh', '-lc',
        'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE"'],
        input=query.encode('utf-8'), capture_output=True, check=True)
    return result.stdout.decode('utf-8').strip()

def dumped_rows(dump, table):
    result = {}
    for match in re.finditer(r'INSERT INTO `' + re.escape(table) + r'` VALUES (.*);\n', dump):
        data = match.group(1)
        quoted = escaped = False
        depth = 0
        start = None
        for i, char in enumerate(data):
            if escaped:
                escaped = False
                continue
            if quoted and char == '\\':
                escaped = True
                continue
            if char == "'":
                quoted = not quoted
            elif not quoted:
                if char == '(':
                    if depth == 0: start = i
                    depth += 1
                elif char == ')':
                    depth -= 1
                    if depth == 0:
                        row = data[start:i+1]
                        key = int(re.match(r'\((\d+),', row).group(1))
                        result[key] = row
    assert result, 'Baseline table was not parsed: ' + table
    return result

backup = ROOT / (ROOT / '.local/wb-light-backup-path.txt').read_text(encoding='utf-8-sig').strip()
before = (backup / 'before-database.sql').read_text(encoding='utf-8')
current = subprocess.check_output(['docker', 'exec', 'smartlab-v4-elabftw-mysql', 'sh', '-lc',
    'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --no-tablespaces --skip-lock-tables -u"$MYSQL_USER" "$MYSQL_DATABASE" smartlab_bench_records uploads']).decode('utf-8')
preserved = {}
for table in ('smartlab_bench_records', 'uploads'):
    old = dumped_rows(before, table)
    live = dumped_rows(current, table)
    assert all(live.get(key) == row for key, row in old.items()), 'Prior row changed: ' + table
    preserved[table] = len(old)

raw_count = 0
with zipfile.ZipFile(ROOT / '.local/change-backups/20261014-044526/before-files.zip') as archive:
    for name in archive.namelist():
        if name.endswith('/'): continue
        assert hashlib.sha256(archive.read(name)).digest() == hashlib.sha256((ROOT / name).read_bytes()).digest(), 'Prior file changed'
        raw_count += 1

source_files = ['src/Models/SmartlabBenchRecords.php', 'src/templates/smartlab-record.html', 'src/templates/smartlab-wb-light.html',
    'web/assets/labsmart-record.js', 'web/assets/labsmart-bench.js', 'web/assets/labsmart-record.css']
for name in source_files:
    live = subprocess.check_output(['docker', 'exec', 'smartlab-v4-elabftw', 'cat', '/elabftw/' + name])
    assert hashlib.sha256(live).digest() == hashlib.sha256((ROOT / 'apps/elabftw' / name).read_bytes()).digest(), 'Deployment differs: ' + name

fixture_id = int(json.loads((OUT / 'ui-fixture.json').read_text(encoding='utf-8-sig'))['experiment_id'])
records = json.loads(sql('SELECT JSON_ARRAYAGG(JSON_OBJECT("key",record_key,"revision",revision,"payload",payload)) FROM smartlab_bench_records WHERE experiment_id=' + str(fixture_id) + ';'))
latest = {}
for record in records:
    key = record['key']
    if key not in latest or record['revision'] > latest[key]['revision']: latest[key] = record
assert latest['step.loading']['payload']['fields']['loading'] == '30 μg / lane'
assert latest['step.loading']['payload']['fields']['gel'] == '10%'
assert latest['step.loading']['payload']['parameter_states']['loading'] == 'overridden'
assert latest['step.finish']['payload']['state'] == 'confirmed'
assert 'result' not in latest, 'Finishing created or required a report'

photo = json.loads(sql('SELECT JSON_OBJECT("id",id,"name",real_name,"path",long_name,"hash",hash,"size",filesize) FROM uploads WHERE type="experiments" AND item_id=' + str(fixture_id) + ' AND real_name="WB-light-demo.csv" ORDER BY id DESC LIMIT 1;'))
original = (OUT / 'fixtures/WB-light-demo.csv').read_bytes()
relative = Path(photo['path'])
if len(relative.parts) == 1: relative = Path(photo['path'][:2]) / relative
stored = ROOT / '.local/elabftw/uploads' / relative
assert stored.resolve().is_relative_to((ROOT / '.local/elabftw/uploads').resolve())
assert stored.read_bytes() == original
assert photo['hash'] == hashlib.sha256(original).hexdigest()
imaging = latest['step.imaging']['payload']
assert imaging['state'] == 'confirmed' and photo['id'] in imaging['evidence_ids'], 'Upload was not linked or undid execution'
php = r'''<?php
chdir('/elabftw'); require 'vendor/autoload.php';
$bench = new \Elabftw\Models\SmartlabBenchRecords(new \Elabftw\Models\Users\Users(2, 1));
$context = $bench->read(PREVIEW_ID);
echo json_encode(\Elabftw\Models\SmartlabBenchRecords::progress($context['template'], $context['records']));
'''.replace('PREVIEW_ID', str(fixture_id))
progress = json.loads(subprocess.check_output(['docker', 'exec', '-i', 'smartlab-v4-elabftw', 'php'], input=php.encode('utf-8')))
assert progress['finished'], 'Adding an attachment reopened the finished experiment'
result = dict(deployed_sources_match=len(source_files), prior_record_rows_preserved=preserved['smartlab_bench_records'],
    prior_upload_rows_preserved=preserved['uploads'], prior_file_bytes_preserved=raw_count,
    fixture_experiment_id=fixture_id, changed_one_value_preserved=True, finished_without_report=True,
    uploaded_file_bytes_and_sha256_match=True, upload_linked_to_confirmed_step=True, post_upload_keeps_experiment_finished=True)
(OUT / 'deployment-and-data-check.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps(result, ensure_ascii=False, indent=2))
