"""Worker task and runtime readiness checks used by setup and scheduling."""

from __future__ import annotations

import importlib.util
import os
import platform
import shutil
import subprocess
from typing import Any, Mapping


TERMINAL_BINARIES = (
    "x-terminal-emulator",
    "gnome-terminal",
    "konsole",
    "xfce4-terminal",
    "mate-terminal",
    "lxterminal",
    "xterm",
)


def available_terminal() -> str | None:
    """Return the first supported visible terminal binary on PATH."""
    if platform.system() == "Linux" and not (
        os.environ.get("DISPLAY") or os.environ.get("WAYLAND_DISPLAY")
    ):
        return None
    for name in TERMINAL_BINARIES:
        path = shutil.which(name)
        if path:
            return path
    return None


def supported_transfer_schemes() -> list[str]:
    """Return transfer protocols this worker can execute locally."""
    schemes = ["ftp", "ftps"]
    if importlib.util.find_spec("paramiko") is not None:
        schemes.append("sftp")
    return schemes


def _ffmpeg_encoder_available(name: str) -> bool:
    if shutil.which("ffmpeg") is None:
        return False
    try:
        result = subprocess.run(
            ["ffmpeg", "-hide_banner", "-encoders"],
            check=False,
            capture_output=True,
            text=True,
            timeout=15,
        )
    except (OSError, subprocess.SubprocessError):
        return False
    return name in (result.stdout or "")


def task_readiness(registry: Mapping[str, Any]) -> dict[str, dict[str, Any]]:
    """Evaluate declarative task requirements without running task installers."""
    readiness: dict[str, dict[str, Any]] = {}
    terminal = available_terminal()
    for name, definition in sorted(registry.items()):
        spec = getattr(definition, "spec", {})
        spec = spec if isinstance(spec, dict) else {}
        requirements = spec.get("requirements")
        requirements = requirements if isinstance(requirements, dict) else {}
        reasons: list[str] = []

        if spec.get("production_ready") is False:
            reasons.append(str(spec.get("unavailable_reason") or "Task is marked as an example/placeholder."))
        if terminal is None:
            reasons.append("No supported visible terminal emulator is available.")

        for command in requirements.get("commands", []):
            command = str(command).strip()
            if command and shutil.which(command) is None:
                reasons.append(f"Required command is missing: {command}")

        for module in requirements.get("python_modules", []):
            module = str(module).strip()
            if module and importlib.util.find_spec(module) is None:
                reasons.append(f"Required Python module is missing: {module}")

        for encoder in requirements.get("ffmpeg_encoders", []):
            encoder = str(encoder).strip()
            if encoder and not _ffmpeg_encoder_available(encoder):
                reasons.append(f"Required FFmpeg encoder is missing: {encoder}")

        readiness[name] = {
            "ready": not reasons,
            "reason": "; ".join(reasons),
            "requirements": requirements,
        }
    return readiness
