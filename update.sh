#!/usr/bin/env bash
set -Eeuo pipefail

# Reflection's upstream is intentionally fixed so an update is one command:
#   ./update.sh
# Workers can also follow the exact master commit:
#   ./update.sh --commit <git-commit>
GITHUB_REPOSITORY="andr8076/Reflection"
GITHUB_BRANCH="main"
GIT_URL="https://github.com/${GITHUB_REPOSITORY}.git"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/reflection-update.XXXXXX")"
SOURCE_DIR="$TEMP_DIR/source"
TARGET_COMMIT=""

cleanup() {
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT

usage() {
    cat <<'EOF'
Usage: ./update.sh [--commit <git-commit>]

Without --commit, the updater installs the latest configured branch.
With --commit, the updater installs that exact commit and fails safely if it
cannot be fetched or checked out.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --commit)
            if [[ $# -lt 2 || -z "$2" ]]; then
                echo "--commit requires a value." >&2
                usage >&2
                exit 2
            fi
            TARGET_COMMIT="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

for command in git python3; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing required command: $command" >&2
        exit 1
    fi
done

if [[ ! -f "$SCRIPT_DIR/config.php" || ! -f "$SCRIPT_DIR/cluster/Reflection.py" ]]; then
    echo "Run this script from the Reflection project directory." >&2
    exit 1
fi

if [[ -n "$TARGET_COMMIT" ]]; then
    echo "Fetching Reflection commit ${TARGET_COMMIT} from ${GITHUB_REPOSITORY}..."
    git init "$SOURCE_DIR" >/dev/null
    git -C "$SOURCE_DIR" remote add origin "$GIT_URL"

    if ! git -C "$SOURCE_DIR" fetch --depth 1 origin "$TARGET_COMMIT"; then
        echo "Direct commit fetch failed. Trying ${GITHUB_BRANCH} branch history..." >&2
        rm -rf -- "$SOURCE_DIR"
        if ! git clone --depth 100 --branch "$GITHUB_BRANCH" "$GIT_URL" "$SOURCE_DIR"; then
            echo "Unable to fetch Reflection update from GitHub." >&2
            exit 1
        fi
    fi

    if ! git -C "$SOURCE_DIR" checkout --detach --force "$TARGET_COMMIT"; then
        echo "Unable to check out requested Reflection commit: ${TARGET_COMMIT}" >&2
        echo "Leaving the current installation unchanged." >&2
        exit 1
    fi
else
    echo "Cloning the latest ${GITHUB_REPOSITORY} ${GITHUB_BRANCH} branch..."
    if ! git clone --depth 1 --branch "$GITHUB_BRANCH" "$GIT_URL" "$SOURCE_DIR"; then
        echo >&2
        echo "Unable to clone the Reflection update from GitHub." >&2
        echo "Check your network connection and try ./update.sh again." >&2
        exit 1
    fi
fi

if [[ ! -d "$SOURCE_DIR/.git" || ! -f "$SOURCE_DIR/config.php" || ! -f "$SOURCE_DIR/cluster/Reflection.py" ]]; then
    echo "Downloaded checkout does not look like a Reflection Git checkout." >&2
    exit 1
fi

if [[ -n "$TARGET_COMMIT" ]]; then
    actual_commit="$(git -C "$SOURCE_DIR" rev-parse HEAD)"
    if [[ "$actual_commit" != "$TARGET_COMMIT"* && "${actual_commit:0:${#TARGET_COMMIT}}" != "$TARGET_COMMIT" ]]; then
        echo "Fetched commit ${actual_commit} does not match requested commit ${TARGET_COMMIT}." >&2
        echo "Leaving the current installation unchanged." >&2
        exit 1
    fi
fi

# Validate the downloaded worker entry points before replacing the live files.
python3 -m py_compile \
    "$SOURCE_DIR/cluster/Reflection.py" \
    "$SOURCE_DIR/cluster/task_registry.py" \
    "$SOURCE_DIR/cluster/task_runner.py" \
    "$SOURCE_DIR/cluster/run_setup.py" \
    "$SOURCE_DIR/cluster/toggle_start_on_boot.py"

# The Reflection application folder is disposable. Runtime/local files are not.
# Preserve those files outside the wipe, replace the whole app folder with the
# freshly cloned Git checkout, then restore the preserved paths.
python3 - "$SOURCE_DIR" "$SCRIPT_DIR" "$TEMP_DIR/preserved" <<'PY'
import shutil
import sys
from pathlib import Path

source = Path(sys.argv[1]).resolve()
target = Path(sys.argv[2]).resolve()
preserve_root = Path(sys.argv[3]).resolve()

preserve_paths = [
    Path("data"),
    Path("farm_settings.local.php"),
    Path("cluster/reflection_config.json"),
    Path("cluster/reflection_config.local.json"),
    Path(".env"),
]
ignored_names = {"__MACOSX", ".DS_Store"}


def copy_path(src: Path, dst: Path) -> None:
    if not src.exists() and not src.is_symlink():
        return
    dst.parent.mkdir(parents=True, exist_ok=True)
    if src.is_dir() and not src.is_symlink():
        shutil.copytree(
            src,
            dst,
            symlinks=True,
            ignore=shutil.ignore_patterns("__MACOSX", ".DS_Store"),
            dirs_exist_ok=True,
        )
    else:
        shutil.copy2(src, dst, follow_symlinks=False)


for relative in preserve_paths:
    existing = target / relative
    if existing.exists() or existing.is_symlink():
        copy_path(existing, preserve_root / relative)

for child in target.iterdir():
    if child.name in ignored_names:
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        else:
            child.unlink()
        continue
    if child.is_dir() and not child.is_symlink():
        shutil.rmtree(child)
    else:
        child.unlink()

for child in source.iterdir():
    if child.name in ignored_names:
        continue
    copy_path(child, target / child.name)

for relative in preserve_paths:
    preserved = preserve_root / relative
    if preserved.exists() or preserved.is_symlink():
        destination = target / relative
        if destination.exists() or destination.is_symlink():
            if destination.is_dir() and not destination.is_symlink():
                shutil.rmtree(destination)
            else:
                destination.unlink()
        copy_path(preserved, destination)
PY

# Validate the copied worker entry points too, so failed updates are obvious.
python3 -m py_compile \
    "$SCRIPT_DIR/cluster/Reflection.py" \
    "$SCRIPT_DIR/cluster/task_registry.py" \
    "$SCRIPT_DIR/cluster/task_runner.py" \
    "$SCRIPT_DIR/cluster/run_setup.py" \
    "$SCRIPT_DIR/cluster/toggle_start_on_boot.py"

new_version="$(git -C "$SCRIPT_DIR" rev-parse --short=12 HEAD)"
echo "Reflection updated successfully to Git version ${new_version}."
echo "Protected local paths kept: data/, farm_settings.local.php, cluster/reflection_config.json, cluster/reflection_config.local.json, .env"
echo "Farm workers started by update_worker or version-follow self-update will reboot after the update completes."
