#!/usr/bin/env python3
"""Toggle visible desktop autostart for the Reflection farm agent."""

from __future__ import annotations

import argparse
import os
import shlex
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence

APP_ID = "reflection-farm-agent"
APP_NAME = "Reflection Farm Agent"
TERMINAL_COMMANDS = (
    ("x-terminal-emulator", '-T "$TITLE" -e "$RUN_SCRIPT"'),
    ("gnome-terminal", '--title="$TITLE" -- "$RUN_SCRIPT"'),
    ("konsole", '--hold -p tabtitle="$TITLE" -e "$RUN_SCRIPT"'),
    ("xfce4-terminal", '--title="$TITLE" -e "$RUN_SCRIPT"'),
    ("mate-terminal", '--title="$TITLE" -e "$RUN_SCRIPT"'),
    ("lxterminal", '--title="$TITLE" -e "$RUN_SCRIPT"'),
    ("xterm", '-T "$TITLE" -e "$RUN_SCRIPT"'),
)


@dataclass(frozen=True)
class AutostartPaths:
    """All generated files for the desktop autostart integration."""

    repo_dir: Path
    autostart_file: Path
    helper_dir: Path
    run_script: Path
    terminal_script: Path
    legacy_run_script: Path
    legacy_terminal_script: Path

    @property
    def reflection_script(self) -> Path:
        return self.repo_dir / "Reflection.py"


def xdg_config_home() -> Path:
    configured = os.environ.get("XDG_CONFIG_HOME")
    return Path(configured).expanduser() if configured else Path.home() / ".config"


def xdg_data_home() -> Path:
    configured = os.environ.get("XDG_DATA_HOME")
    return Path(configured).expanduser() if configured else Path.home() / ".local" / "share"


def default_paths(repo_dir: Path | None = None) -> AutostartPaths:
    resolved_repo_dir = (repo_dir or Path(__file__).resolve().parent).resolve()
    helper_dir = xdg_data_home() / "reflection" / "autostart"
    legacy_bin_dir = Path.home() / ".local" / "bin"
    return AutostartPaths(
        repo_dir=resolved_repo_dir,
        autostart_file=xdg_config_home() / "autostart" / f"{APP_ID}.desktop",
        helper_dir=helper_dir,
        run_script=helper_dir / f"{APP_ID}-run.sh",
        terminal_script=helper_dir / f"{APP_ID}-terminal.sh",
        legacy_run_script=legacy_bin_dir / f"{APP_ID}-run.sh",
        legacy_terminal_script=legacy_bin_dir / f"{APP_ID}-terminal.sh",
    )


def desktop_exec_quote(path: Path) -> str:
    """Quote an Exec path for a desktop-entry file."""
    value = str(path)
    escaped = (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("`", "\\`")
        .replace("$", "\\$")
    )
    return f'"{escaped}"'


def write_executable(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_name(f".{path.name}.tmp")
    temporary_path.write_text(content, encoding="utf-8")
    temporary_path.chmod(0o700)
    temporary_path.replace(path)


def run_script_content(paths: AutostartPaths) -> str:
    repo_dir = shlex.quote(str(paths.repo_dir))
    reflection_script = shlex.quote(str(paths.reflection_script))
    return f"""#!/bin/sh
cd {repo_dir} || exit 1
python3 {reflection_script}
status=$?
printf '\nReflection stopped with exit code %s. Press Enter to close this terminal...' "$status"
read _
exit "$status"
"""


def terminal_script_content(paths: AutostartPaths) -> str:
    terminal_blocks = []
    for binary, arguments in TERMINAL_COMMANDS:
        condition = "if" if not terminal_blocks else "elif"
        terminal_blocks.append(
            f'{condition} command -v {shlex.quote(binary)} >/dev/null 2>&1; then\n'
            f'    exec {shlex.quote(binary)} {arguments}'
        )

    title = shlex.quote(APP_NAME)
    run_script = shlex.quote(str(paths.run_script))
    return f"""#!/bin/sh
TITLE={title}
RUN_SCRIPT={run_script}

{chr(10).join(terminal_blocks)}
else
    if command -v notify-send >/dev/null 2>&1; then
        notify-send "Reflection Farm Agent" "No supported terminal emulator was found. Install x-terminal-emulator, gnome-terminal, xfce4-terminal, mate-terminal, konsole, lxterminal, or xterm."
    fi
    echo "No supported terminal emulator was found." >&2
    echo "Cannot start Reflection in a visible terminal." >&2
    exit 127
fi
"""


def desktop_entry_content(paths: AutostartPaths) -> str:
    return f"""[Desktop Entry]
Type=Application
Name={APP_NAME}
Comment=Start the Reflection farm agent in a visible terminal window
Exec={desktop_exec_quote(paths.terminal_script)}
Terminal=false
X-GNOME-Autostart-enabled=true
"""


def enable_start_on_boot(paths: AutostartPaths | None = None) -> Path:
    resolved_paths = paths or default_paths()
    if not resolved_paths.reflection_script.is_file():
        raise FileNotFoundError(f"Reflection script not found: {resolved_paths.reflection_script}")

    write_executable(resolved_paths.run_script, run_script_content(resolved_paths))
    write_executable(resolved_paths.terminal_script, terminal_script_content(resolved_paths))
    resolved_paths.autostart_file.parent.mkdir(parents=True, exist_ok=True)
    resolved_paths.autostart_file.write_text(
        desktop_entry_content(resolved_paths),
        encoding="utf-8",
    )
    return resolved_paths.autostart_file


def generated_files(paths: AutostartPaths) -> tuple[Path, ...]:
    return (
        paths.autostart_file,
        paths.terminal_script,
        paths.run_script,
        paths.legacy_terminal_script,
        paths.legacy_run_script,
    )


def disable_start_on_boot(paths: AutostartPaths | None = None) -> list[Path]:
    resolved_paths = paths or default_paths()
    removed = []
    for path in generated_files(resolved_paths):
        if path.exists():
            path.unlink()
            removed.append(path)
    return removed


def is_enabled(paths: AutostartPaths | None = None) -> bool:
    resolved_paths = paths or default_paths()
    return resolved_paths.autostart_file.is_file()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Toggle desktop login autostart for Reflection.py in a visible terminal."
    )
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--enable", action="store_true", help="Enable start on desktop login.")
    group.add_argument("--disable", action="store_true", help="Disable start on desktop login.")
    group.add_argument("--status", action="store_true", help="Print current autostart status.")
    parser.add_argument(
        "--repo-dir",
        type=Path,
        default=None,
        help=(
            "Repository directory that contains Reflection.py. "
            "Defaults to this script's directory."
        ),
    )
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    paths = default_paths(args.repo_dir)

    if args.status:
        print("enabled" if is_enabled(paths) else "disabled")
        if is_enabled(paths):
            print(paths.autostart_file)
        return 0

    if args.disable:
        removed = disable_start_on_boot(paths)
        if removed:
            print("Disabled Reflection desktop autostart and removed:")
            for path in removed:
                print(f"- {path}")
        else:
            print("Reflection desktop autostart was already disabled.")
        return 0

    if args.enable or not is_enabled(paths):
        desktop_file = enable_start_on_boot(paths)
        print(f"Enabled Reflection desktop autostart: {desktop_file}")
        print("The agent will open in a terminal window on the next desktop login.")
        return 0

    removed = disable_start_on_boot(paths)
    print("Disabled Reflection desktop autostart and removed:")
    for path in removed:
        print(f"- {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
