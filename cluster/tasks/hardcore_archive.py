"""Run one folder through the linked, auto-updating Hardcore Archive project."""

from __future__ import annotations

import json
import logging
import shutil
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlparse

WORKER_ROOT = Path(__file__).resolve().parents[1]
if str(WORKER_ROOT) not in sys.path:
    sys.path.insert(0, str(WORKER_ROOT))

from archive_dependency import ensure_hardcore_archive  # noqa: E402

TASK_NAME = "hardcore_archive"
DESCRIPTION = "Archive one folder as a verified .7z using the linked Hardcore Archive project."
TASK_SPEC_JSON = r'''
{
  "name": "hardcore_archive",
  "description": "Archive one source folder into a verified .7z file using the linked Hardcore-Archive project.",
  "production_ready": true,
  "requirements": {
    "commands": ["bash", "git", "python3"],
    "command_alternatives": [["7zz", "7z", "7za"]]
  },
  "dependencies": {
    "repositories": [{
      "name": "Hardcore-Archive",
      "repository": "https://github.com/andr8076/Hardcore-Archive",
      "branch": "main",
      "update": "before_each_hardcore_archive_run",
      "submodules": "pinned_to_parent_commit"
    }]
  },
  "source": {
    "mode": "required",
    "label": "Source folder",
    "help": "One folder readable directly by this worker, such as a local or shared mount. FTP/SFTP folder transfers are not supported."
  },
  "delivery": {
    "mode": "auto",
    "label": "7-Zip archive",
    "help": "Written beside the source as {basename}.7z when delivery is blank; an override must end in .7z.",
    "template": "{dir}/{basename}.7z",
    "extension": ".7z"
  },
  "output": {
    "kind": "file",
    "extension": ".7z",
    "container": "7z",
    "preserve_source": true
  }
}
'''
TASK_SPEC = json.loads(TASK_SPEC_JSON)


def install() -> None:
    """Refresh the linked Hardcore Archive main checkout and its pinned submodules."""
    dependency = ensure_hardcore_archive(update=True)
    logging.info(
        "Hardcore Archive dependency is ready: %s (%s).",
        dependency.repository,
        dependency.commit[:12],
    )


def _output_path(source_path: Path, delivery: str | Path | None) -> Path:
    if delivery is None or str(delivery).strip() == "":
        return Path(f"{source_path}.7z")
    return Path(delivery).expanduser().resolve()


def _path_is_within(path: Path, directory: Path) -> bool:
    try:
        path.relative_to(directory)
        return True
    except ValueError:
        return False


def _run_archive(command: list[str]) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(command, check=False)
    except (OSError, subprocess.SubprocessError) as exc:
        raise RuntimeError(f"Could not start Hardcore Archive: {exc}") from exc


def run(source, delivery, overwrite_allowed):
    """Archive one worker-readable directory without deleting its source."""
    raw_source = str(source or "").strip()
    if not raw_source:
        raise ValueError("A source folder is required for hardcore_archive.")
    if urlparse(raw_source).scheme.lower() in {"ftp", "ftps", "sftp"}:
        raise ValueError(
            "hardcore_archive requires a folder mounted and readable by the worker; "
            "Reflection's FTP/SFTP transfer adapter only transfers individual files."
        )

    source_path = Path(raw_source).expanduser().resolve()
    if not source_path.exists():
        raise FileNotFoundError(f"Source folder does not exist: {source_path}")
    if not source_path.is_dir():
        raise NotADirectoryError(f"hardcore_archive requires one folder: {source_path}")

    output_file = _output_path(source_path, delivery)
    if output_file.suffix.lower() != ".7z":
        raise ValueError(f"hardcore_archive delivery must end with .7z: {output_file}")
    if _path_is_within(output_file, source_path):
        raise ValueError("The .7z delivery file must be outside the source folder.")
    if output_file.exists() and output_file.is_dir():
        raise IsADirectoryError(f"Delivery path is a directory: {output_file}")
    if output_file.exists() and not overwrite_allowed:
        raise FileExistsError(f"Target delivery file exists and overwrite is disabled: {output_file}")

    output_file.parent.mkdir(parents=True, exist_ok=True)
    bash = shutil.which("bash")
    if bash is None:
        raise RuntimeError("bash is required to run the Hardcore Archive project.")

    dependency = ensure_hardcore_archive(update=True)
    command = [bash, str(dependency.script)]
    if overwrite_allowed:
        command.append("--force")
    command.append(str(source_path))
    if delivery is not None and str(delivery).strip() != "":
        command.append(str(output_file))

    result = _run_archive(command)
    if result.returncode != 0:
        raise RuntimeError(
            f"Hardcore Archive exited with code {result.returncode}; "
            "check its run log beside the archive."
        )
    if not output_file.is_file():
        raise RuntimeError(
            f"Hardcore Archive reported success but the .7z file is missing: {output_file}"
        )

    message = (
        f"Created verified Hardcore Archive {output_file} "
        f"using main commit {dependency.commit[:12]}; source was kept."
    )
    logging.info("%s", message)
    return {"success": True, "cleanup_source": False, "message": message}
