#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME="Reflection Farm Agent"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

usage() {
    cat <<USAGE
Usage: ./install.sh [options]

Installs the Reflection worker so it starts in a visible terminal window when
this user's graphical desktop session starts.

Options:
  --configure       Re-run the interactive reflection_config.json setup.
  --skip-config     Do not create or update reflection_config.json.
  --accept-defaults Write default/current config values without prompting.
  --status          Show autostart status and exit.
  -h, --help        Show this help.

Important:
  A visible terminal requires a logged-in desktop session. For a farm PC that
  should start at power-on, enable desktop auto-login for this user in the OS.
USAGE
}

configure=false
skip_config=false
accept_defaults=false
status_only=false

while (($#)); do
    case "$1" in
        --configure)
            configure=true
            ;;
        --skip-config)
            skip_config=true
            ;;
        --accept-defaults)
            accept_defaults=true
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
    echo "Do not run this installer with sudo/root." >&2
    echo "Desktop autostart belongs to the user that will run the visible terminal." >&2
    exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required but was not found." >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/Reflection.py" ]]; then
    echo "Reflection.py was not found in: $SCRIPT_DIR" >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/toggle_start_on_boot.py" ]]; then
    echo "toggle_start_on_boot.py was not found in: $SCRIPT_DIR" >&2
    exit 1
fi

find_terminal() {
    local candidate
    for candidate in x-terminal-emulator gnome-terminal konsole xfce4-terminal mate-terminal lxterminal xterm; do
        if command -v "$candidate" >/dev/null 2>&1; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

if [[ "$status_only" == true ]]; then
    python3 "$SCRIPT_DIR/toggle_start_on_boot.py" --status --repo-dir "$SCRIPT_DIR"
    exit $?
fi

terminal="$(find_terminal || true)"
if [[ -z "$terminal" ]]; then
    echo "No supported terminal emulator was found." >&2
    echo "Install one of: x-terminal-emulator, gnome-terminal, konsole, xfce4-terminal, mate-terminal, lxterminal, xterm." >&2
    echo "The worker can run without a terminal, but that would violate the visible-terminal requirement." >&2
    exit 1
fi

echo "Installing $APP_NAME from: $SCRIPT_DIR"
echo "Visible terminal command found: $terminal"

python3 -m py_compile \
    "$SCRIPT_DIR/Reflection.py" \
    "$SCRIPT_DIR/task_runner.py" \
    "$SCRIPT_DIR/run_setup.py" \
    "$SCRIPT_DIR/toggle_start_on_boot.py"

if [[ "$skip_config" != true ]]; then
    if [[ ! -f "$SCRIPT_DIR/reflection_config.json" || "$configure" == true || "$accept_defaults" == true ]]; then
        setup_args=("$SCRIPT_DIR/run_setup.py" --config-only)
        if [[ "$accept_defaults" == true ]]; then
            setup_args+=(--accept-defaults)
        fi
        python3 "${setup_args[@]}"
    else
        echo "Keeping existing reflection_config.json. Use ./install.sh --configure to edit it."
    fi
fi

python3 "$SCRIPT_DIR/toggle_start_on_boot.py" --enable --repo-dir "$SCRIPT_DIR"

echo
echo "Installed. $APP_NAME will open in a visible terminal at the next desktop login."
echo "If this farm PC should start at power-on, enable OS auto-login for this user."
echo "To start it manually right now, run:"
echo "  cd '$SCRIPT_DIR' && python3 Reflection.py"
echo "To remove autostart, run:"
echo "  cd '$SCRIPT_DIR' && ./uninstall.sh"
