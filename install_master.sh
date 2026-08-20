#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
install_cron=false
check_only=false

usage() {
    cat <<'USAGE'
Usage: ./install_master.sh [--install-cron] [--check]

Validates the PHP master, initializes its JSON data directory, and prints the
one-per-minute master tick schedule. --install-cron adds that schedule to the
current user's crontab. --check performs validation without changing crontab.
USAGE
}

while (($#)); do
    case "$1" in
        --install-cron) install_cron=true ;;
        --check) check_only=true ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "PHP CLI is required but was not found: $PHP_BIN" >&2
    exit 1
fi

for extension in json; do
    if ! "$PHP_BIN" -m | grep -Fxq "$extension"; then
        echo "Required PHP extension is missing: $extension" >&2
        exit 1
    fi
done

while IFS= read -r php_file; do
    "$PHP_BIN" -l "$php_file" >/dev/null
done < <(find "$SCRIPT_DIR" -maxdepth 1 -type f -name '*.php' -print | sort)

DATA_DIR="$SCRIPT_DIR/data"
if [[ "$check_only" != true ]]; then
    mkdir -p "$DATA_DIR"
fi
if [[ ! -d "$DATA_DIR" || ! -w "$DATA_DIR" ]]; then
    echo "Master JSON data directory is not writable: $DATA_DIR" >&2
    exit 1
fi

PHP_PATH="$(command -v "$PHP_BIN")"
printf -v PHP_PATH_QUOTED '%q' "$PHP_PATH"
printf -v TICK_PATH_QUOTED '%q' "$SCRIPT_DIR/automation_tick.php"
printf -v LOG_PATH_QUOTED '%q' "$DATA_DIR/master_tick.log"
TICK_COMMAND="$PHP_PATH_QUOTED $TICK_PATH_QUOTED"
CRON_MARKER="# Reflection master tick"
CRON_LINE="* * * * * $TICK_COMMAND >> $LOG_PATH_QUOTED 2>&1"

if [[ "$install_cron" == true ]]; then
    if ! command -v crontab >/dev/null 2>&1; then
        echo "crontab is required for --install-cron." >&2
        exit 1
    fi
    existing_cron="$(crontab -l 2>/dev/null || true)"
    filtered_cron="$(printf '%s\n' "$existing_cron" | grep -Fv "$CRON_MARKER" | grep -Fv "$SCRIPT_DIR/automation_tick.php" || true)"
    {
        printf '%s\n' "$filtered_cron"
        printf '%s\n%s\n' "$CRON_MARKER" "$CRON_LINE"
    } | crontab -
    echo "Installed the one-per-minute Reflection master tick."
else
    echo "Master validation passed. Schedule this once per minute:"
    echo "$CRON_LINE"
fi

echo "Open system_checks.php after the first tick to verify master and worker readiness."
