#!/usr/bin/env python3
"""SmartLab Agent: watch instrument export folders and upload new data packages.

Design (V3): monitor configured directories, wait until files are stable,
then upload them as one *package* to the SmartLab server inbox. The server
keeps them in the "待关联数据" queue until a human confirms which experiment
they belong to. The agent never modifies or deletes source files.

Requires: Python 3.8+ (stdlib only). See README.md for deployment.
"""

import argparse
import hashlib
import io
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request
import uuid

SKIP_SUFFIXES = (".tmp", ".part", ".crdownload", ".partial", ".swp")
STATE_VERSION = 1


def log(message):
    print("[%s] %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), message), flush=True)


def parse_args():
    parser = argparse.ArgumentParser(description="SmartLab Agent (instrument data collector)")
    parser.add_argument("--server", required=True, help="SmartLab base URL, e.g. https://172.18.21.53:3159")
    parser.add_argument("--token", required=True, help="device token generated on the 数据 page")
    parser.add_argument("--name", default=os.uname().nodename if hasattr(os, "uname") else "instrument-pc",
                        help="agent/device display name, e.g. CFX96-01")
    parser.add_argument("--watch", action="append", required=True, help="directory to watch (repeatable)")
    parser.add_argument("--interval", type=int, default=10, help="scan interval seconds (default 10)")
    parser.add_argument("--stable-seconds", type=int, default=8,
                        help="file must be unchanged for this long before upload (default 8; V3 现场可用 30)")
    parser.add_argument("--state", default="smartlab-agent-state.json", help="state file path")
    parser.add_argument("--ca", default=None, help="CA certificate (PEM) to verify the server")
    parser.add_argument("--insecure", action="store_true", help="skip TLS verification (not recommended)")
    parser.add_argument("--once", action="store_true", help="single scan cycle then exit (for testing/cron)")
    parser.add_argument("--max-files", type=int, default=200, help="max files per package (default 200)")
    return parser.parse_args()


def load_state(path):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            data = json.load(handle)
        if isinstance(data, dict) and data.get("version") == STATE_VERSION:
            return data
    except (OSError, ValueError):
        pass
    return {"version": STATE_VERSION, "uploaded": {}}


def save_state(path, state):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(state, handle, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def scan_files(watch_dirs):
    found = []
    for base in watch_dirs:
        if not os.path.isdir(base):
            log("watch directory does not exist yet: %s" % base)
            continue
        for root, dirs, files in os.walk(base):
            dirs[:] = [d for d in dirs if not d.startswith(".")]
            for name in files:
                if name.startswith(".") or name.lower().endswith(SKIP_SUFFIXES):
                    continue
                full = os.path.abspath(os.path.join(root, name))
                try:
                    stat = os.stat(full)
                except OSError:
                    continue
                if stat.st_size <= 0:
                    continue
                found.append((full, stat.st_size, stat.st_mtime_ns))
    return found


def stable_candidates(found, tracking, stable_seconds, state):
    """Return files that are new (not uploaded) and unchanged for stable_seconds."""
    ready = []
    now = time.time()
    seen = set()
    for path, size, mtime in found:
        seen.add(path)
        if path in state["uploaded"]:
            entry = state["uploaded"][path]
            if entry.get("size") == size and entry.get("mtime") == mtime:
                continue  # already uploaded and unchanged
        key = (size, mtime)
        info = tracking.get(path)
        if info is None or info[0] != key:
            tracking[path] = (key, now)
            continue  # first sighting (or changed): wait for stability
        if now - info[1] >= stable_seconds:
            ready.append(path)
    for path in list(tracking):
        if path not in seen:
            del tracking[path]
    return ready


def sha256_of(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def build_multipart(fields, file_paths, manifest=None):
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for key, value in fields.items():
        body.write(("--%s\r\n" % boundary).encode())
        body.write(('Content-Disposition: form-data; name="%s"\r\n\r\n' % key).encode())
        body.write(str(value).encode("utf-8"))
        body.write(b"\r\n")
    for index, path in enumerate(file_paths):
        name = os.path.basename(path)
        body.write(("--%s\r\n" % boundary).encode())
        body.write(('Content-Disposition: form-data; name="files[]"; filename="%s"\r\n' % name).encode("utf-8"))
        body.write(b"Content-Type: application/octet-stream\r\n\r\n")
        digest = hashlib.sha256()
        size = 0
        with open(path, "rb") as handle:
            while True:
                chunk = handle.read(1024 * 1024)
                if not chunk:
                    break
                body.write(chunk)
                digest.update(chunk)
                size += len(chunk)
        if manifest is not None and (digest.hexdigest() != manifest[index]["sha256"] or size != manifest[index]["size"]):
            raise RuntimeError("source changed while packaging; waiting for a stable copy")
        body.write(b"\r\n")
    body.write(("--%s--\r\n" % boundary).encode())
    return body.getvalue(), "multipart/form-data; boundary=%s" % boundary


def build_ssl_context(args):
    if args.insecure:
        log("WARNING: TLS verification disabled (--insecure).")
        return ssl._create_unverified_context()
    if args.ca:
        return ssl.create_default_context(cafile=args.ca)
    return ssl.create_default_context()


def upload_package(args, context, file_paths):
    folder = os.path.basename(os.path.dirname(file_paths[0])) or "instrument"
    manifest = []
    for path in file_paths:
        before = os.stat(path)
        digest = sha256_of(path)
        after = os.stat(path)
        if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
            raise RuntimeError("source changed while hashing; waiting for stability")
        manifest.append({"name": os.path.basename(path), "source_path": os.path.abspath(path),
                         "size": after.st_size, "mtime_ns": after.st_mtime_ns, "sha256": digest})
    request_id = hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")).hexdigest()
    fields = {
        "token": args.token,
        "source_label": args.name,
        "package_label": "%s · 数据包" % folder,
        "manifest": json.dumps(manifest, ensure_ascii=False),
        "request_id": request_id,
    }
    body, content_type = build_multipart(fields, file_paths, manifest)
    request = urllib.request.Request(
        args.server.rstrip("/") + "/smartlab-agent.php",
        data=body,
        method="POST",
        headers={"Content-Type": content_type, "User-Agent": "SmartLab-Agent/1.0"},
    )
    with urllib.request.urlopen(request, context=context, timeout=300) as response:
        result = json.loads(response.read().decode("utf-8"))
        if result.get("ok") and (result.get("request_id") != request_id or result.get("files") != len(manifest)):
            raise RuntimeError("server acknowledgment does not match the package")
        result["source_manifest"] = manifest
        return result


def main():
    args = parse_args()
    state = load_state(args.state)
    tracking = {}
    log("SmartLab Agent starting: name=%s server=%s watch=%s" % (args.name, args.server, ", ".join(args.watch)))
    context = build_ssl_context(args)

    while True:
        try:
            found = scan_files(args.watch)
            ready = stable_candidates(found, tracking, args.stable_seconds, state)
            if ready:
                ready = ready[: args.max_files]
                log("detected %d stable new file(s), uploading package..." % len(ready))
                result = upload_package(args, context, ready)
                if result.get("ok"):
                    now = time.strftime("%Y-%m-%d %H:%M:%S")
                    for path, entry in zip(ready, result["source_manifest"]):
                        state["uploaded"][path] = {
                            "size": entry["size"],
                            "mtime": entry["mtime_ns"],
                            "sha256": entry["sha256"],
                            "uploaded_at": now,
                        }
                    save_state(args.state, state)
                    log("package #%s uploaded (%d files): %s" % (result.get("package_id"), len(ready), result.get("message", "")))
                else:
                    log("upload rejected: %s" % result.get("error"))
        except urllib.error.HTTPError as error:
            body = ""
            try:
                body = error.read().decode("utf-8", errors="replace")
            except Exception:
                pass
            log("server returned HTTP %s: %s" % (error.code, body))
        except urllib.error.URLError as error:
            log("network error (will retry): %s" % error)
        except KeyboardInterrupt:
            log("stopped by user.")
            return 0
        except Exception as error:  # keep the agent alive
            log("unexpected error (will retry): %r" % error)

        if args.once:
            return 0
        time.sleep(max(2, args.interval))


if __name__ == "__main__":
    sys.exit(main())
