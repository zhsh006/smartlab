FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    FIJI_APP_DIR=/opt/Fiji \
    SMARTLAB_IMAGEJ_PORT=8765

COPY .local/imagej/cache/fiji-latest-linux64-jdk.zip /tmp/fiji.zip

RUN python - <<'PY'
import os
import pathlib
import zipfile

archive_path = pathlib.Path("/tmp/fiji.zip")
with zipfile.ZipFile(archive_path) as archive:
    archive.extractall("/opt")
archive_path.unlink()
root = pathlib.Path("/opt")
candidates = [root / "Fiji", root / "Fiji.app"]
app_dir = next((candidate for candidate in candidates if candidate.exists()), None)
if app_dir is None:
    for child in root.iterdir():
        if child.is_dir() and child.name.lower().startswith("fiji"):
            app_dir = child
            break
if app_dir is None:
    raise SystemExit("Could not locate extracted Fiji directory")

main_launcher = next((path for path in [app_dir / "fiji", app_dir / "ImageJ-linux64", app_dir / "fiji-linux-x64"] if path.exists()), None)
linux_launcher = next((path for path in [app_dir / "fiji-linux-x64", app_dir / "ImageJ-linux64"] if path.exists()), None)
if main_launcher is None or linux_launcher is None:
    raise SystemExit(f"Could not locate Fiji launchers under {app_dir}")

target_app_dir = root / "Fiji"
if app_dir != target_app_dir:
    if target_app_dir.exists():
        raise SystemExit("/opt/Fiji already exists unexpectedly")
    app_dir.rename(target_app_dir)
    app_dir = target_app_dir
    main_launcher = next((path for path in [app_dir / "fiji", app_dir / "ImageJ-linux64", app_dir / "fiji-linux-x64"] if path.exists()), None)
    linux_launcher = next((path for path in [app_dir / "fiji-linux-x64", app_dir / "ImageJ-linux64"] if path.exists()), None)

fiji_link = pathlib.Path("/usr/local/bin/fiji")
if fiji_link.exists() or fiji_link.is_symlink():
    fiji_link.unlink()
fiji_link.symlink_to(main_launcher)

linux_link = pathlib.Path("/usr/local/bin/fiji-linux-x64")
if linux_link.exists() or linux_link.is_symlink():
    linux_link.unlink()
linux_link.symlink_to(linux_launcher)

os.chmod(main_launcher, 0o755)
os.chmod(linux_launcher, 0o755)
PY

WORKDIR /opt/smartlab-imagej

COPY deploy/imagej-worker/app.py /opt/smartlab-imagej/app.py
COPY deploy/imagej-worker/macros/ /opt/smartlab-imagej/macros/

RUN mkdir -p /opt/Fiji/plugins/SmartLab /opt/Fiji/macros/SmartLab /work/data

EXPOSE 8765

CMD ["python", "/opt/smartlab-imagej/app.py"]
