"""Install and refresh Reflection's external encoding dependency."""

from __future__ import annotations

import fcntl
import json
import logging
import os
import shutil
import subprocess
import uuid
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

WORKER_ROOT = Path(__file__).resolve().parent
MANIFEST_PATH = WORKER_ROOT / "dependencies.json"
DEPENDENCY_ROOT = WORKER_ROOT / ".dependencies"
PROBE_CACHE_PATH = DEPENDENCY_ROOT / "265Encode-probe-cache.json"


class EncoderDependencyError(RuntimeError):
    """Raised when the configured 265Encode dependency is unavailable."""


@dataclass(frozen=True)
class EncoderDependency:
    name: str
    repository: str
    branch: str
    checkout: Path
    script: Path
    commit: str


def _manifest_entry() -> dict[str, str]:
    try:
        payload = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise EncoderDependencyError(f"Cannot read dependency manifest {MANIFEST_PATH}: {exc}") from exc

    entries = payload.get("dependencies") if isinstance(payload, dict) else None
    if not isinstance(entries, list):
        raise EncoderDependencyError("Dependency manifest must contain a dependencies list.")

    entry = next((item for item in entries if isinstance(item, dict) and item.get("name") == "265Encode"), None)
    if entry is None:
        raise EncoderDependencyError("Dependency manifest does not declare 265Encode.")

    repository = str(entry.get("repository") or "").strip()
    branch = str(entry.get("branch") or "").strip()
    relative_path = str(entry.get("path") or "").strip()
    if not repository or not branch or not relative_path:
        raise EncoderDependencyError("The 265Encode dependency needs repository, branch, and path values.")

    checkout = (WORKER_ROOT / relative_path).resolve()
    dependency_root = DEPENDENCY_ROOT.resolve()
    if not checkout.is_relative_to(dependency_root):
        raise EncoderDependencyError("The 265Encode checkout path must stay inside cluster/.dependencies.")
    return {
        "name": "265Encode",
        "repository": repository,
        "branch": branch,
        "checkout": str(checkout),
    }


def _normalized_repository(value: str) -> tuple[str, str] | None:
    parsed = urlparse(value.strip())
    if parsed.scheme not in {"https", "ssh"} or not parsed.hostname:
        return None
    if parsed.username or parsed.password:
        return None
    path = parsed.path.strip("/").removesuffix(".git").lower()
    return parsed.hostname.lower(), path


def _run(command: list[str], *, timeout: int | None = 180) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env["GIT_TERMINAL_PROMPT"] = "0"
    try:
        return subprocess.run(
            command,
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
            env=env,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise EncoderDependencyError(f"Could not run {command[0]}: {exc}") from exc


def _git_value(git: str, checkout: Path, *args: str) -> str:
    result = _run([git, "-C", str(checkout), *args])
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "no output").strip()
        raise EncoderDependencyError(f"Git command failed in {checkout}: {detail}")
    return result.stdout.strip()


@contextmanager
def _dependency_lock(lock_path: Path):
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with lock_path.open("a", encoding="utf-8") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)


def _remove_path(path: Path) -> None:
    if path.is_dir() and not path.is_symlink():
        shutil.rmtree(path)
    elif path.exists() or path.is_symlink():
        path.unlink()


def _clone(git: str, repository: str, branch: str, checkout: Path) -> None:
    staging = checkout.parent / f".{checkout.name}.clone-{os.getpid()}-{uuid.uuid4().hex}"
    backup = checkout.parent / f".{checkout.name}.old-{uuid.uuid4().hex}"
    try:
        result = _run(
            [git, "clone", "--depth", "1", "--single-branch", "--branch", branch, repository, str(staging)],
            timeout=300,
        )
        if result.returncode != 0:
            detail = (result.stderr or result.stdout or "no output").strip()
            raise EncoderDependencyError(f"Could not clone 265Encode from {repository}: {detail}")
        if not (staging / "265Encode.sh").is_file() or not (staging / "tools" / "HEVCPlan.py").is_file():
            raise EncoderDependencyError("The 265Encode repository is missing its protocol-2 entry points.")

        if checkout.exists():
            os.replace(checkout, backup)
        try:
            os.replace(staging, checkout)
        except BaseException:
            if backup.exists() and not checkout.exists():
                os.replace(backup, checkout)
            raise
        if backup.exists():
            _remove_path(backup)
    finally:
        if staging.exists():
            shutil.rmtree(staging, ignore_errors=True)
        if backup.exists() and checkout.exists():
            _remove_path(backup)


def ensure_265encode(*, update: bool = True) -> EncoderDependency:
    """Clone 265Encode or refresh its main branch before using it."""
    entry = _manifest_entry()
    repository = entry["repository"]
    branch = entry["branch"]
    checkout = Path(entry["checkout"])
    git = shutil.which("git")
    if git is None:
        raise EncoderDependencyError("git is required to install and update the 265Encode dependency.")

    if checkout.is_symlink():
        raise EncoderDependencyError(f"Refusing to use a symlink as the 265Encode checkout: {checkout}")

    with _dependency_lock(DEPENDENCY_ROOT / "265Encode.lock"):
        if not checkout.is_dir() or not (checkout / ".git").exists():
            _clone(git, repository, branch, checkout)
        else:
            configured_remote = _git_value(git, checkout, "remote", "get-url", "origin")
            if _normalized_repository(configured_remote) != _normalized_repository(repository):
                raise EncoderDependencyError(
                    f"The 265Encode checkout points to {configured_remote}, expected {repository}."
                )

            if update:
                fetched = _run(
                    [git, "-C", str(checkout), "fetch", "--quiet", "--depth", "1", "--prune", "origin", branch],
                    timeout=300,
                )
                if fetched.returncode == 0:
                    current = _git_value(git, checkout, "rev-parse", "HEAD")
                    latest = _git_value(git, checkout, "rev-parse", "FETCH_HEAD")
                    if current != latest:
                        reset = _run([git, "-C", str(checkout), "reset", "--hard", "FETCH_HEAD"])
                        if reset.returncode != 0:
                            detail = (reset.stderr or reset.stdout or "no output").strip()
                            raise EncoderDependencyError(f"Could not update 265Encode: {detail}")
                        logging.info("Updated 265Encode from %s to %s.", current[:12], latest[:12])
                else:
                    detail = (fetched.stderr or fetched.stdout or "no output").strip()
                    logging.warning("Could not check for 265Encode updates; using the installed version: %s", detail)

        script = checkout / "265Encode.sh"
        if not script.is_file() or not (checkout / "tools" / "HEVCPlan.py").is_file():
            raise EncoderDependencyError(f"The 265Encode protocol-2 files are missing from {checkout}.")
        if not os.access(script, os.X_OK):
            raise EncoderDependencyError(f"The 265Encode entry point is not executable: {script}")

        commit = _git_value(git, checkout, "rev-parse", "HEAD")
        logging.info("Using 265Encode %s from %s (%s).", commit[:12], repository, branch)
        return EncoderDependency(
            name="265Encode",
            repository=repository,
            branch=branch,
            checkout=checkout,
            script=script,
            commit=commit,
        )
