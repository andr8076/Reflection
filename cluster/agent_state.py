"""Durable worker-side lifecycle state.

The farm protocol is intentionally at-least-once.  A worker therefore keeps a
small local outbox until the master has acknowledged a final task result.  The
file is replaced atomically so an interrupted write cannot erase the last
completion that still needs to be delivered.
"""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any, Mapping


STATE_VERSION = 1


class AgentStateStore:
    """Persist the one completion that must be acknowledged before new work."""

    def __init__(self, path: Path | str):
        self.path = Path(path)

    def read(self) -> dict[str, Any]:
        if not self.path.is_file():
            return {"version": STATE_VERSION, "pending_completion": None}
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise RuntimeError(f"Worker outbox is unreadable: {self.path}: {exc}") from exc
        if not isinstance(data, dict):
            raise RuntimeError(f"Worker outbox must contain a JSON object: {self.path}")
        pending = data.get("pending_completion")
        if pending is not None and not isinstance(pending, dict):
            raise RuntimeError(f"Worker outbox pending_completion must be an object: {self.path}")
        return {"version": STATE_VERSION, "pending_completion": pending}

    def pending_completion(self) -> dict[str, Any] | None:
        pending = self.read().get("pending_completion")
        return dict(pending) if isinstance(pending, dict) else None

    def save_completion(self, completion: Mapping[str, Any]) -> None:
        self._write({"version": STATE_VERSION, "pending_completion": dict(completion)})

    def clear_completion(self, completion_id: str) -> bool:
        state = self.read()
        pending = state.get("pending_completion")
        if not isinstance(pending, dict):
            return False
        if str(pending.get("completion_id") or "") != str(completion_id or ""):
            return False
        self._write({"version": STATE_VERSION, "pending_completion": None})
        return True

    def _write(self, state: Mapping[str, Any]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        descriptor, temporary_name = tempfile.mkstemp(
            prefix=f".{self.path.name}.", suffix=".tmp", dir=str(self.path.parent)
        )
        temporary_path = Path(temporary_name)
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
                json.dump(dict(state), handle, indent=2, sort_keys=True)
                handle.write("\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_path, self.path)
        finally:
            if temporary_path.exists():
                temporary_path.unlink()
