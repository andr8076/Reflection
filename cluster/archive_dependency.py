"""Install and refresh Reflection's external Hardcore Archive dependency."""

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


class HardcoreArchiveDependencyError(RuntimeError):
    """Raised when the configured Hardcore Archive dependency is unavailable."""


@dataclass(frozen=True)
class HardcoreArchiveDependency:
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
        raise HardcoreArchiveDependencyError(
            f"Cannot read dependency manifest {MANIFEST_PATH}: {exc}"
        ) from exc

    entries = payload.get("dependencies") if isinstance(payload, dict) else None
    if not isinstance(entries, list):
        raise HardcoreArchiveDependencyError("Dependency manifest must contain a dependencies list.")

    entry = next(
        (item for item in entries if isinstance(item, dict) and item.get("name") == "Hardcore-Archive"),
        None,
    )
    if entry is None:
        raise HardcoreArchiveDependencyError("Dependency manifest does not declare Hardcore-Archive.")

    repository = str(entry.get("repository") or "").strip()
    branch = str(entry.get("branch") or "").strip()
    relative_path = str(entry.get("path") or "").strip()
    if not repository or not branch or not relative_path:
        raise HardcoreArchiveDependencyError(
            "Hardcore-Archive needs repository, branch, and path values."
        )
    if branch != "main":
        raise HardcoreArchiveDependencyError("Hardcore-Archive must be checked out from main.")

    checkout = (WORKER_ROOT / relative_path).resolve()
    dependency_root = DEPENDENCY_ROOT.resolve()
    if not checkout.is_relative_to(dependency_root):
        raise HardcoreArchiveDependencyError(
            "The Hardcore-Archive checkout path must stay inside cluster/.dependencies."
        )
    return {
        "name": "Hardcore-Archive",
        "repository": repository,
        "branch": branch,
        "checkout": str(checkout),
    }


def _normalized_repository(value: str) -> tuple[str, str] | None:
    parsed = urlparse(value.strip())
    if parsed.scheme not in {"https", "ssh"} or not parsed.hostname:
        return None
    path = parsed.path.strip("/").removesuffix(".git").lower()
    return parsed.hostname.lower(), path


def _run(
    command: list[str],
    *,
    timeout: int | None = 180,
) -> subprocess.CompletedProcess[str]:
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
        raise HardcoreArchiveDependencyError(f"Could not run {command[0]}: {exc}") from exc


def _git_value(git: str, checkout: Path, *args: str) -> str:
    result = _run([git, "-C", str(checkout), *args])
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "no output").strip()
        raise HardcoreArchiveDependencyError(
            f"Git command failed in {checkout}: {detail}"
        )
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


def _validate_checkout(checkout: Path) -> None:
    required_files = (
        checkout / "hardcore-archive",
        checkout / "lib" / "scheduler.sh",
        checkout / "vendor" / "AV1Encode" / "AV1Encode.sh",
    )
    missing = [str(path) for path in required_files if not path.is_file()]
    if missing:
        raise HardcoreArchiveDependencyError(
            "Hardcore-Archive checkout is incomplete; missing: " + ", ".join(missing)
        )
    av1_entrypoint = required_files[-1]
    if not os.access(av1_entrypoint, os.X_OK):
        raise HardcoreArchiveDependencyError(
            f"The pinned AV1Encode submodule entry point is not executable: {av1_entrypoint}"
        )


def _update_submodules(git: str, checkout: Path) -> None:
    sync = _run(
        [git, "-C", str(checkout), "submodule", "sync", "--recursive"],
        timeout=180,
    )
    if sync.returncode != 0:
        detail = (sync.stderr or sync.stdout or "no output").strip()
        raise HardcoreArchiveDependencyError(
            f"Could not sync Hardcore-Archive submodules: {detail}"
        )

    updated = _run(
        [git, "-C", str(checkout), "submodule", "update", "--init", "--recursive"],
        timeout=900,
    )
    if updated.returncode != 0:
        detail = (updated.stderr or updated.stdout or "no output").strip()
        raise HardcoreArchiveDependencyError(
            f"Could not initialize Hardcore-Archive's pinned submodules: {detail}"
        )


