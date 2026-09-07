"""Compress a source file or directory into a ZIP archive."""

import json
import logging
import os
import tempfile
import zipfile
from pathlib import Path

TASK_NAME = "compress_archive"
DESCRIPTION = "Compress a file or directory into a .zip archive."
TASK_SPEC_JSON = r'''
{
  "name": "compress_archive",
  "description": "Compress a file or directory into a .zip archive.",
  "production_ready": true,
  "source": {
    "mode": "required",
    "label": "Source file or folder",
    "help": "File or directory the worker can read. For FTP/SFTP jobs, use the storage server path or URI."
  },
  "delivery": {
    "mode": "auto",
    "label": "ZIP output",
    "help": "Automatically written beside the source as {name}.zip unless a valid override is supplied.",
    "template": "{dir}/{name}.zip",
    "extension": ".zip"
  },
  "output": {
    "kind": "file",
    "extension": ".zip",
    "mime": "application/zip"
  }
}
'''
TASK_SPEC = json.loads(TASK_SPEC_JSON)

ENV_COMPRESSION_LEVEL = "REFLECTION_ZIP_COMPRESSION_LEVEL"


def install():
    """zipfile is part of the Python standard library."""
    logging.info("compress_archive uses Python's built-in ZIP support.")


def run(source, delivery, overwrite_allowed):
    """Compress source into a .zip delivery file."""
    source_path = Path(source).expanduser().resolve()
    delivery_path = Path(delivery).expanduser().resolve() if delivery else None

    if not source_path.exists():
        raise FileNotFoundError(f"Source path does not exist: {source_path}")
    if delivery_path is None:
        raise ValueError("Delivery path is required for compress_archive.")
    if delivery_path.suffix.lower() != ".zip":
        raise ValueError("compress_archive delivery must end with .zip.")
    if delivery_path.exists() and not overwrite_allowed:
        raise FileExistsError("Target delivery file exists and overwrite is disabled.")

    install()

    delivery_path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = _temporary_delivery_path(delivery_path)
    source_size = _path_size(source_path)
    compression_level = _compression_level()

    logging.info(
        "Compressing %s (%s bytes) to ZIP %s with compression level %s.",
        source_path,
        source_size,
        delivery_path,
        compression_level,
    )

    try:
        _write_zip(source_path, temp_path, compression_level)
        os.replace(temp_path, delivery_path)
    except Exception:
        temp_path.unlink(missing_ok=True)
        raise

    logging.info(
        "ZIP compression complete: %s -> %s (%s bytes).",
        source_path,
        delivery_path,
        delivery_path.stat().st_size,
    )
    return True


def _path_size(path):
    if path.is_file():
        return path.stat().st_size

    total = 0
    for child in path.rglob("*"):
        if child.is_file():
            total += child.stat().st_size
    return total


def _compression_level():
    configured = os.environ.get(ENV_COMPRESSION_LEVEL)
    if not configured:
        return 6
    level = int(configured)
    if level < 0 or level > 9:
        raise ValueError(f"{ENV_COMPRESSION_LEVEL} must be an integer from 0 through 9.")
    return level


def _temporary_delivery_path(delivery_path):
    with tempfile.NamedTemporaryFile(
        prefix=f".{delivery_path.name}.",
        suffix=".tmp",
        dir=delivery_path.parent,
        delete=False,
    ) as temp_file:
        return Path(temp_file.name)


def _write_zip(source_path, temp_path, compression_level):
    with zipfile.ZipFile(
        temp_path,
        mode="w",
        compression=zipfile.ZIP_DEFLATED,
        compresslevel=compression_level,
    ) as archive:
        if source_path.is_file():
            archive.write(source_path, arcname=source_path.name)
            return

        root = source_path.parent
        archive.write(source_path, arcname=source_path.relative_to(root))
        for child in sorted(source_path.rglob("*")):
            archive.write(child, arcname=child.relative_to(root))
