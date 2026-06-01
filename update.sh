#!/usr/bin/env bash
set -Eeuo pipefail

# Reflection's upstream is intentionally fixed so an update is one command:
#   ./update.sh
GITHUB_REPOSITORY="andr8076/Reflection"
ARCHIVE_URL="https://api.github.com/repos/${GITHUB_REPOSITORY}/tarball"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
TOKEN_FILE="$SCRIPT_DIR/.reflection_github_token"
TOKEN="${REFLECTION_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
if [[ -z "$TOKEN" && -f "$TOKEN_FILE" ]]; then
    IFS= read -r TOKEN < "$TOKEN_FILE" || true
fi
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
    --header "Accept: application/vnd.github+json"
    --header "X-GitHub-Api-Version: 2026-03-10"
    --output "$ARCHIVE_PATH"
)
if [[ -n "$TOKEN" ]]; then
    curl_args+=(--header "Authorization: Bearer $TOKEN")
fi

echo "Downloading the latest ${GITHUB_REPOSITORY} default branch..."
if ! curl "${curl_args[@]}" "$ARCHIVE_URL"; then
    echo >&2
    echo "Unable to download the Reflection update from GitHub." >&2
    echo "If the repository is private, save a read-only GitHub token once:" >&2
    echo "  printf '%s\n' 'github-token' > '$TOKEN_FILE'" >&2
    echo "  chmod 600 '$TOKEN_FILE'" >&2
    echo "Then run ./update.sh again." >&2
    exit 1
fi

mkdir -p "$EXTRACT_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$EXTRACT_DIR"
SOURCE_DIR="$(find "$EXTRACT_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "$SOURCE_DIR" || ! -f "$SOURCE_DIR/config.php" || ! -f "$SOURCE_DIR/cluster/Reflection.py" ]]; then
    echo "Downloaded archive does not look like a Reflection checkout." >&2
    exit 1
fi

# Copy the tracked application files over the current checkout. Local runtime
# files are not present in the GitHub archive, so data/, farm_settings.local.php,
# and cluster/reflection_config.json remain untouched.
python3 - "$SOURCE_DIR" "$SCRIPT_DIR" <<'PY'
import shutil
import sys
from pathlib import Path

source = Path(sys.argv[1])
target = Path(sys.argv[2])
for child in source.iterdir():
    destination = target / child.name
    if child.is_dir():
        shutil.copytree(child, destination, dirs_exist_ok=True)
    else:
        shutil.copy2(child, destination)
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
