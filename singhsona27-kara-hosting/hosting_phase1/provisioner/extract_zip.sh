#!/usr/bin/env bash
set -euo pipefail

SITE_DIR="${1:?site dir required}"
ZIP_REL="${2:?zip relative path required}"
CONTAINER="${3:?site container required}"

case "$ZIP_REL" in
  /*|*..*) echo "Unsafe ZIP path" >&2; exit 1;;
esac

ZIP_FILE="${SITE_DIR}/${ZIP_REL}"
if [ ! -f "$ZIP_FILE" ]; then
  echo "ZIP file not found" >&2
  exit 1
fi

python3 - "$SITE_DIR" "$ZIP_FILE" <<'PY'
import os
import posixpath
import shutil
import sys
import zipfile
from pathlib import Path

site_dir = Path(sys.argv[1]).resolve()
zip_file = Path(sys.argv[2]).resolve()

def safe_name(raw):
    name = raw.replace("\\", "/")
    name = posixpath.normpath(name)
    if name in ("", "."):
        return None
    if name.startswith("/") or name.startswith("../") or "/../" in name:
        raise RuntimeError(f"Unsafe path inside ZIP: {raw}")
    return name

with zipfile.ZipFile(zip_file) as zf:
    for info in zf.infolist():
        name = safe_name(info.filename)
        if not name:
            continue

        target = (site_dir / name).resolve()
        if not str(target).startswith(str(site_dir) + os.sep):
            raise RuntimeError(f"Unsafe path inside ZIP: {info.filename}")

        if info.is_dir() or info.filename.endswith(("/", "\\")):
            target.mkdir(parents=True, exist_ok=True)
            continue

        target.parent.mkdir(parents=True, exist_ok=True)
        with zf.open(info) as src, target.open("wb") as dst:
            shutil.copyfileobj(src, dst)
PY

chown -R 1000:1000 "$SITE_DIR" >/dev/null 2>&1 || true
find "$SITE_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$SITE_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
docker restart "$CONTAINER" >/dev/null

printf '{"success":true,"zip":"%s"}' "$ZIP_REL"
