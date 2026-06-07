#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

usage() {
    cat <<USAGE
Usage: ./uninstall.sh [options]

Removes the Reflection worker desktop autostart files for this user.

Options:
  --remove-config   Also delete reflection_config.json after disabling autostart.
  --status          Show autostart status and exit.
  -h, --help        Show this help.
USAGE
}

remove_config=false
status_only=false

while (($#)); do
    case "$1" in
        --remove-config)
            remove_config=true
            ;;
        --status)
            status_only=true
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    echo "Do not run this uninstaller with sudo/root." >&2
    echo "Desktop autostart belongs to the user that owns the desktop session." >&2
    exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required but was not found." >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/toggle_start_on_boot.py" ]]; then
    echo "toggle_start_on_boot.py was not found in: $SCRIPT_DIR" >&2
    exit 1
fi

if [[ "$status_only" == true ]]; then
    python3 "$SCRIPT_DIR/toggle_start_on_boot.py" --status --repo-dir "$SCRIPT_DIR"
    exit $?
fi

python3 "$SCRIPT_DIR/toggle_start_on_boot.py" --disable --repo-dir "$SCRIPT_DIR"

if [[ "$remove_config" == true ]]; then
    rm -f "$SCRIPT_DIR/reflection_config.json"
    echo "Removed reflection_config.json."
else
    echo "Kept reflection_config.json. Use ./uninstall.sh --remove-config to remove it too."
fi

echo "Uninstalled Reflection desktop autostart for this user."
