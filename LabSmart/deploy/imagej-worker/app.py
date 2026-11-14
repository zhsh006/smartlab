from __future__ import annotations

import json
import os
import pathlib
import tempfile
import subprocess
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
FIJI_APP_DIR = pathlib.Path(os.environ.get("FIJI_APP_DIR", "/opt/Fiji"))
FIJI_BINARY = pathlib.Path("/usr/local/bin/fiji")
SMARTLAB_ROOT = pathlib.Path("/opt/smartlab-imagej")
PLUGIN_DIR = FIJI_APP_DIR / "plugins" / "SmartLab"
MACRO_DIR = FIJI_APP_DIR / "macros" / "SmartLab"
WORK_DIR = pathlib.Path("/work/data")


def resolve_macro_path(name: str) -> pathlib.Path:
    mounted_macro = MACRO_DIR / name
    if mounted_macro.exists():
        return mounted_macro
    bundled_macro = SMARTLAB_ROOT / "macros" / name
    return bundled_macro


def list_relative_files(root: pathlib.Path) -> list[str]:
    if not root.exists():
        return []
    return sorted(
        str(path.relative_to(root))
        for path in root.rglob("*")
        if path.is_file()
    )


def run_imagej_ping() -> dict[str, Any]:
    macro_path = resolve_macro_path("ping.ijm")
    completed = subprocess.run(
        [str(FIJI_BINARY), "--headless", "--console", "-macro", str(macro_path)],
        capture_output=True,
        text=True,
        timeout=30,
        check=False,
    )
    stdout = completed.stdout.strip()
    stderr = completed.stderr.strip()
    return {
        "ok": completed.returncode == 0 and "SMARTLAB_IMAGEJ_OK" in stdout,
        "returncode": completed.returncode,
        "stdout": stdout,
        "stderr": stderr,
    }


def run_ihc_analysis(payload: dict[str, Any]) -> dict[str, Any]:
    image_base64 = str(payload.get("image_base64", ""))
    rois = payload.get("rois", [])
    mode = str(payload.get("mode", "deconv"))
    threshold = float(payload.get("threshold", 32))
    he_threshold = float(payload.get("he_threshold", 24))

    if not image_base64:
        raise ValueError("Missing image_base64")
    if not isinstance(rois, list) or not rois:
        raise ValueError("Missing rois")

    if "," in image_base64:
        image_base64 = image_base64.split(",", 1)[1]

    image_bytes = base64.b64decode(image_base64)
    with tempfile.NamedTemporaryFile(dir=WORK_DIR, suffix=".png", delete=False) as image_file:
        image_file.write(image_bytes)
        image_path = pathlib.Path(image_file.name)

    try:
        roi_parts: list[str] = []
        for roi in rois:
            label = str(roi.get("label", "ROI")).replace(",", "_").replace(";", "_").replace("|", "_")
            roi_parts.append(
                ",".join(
                    [
                        label,
                        str(int(roi.get("x", 0))),
                        str(int(roi.get("y", 0))),
                        str(int(roi.get("w", 0))),
                        str(int(roi.get("h", 0))),
                    ]
                )
            )

        macro_arg = "|".join(
            [
                str(image_path),
                mode,
                str(threshold),
                str(he_threshold),
                ";".join(roi_parts),
            ]
        )

        completed = subprocess.run(
            [
                str(FIJI_BINARY),
                "--headless",
                "--console",
                "-macro",
                str(resolve_macro_path("analyze_ihc.ijm")),
                macro_arg,
            ],
            capture_output=True,
            text=True,
            timeout=120,
            check=False,
        )

        stdout = completed.stdout.strip()
        stderr = completed.stderr.strip()
        lines = [line.strip() for line in stdout.splitlines() if line.strip()]
        results: list[dict[str, Any]] = []
        image_info: dict[str, Any] = {}

        for line in lines:
            parts = line.split("\t")
            if not parts:
                continue
            if parts[0] == "IMAGE" and len(parts) >= 3:
                image_info = {
                    "width": int(float(parts[1])),
                    "height": int(float(parts[2])),
                }
            if parts[0] == "ROI" and len(parts) >= 8:
                results.append(
                    {
                        "label": parts[1],
                        "positive_ratio": float(parts[2]),
                        "intensity_score": int(float(parts[3])),
                        "h_score": float(parts[4]),
                        "irs": int(float(parts[5])),
                        "mean_signal": float(parts[6]),
                        "tissue_ratio": float(parts[7]),
                    }
                )

        macro_error = "Macro Error" in stderr or "Exception" in stderr
        missing_results = len(results) == 0 and len(rois) > 0
        ok = completed.returncode == 0 and not macro_error and not missing_results

        return {
            "ok": ok,
            "returncode": completed.returncode,
            "image": image_info,
            "results": results,
            "stdout": stdout,
            "stderr": stderr,
            "engine": "fiji-macro-ihc-v1",
            "error": None if ok else (
                "ImageJ macro returned no ROI results." if missing_results and not macro_error
                else stderr.strip() or "ImageJ macro failed."
            ),
        }
    finally:
        image_path.unlink(missing_ok=True)


class Handler(BaseHTTPRequestHandler):
    def _send_json(self, payload: dict[str, Any], status_code: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=True).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/health":
            self._send_json(
                {
                    "status": "ok",
                    "service": "smartlab-imagej-worker",
                    "fiji_binary": str(FIJI_BINARY),
                    "fiji_app_dir": str(FIJI_APP_DIR),
                }
            )
            return
        if self.path == "/api/v1/system/info":
            self._send_json(
                {
                    "service": "smartlab-imagej-worker",
                    "fiji_binary_exists": FIJI_BINARY.exists(),
                    "fiji_app_dir_exists": FIJI_APP_DIR.exists(),
                    "plugin_dir": str(PLUGIN_DIR),
                    "macro_dir": str(MACRO_DIR),
                    "work_dir": str(WORK_DIR),
                    "plugins": list_relative_files(PLUGIN_DIR),
                    "macros": list_relative_files(MACRO_DIR),
                }
            )
            return
        if self.path == "/api/v1/system/ping-imagej":
            result = run_imagej_ping()
            self._send_json(result, 200 if result["ok"] else 500)
            return
        self._send_json({"error": "not_found", "path": self.path}, 404)

    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/api/v1/analyze/ihc":
            self._send_json({"error": "not_found", "path": self.path}, 404)
            return

        content_length = int(self.headers.get("Content-Length", "0") or "0")
        raw_body = self.rfile.read(content_length)
        try:
            payload = json.loads(raw_body.decode("utf-8"))
            result = run_ihc_analysis(payload)
            self._send_json(result, 200 if result.get("ok") else 500)
        except Exception as exc:  # noqa: BLE001
            self._send_json({"ok": False, "error": str(exc)}, 500)

    def log_message(self, fmt: str, *args: Any) -> None:
        print(f"{self.address_string()} - {fmt % args}")


if __name__ == "__main__":
    port = int(os.environ.get("SMARTLAB_IMAGEJ_PORT", "8765"))
    server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
    server.serve_forever()
