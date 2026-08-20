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
WORKER_INSTALL=false

if [[ -f "$SCRIPT_DIR/cluster/reflection_config.json" || -f "$SCRIPT_DIR/cluster/reflection_config.local.json" ]]; then
    WORKER_INSTALL=true
fi

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

# Validate every downloaded entry point before replacing live code. A master-
# only installation does not need a desktop terminal or worker dependencies.
python3 -m py_compile \
    "$SOURCE_DIR/cluster/Reflection.py" \
    "$SOURCE_DIR/cluster/agent_state.py" \
    "$SOURCE_DIR/cluster/task_readiness.py" \
    "$SOURCE_DIR/cluster/task_registry.py" \
    "$SOURCE_DIR/cluster/task_runner.py" \
    "$SOURCE_DIR/cluster/task_log_viewer.py" \
    "$SOURCE_DIR/cluster/preflight.py" \
    "$SOURCE_DIR/cluster/run_setup.py" \
    "$SOURCE_DIR/cluster/toggle_start_on_boot.py"

if command -v php >/dev/null 2>&1; then
    while IFS= read -r php_file; do
        php -l "$php_file" >/dev/null
    done < <(find "$SOURCE_DIR" -maxdepth 2 -type f -name '*.php' -print | sort)
fi

LIVE_VENV="$SCRIPT_DIR/cluster/.venv"
if [[ "$WORKER_INSTALL" == true ]]; then
    # Resolve exact worker dependencies before replacing live code. The venv is
    # preserved across replacement, so dependency or preflight failures leave
    # the current application code in place.
    if [[ ! -x "$LIVE_VENV/bin/python" ]]; then
        if ! python3 -m venv "$LIVE_VENV"; then
            echo "Unable to create cluster/.venv. Install python3-venv and retry." >&2
            exit 1
        fi
    fi
    LIVE_PYTHON="$LIVE_VENV/bin/python"
    "$LIVE_PYTHON" -m pip install --disable-pip-version-check -r "$SOURCE_DIR/cluster/requirements.txt"
    "$LIVE_PYTHON" "$SOURCE_DIR/cluster/preflight.py" --skip-server
fi

# The Reflection application folder is disposable. Runtime/local files are not.
# Preserve those files outside the wipe, replace the whole app folder with the
# freshly cloned Git checkout, then restore the preserved paths.
python3 - "$SOURCE_DIR" "$SCRIPT_DIR" "$TEMP_DIR/preserved" "$TEMP_DIR/backup" <<'PY'
import shutil
import sys
from pathlib import Path

source = Path(sys.argv[1]).resolve()
target = Path(sys.argv[2]).resolve()
preserve_root = Path(sys.argv[3]).resolve()
backup_root = Path(sys.argv[4]).resolve()

preserve_paths = [
    Path("data"),
    Path("farm_settings.local.php"),
    Path("cluster/reflection_config.json"),
    Path("cluster/reflection_config.local.json"),
    Path("cluster/reflection_outbox.json"),
    Path("cluster/tasks_local"),
    Path("cluster/.venv"),
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


def clear_directory(directory: Path) -> None:
    for child in directory.iterdir():
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        else:
            child.unlink()


for relative in preserve_paths:
    existing = target / relative
    if existing.exists() or existing.is_symlink():
        copy_path(existing, preserve_root / relative)

# Keep a complete rollback copy until the replacement succeeds.
for child in target.iterdir():
    if child.name not in ignored_names:
        copy_path(child, backup_root / child.name)

try:
    clear_directory(target)
    for child in source.iterdir():
        if child.name not in ignored_names:
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
except BaseException:
    clear_directory(target)
    for child in backup_root.iterdir():
        copy_path(child, target / child.name)
    raise
PY

restore_backup() {
    python3 - "$SCRIPT_DIR" "$TEMP_DIR/backup" <<'PY'
import shutil
import sys
from pathlib import Path

target = Path(sys.argv[1]).resolve()
backup = Path(sys.argv[2]).resolve()
for child in list(target.iterdir()):
    if child.is_dir() and not child.is_symlink():
        shutil.rmtree(child)
    else:
        child.unlink()
for child in backup.iterdir():
    destination = target / child.name
    if child.is_dir() and not child.is_symlink():
        shutil.copytree(child, destination, symlinks=True)
    else:
        shutil.copy2(child, destination, follow_symlinks=False)
PY
}

# Validate the copied entry points too. Restore the complete previous tree if
# the on-disk result does not validate exactly as the downloaded source did.
POST_PYTHON=python3
if [[ "$WORKER_INSTALL" == true ]]; then
    POST_PYTHON="$SCRIPT_DIR/cluster/.venv/bin/python"
fi
if ! "$POST_PYTHON" -m py_compile \
    "$SCRIPT_DIR/cluster/Reflection.py" \
    "$SCRIPT_DIR/cluster/agent_state.py" \
    "$SCRIPT_DIR/cluster/task_readiness.py" \
    "$SCRIPT_DIR/cluster/task_registry.py" \
    "$SCRIPT_DIR/cluster/task_runner.py" \
    "$SCRIPT_DIR/cluster/task_log_viewer.py" \
    "$SCRIPT_DIR/cluster/preflight.py" \
    "$SCRIPT_DIR/cluster/run_setup.py" \
    "$SCRIPT_DIR/cluster/toggle_start_on_boot.py"; then
    echo "Updated Python files failed validation. Restoring the previous installation." >&2
    restore_backup
    exit 1
fi

if command -v php >/dev/null 2>&1; then
    php_validation_failed=false
    while IFS= read -r php_file; do
        if ! php -l "$php_file" >/dev/null; then
            php_validation_failed=true
            break
        fi
    done < <(find "$SCRIPT_DIR" -maxdepth 2 -type f -name '*.php' -print | sort)
    if [[ "$php_validation_failed" == true ]]; then
        echo "Updated PHP files failed validation. Restoring the previous installation." >&2
        restore_backup
        exit 1
    fi
fi

new_commit="$(git -C "$SCRIPT_DIR" rev-parse HEAD)"
printf '%s\n' "$new_commit" > "$SCRIPT_DIR/.reflection_commit"
chmod 0660 "$SCRIPT_DIR/.reflection_commit" 2>/dev/null || true
new_version="${new_commit:0:12}"
echo "Reflection updated successfully to Git version ${new_version}."
echo "Protected local paths kept: data/, farm_settings.local.php, worker config/outbox, tasks_local/, cluster/.venv/, .env"
echo "Farm workers started by update_worker or version-follow self-update will reboot after the update completes."
