#!/usr/bin/env python3
"""Stream one isolated task's captured logs until the supervising worker finishes."""

from __future__ import annotations

import argparse
import time
from pathlib import Path


def _emit_new_text(path: Path, offset: int, label: str) -> int:
    """Print newly appended bytes from one log file and return the new offset."""
    try:
        with path.open("rb") as log_file:
            log_file.seek(offset)
            data = log_file.read()
            offset = log_file.tell()
    except FileNotFoundError:
        return offset

    if data:
        text = data.decode("utf-8", errors="replace")
        print(f"[{label}]", flush=True)
        print(text, end="" if text.endswith("\n") else "\n", flush=True)
    return offset


def stream_logs(stdout_path: Path, stderr_path: Path, done_path: Path, poll_interval: float = 0.25) -> None:
    """Stream captured output and exit automatically after the done marker appears."""
    stdout_offset = 0
    stderr_offset = 0
    while True:
        stdout_offset = _emit_new_text(stdout_path, stdout_offset, "stdout")
        stderr_offset = _emit_new_text(stderr_path, stderr_offset, "stderr")
        if done_path.exists():
            stdout_offset = _emit_new_text(stdout_path, stdout_offset, "stdout")
            _emit_new_text(stderr_path, stderr_offset, "stderr")
            return
        time.sleep(poll_interval)


def main() -> int:
    parser = argparse.ArgumentParser(description="Show live output for one Reflection task.")
    parser.add_argument("--title", required=True)
    parser.add_argument("--stdout-log", type=Path, required=True)
    parser.add_argument("--stderr-log", type=Path, required=True)
    parser.add_argument("--done-file", type=Path, required=True)
    args = parser.parse_args()

    print(args.title, flush=True)
    print("This window closes automatically when the task finishes.\n", flush=True)
    stream_logs(args.stdout_log, args.stderr_log, args.done_file)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
