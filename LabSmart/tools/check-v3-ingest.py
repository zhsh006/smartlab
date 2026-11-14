"""Local integration check: artificial files, existing device credential, verified TLS.

Leaves one clearly labelled pending package for UI verification; never changes source files.
"""
from pathlib import Path
from types import SimpleNamespace
import hashlib
import importlib.util
import json
import subprocess
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "v3-batch1-20261014"
OUT.mkdir(parents=True, exist_ok=True)

def sql(query):
    result = subprocess.run(["docker", "exec", "-i", "smartlab-v4-elabftw-mysql", "sh", "-lc",
        'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE"'],
        input=query.encode("utf-8"), capture_output=True, check=True)
    return result.stdout.decode("utf-8").strip()

spec = importlib.util.spec_from_file_location("agent", ROOT / "tools" / "agent" / "smartlab-agent.py")
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)
token = sql('SELECT token FROM smartlab_agents WHERE status="active" AND LENGTH(token)=48 ORDER BY id LIMIT 1;')
if not token:
    raise RuntimeError("An existing local test device is required; no credential is created automatically.")
files = []
for directory, value in (("run-a", "11"), ("run-b", "22")):
    path = OUT / "fixtures" / directory / "same-name.csv"
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.exists():
        path.write_text("sample,value\nCHECK," + value + "\n", encoding="utf-8")
    files.append(str(path))
before = [agent.sha256_of(p) for p in files]
assert before[0] != before[1], 'The same-name fixtures must have distinct contents'
args = SimpleNamespace(server="https://localhost:3159", token=token, name="CHECK-V3-BATCH1",
                       ca=str(ROOT / ".local" / "certs" / "local-ca.pem"), insecure=False)
context = agent.build_ssl_context(args)
first = agent.upload_package(args, context, files)
second = agent.upload_package(args, context, files)
assert first["ok"] and first["package_id"] == second["package_id"], "Retry produced a duplicate package"
package_id = int(first["package_id"])
stored = json.loads(sql("SELECT files_json FROM smartlab_data_packages WHERE id=" + str(package_id) + ";"))
actual = []
for item in stored:
    content = subprocess.check_output(["docker", "exec", "smartlab-v4-elabftw", "cat", "/elabftw/agent-inbox/" + item["stored"]])
    actual.append(hashlib.sha256(content).hexdigest())
assert len(stored) == 2 and len({item["stored"] for item in stored}) == 2
assert actual == before and before == [agent.sha256_of(p) for p in files]

# Incorrect expected hash must be rejected without publishing another package.
bad = [dict(item) for item in first["source_manifest"]]
bad[0]["sha256"] = "0" * 64
body, content_type = agent.build_multipart({"token": token, "manifest": json.dumps(bad),
    "request_id": hashlib.sha256(uuid.uuid4().bytes).hexdigest()}, files)
count_before = sql("SELECT COUNT(*) FROM smartlab_data_packages;")
request = urllib.request.Request(args.server + "/smartlab-agent.php", body, method="POST", headers={"Content-Type": content_type})
try:
    urllib.request.urlopen(request, context=context).close()
    raise AssertionError("Bad expected hash was accepted")
except urllib.error.HTTPError as error:
    assert error.code == 422
assert count_before == sql("SELECT COUNT(*) FROM smartlab_data_packages;")
evidence = {"scope": "Only synthetic local files", "package_id": package_id,
    "same_names_preserved": True, "unique_stored_paths": 2, "both_source_hashes_match": True,
    "source_files_unchanged": True, "retry_returned_same_package": True,
    "invalid_hash_rejected_without_publish": True,
    "status": sql("SELECT status FROM smartlab_data_packages WHERE id=" + str(package_id) + ";")}
(OUT / "ingest-check.json").write_text(json.dumps(evidence, ensure_ascii=False, indent=2), encoding="utf-8")
print(json.dumps(evidence, ensure_ascii=False))