def _clone(git: str, repository: str, branch: str, checkout: Path) -> None:
    staging = checkout.parent / f".{checkout.name}.clone-{os.getpid()}-{uuid.uuid4().hex}"
    backup = checkout.parent / f".{checkout.name}.old-{uuid.uuid4().hex}"
    try:
        result = _run(
            [
                git,
                "clone",
                "--depth",
                "1",
                "--single-branch",
                "--branch",
                branch,
                repository,
                str(staging),
            ],
            timeout=600,
        )
        if result.returncode != 0:
            detail = (result.stderr or result.stdout or "no output").strip()
            raise HardcoreArchiveDependencyError(
                f"Could not clone Hardcore-Archive from {repository}: {detail}"
            )
        _update_submodules(git, staging)
        _validate_checkout(staging)

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


def ensure_hardcore_archive(*, update: bool = True) -> HardcoreArchiveDependency:
    """Clone or refresh the pinned-submodule Hardcore-Archive main checkout."""
    entry = _manifest_entry()
    repository = entry["repository"]
    branch = entry["branch"]
    checkout = Path(entry["checkout"])
    git = shutil.which("git")
    if git is None:
        raise HardcoreArchiveDependencyError(
            "git is required to install and update the Hardcore-Archive dependency."
        )
    if checkout.is_symlink():
        raise HardcoreArchiveDependencyError(
            f"Refusing to use a symlink as the Hardcore-Archive checkout: {checkout}"
        )

    DEPENDENCY_ROOT.mkdir(parents=True, exist_ok=True)
    with _dependency_lock(DEPENDENCY_ROOT / "Hardcore-Archive.lock"):
        if not checkout.is_dir() or not (checkout / ".git").exists():
            _clone(git, repository, branch, checkout)
        else:
            configured_remote = _git_value(git, checkout, "remote", "get-url", "origin")
            if _normalized_repository(configured_remote) != _normalized_repository(repository):
                raise HardcoreArchiveDependencyError(
                    f"The Hardcore-Archive checkout points to {configured_remote}, "
                    f"expected {repository}."
                )

            if update:
                fetched = _run(
                    [
                        git,
                        "-C",
                        str(checkout),
                        "fetch",
                        "--quiet",
                        "--depth",
                        "1",
                        "--prune",
                        "origin",
                        branch,
                    ],
                    timeout=300,
                )
                if fetched.returncode == 0:
                    current = _git_value(git, checkout, "rev-parse", "HEAD")
                    latest = _git_value(git, checkout, "rev-parse", "FETCH_HEAD")
                    if current != latest:
                        reset = _run(
                            [git, "-C", str(checkout), "reset", "--hard", "FETCH_HEAD"]
                        )
                        if reset.returncode != 0:
                            detail = (reset.stderr or reset.stdout or "no output").strip()
                            raise HardcoreArchiveDependencyError(
                                f"Could not update Hardcore-Archive: {detail}"
                            )
                        logging.info(
                            "Updated Hardcore-Archive from %s to %s.",
                            current[:12],
                            latest[:12],
                        )
                else:
                    detail = (fetched.stderr or fetched.stdout or "no output").strip()
                    logging.warning(
                        "Could not check for Hardcore-Archive updates; using the installed "
                        "main commit: %s",
                        detail,
                    )

            _update_submodules(git, checkout)

        _validate_checkout(checkout)
        commit = _git_value(git, checkout, "rev-parse", "HEAD")
        logging.info(
            "Using Hardcore-Archive %s from %s (%s).",
            commit[:12],
            repository,
            branch,
        )
        return HardcoreArchiveDependency(
            name="Hardcore-Archive",
            repository=repository,
            branch=branch,
            checkout=checkout,
            script=checkout / "hardcore-archive",
            commit=commit,
        )
