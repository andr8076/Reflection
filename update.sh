#!/usr/bin/env bash
set -Eeuo pipefail

# Reflection's upstream is intentionally fixed so an update is one command:
#   ./update.sh
GITHUB_REPOSITORY="andr8076/Reflection"
ARCHIVE_URL="https://github.com/${GITHUB_REPOSITORY}/archive/refs/heads/main.tar.gz"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST_FILE="$SCRIPT_DIR/.reflection_managed_files"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/reflection-update.XXXXXX")"
ARCHIVE_PATH="$TEMP_DIR/reflection.tar.gz"
EXTRACT_DIR="$TEMP_DIR/extracted"

cleanup() {
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT

for command in curl tar python3; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing required command: $command" >&2
        exit 1
    fi
done

if [[ ! -f "$SCRIPT_DIR/config.php" || ! -f "$SCRIPT_DIR/cluster/Reflection.py" ]]; then
    echo "Run this script from the Reflection project directory." >&2
    exit 1
fi

curl_args=(
    --fail
    --location
    --silent
    --show-error
    --output "$ARCHIVE_PATH"
)
echo "Downloading the latest ${GITHUB_REPOSITORY} main branch..."
if ! curl "${curl_args[@]}" "$ARCHIVE_URL"; then
    echo >&2
    echo "Unable to download the Reflection update from GitHub." >&2
    echo "Check your network connection and try ./update.sh again." >&2
    exit 1
fi

mkdir -p "$EXTRACT_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$EXTRACT_DIR"
SOURCE_DIR="$(find "$EXTRACT_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "$SOURCE_DIR" || ! -f "$SOURCE_DIR/config.php" || ! -f "$SOURCE_DIR/cluster/Reflection.py" ]]; then
    echo "Downloaded archive does not look like a Reflection checkout." >&2
    exit 1
fi

# Copy the downloaded application files over the current checkout. Keep a small
# local manifest so later updates can remove files that disappeared upstream
# without deleting unrelated local files or runtime state.
python3 - "$SOURCE_DIR" "$SCRIPT_DIR" "$MANIFEST_FILE" <<'PY'
import shutil
import sys
from pathlib import Path

source = Path(sys.argv[1])
target = Path(sys.argv[2])
manifest_path = Path(sys.argv[3])
files = sorted(path.relative_to(source) for path in source.rglob("*") if path.is_file())
current = {str(path) for path in files}
if manifest_path.is_file():
    previous = {line for line in manifest_path.read_text(encoding="utf-8").splitlines() if line}
    for relative in sorted(previous - current, reverse=True):
        stale = target / relative
        if stale.is_file() or stale.is_symlink():
            stale.unlink()

for relative in files:
    source_file = source / relative
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source_file, destination)

manifest_path.write_text("".join(f"{relative}\n" for relative in files), encoding="utf-8")
PY

# Validate the worker entry points after copying so a bad archive is obvious.
python3 -m py_compile \
    "$SCRIPT_DIR/cluster/Reflection.py" \
    "$SCRIPT_DIR/cluster/task_registry.py" \
    "$SCRIPT_DIR/cluster/task_runner.py" \
    "$SCRIPT_DIR/cluster/run_setup.py" \
    "$SCRIPT_DIR/cluster/toggle_start_on_boot.py"

echo "Reflection updated successfully."
echo "Restart running workers to load the updated files."
