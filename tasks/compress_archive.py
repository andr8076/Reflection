"""Compress a source file or directory into a small xz-compressed tar archive."""

import logging
import math
import os
import shutil
import signal
import subprocess
import tempfile
import time
from pathlib import Path

TASK_NAME = "compress_archive"
DESCRIPTION = "Compress a file or directory into a small .tar.xz archive with hardware-aware limits."

DEFAULT_MIN_SECONDS = 60
DEFAULT_MAX_SECONDS = 2 * 60 * 60
ENV_MAX_SECONDS = "REFLECTION_COMPRESS_MAX_SECONDS"
ENV_PRESET = "REFLECTION_COMPRESS_XZ_PRESET"

# Approximate xz encoder memory requirements per thread, in MiB. These values are
# intentionally conservative so farm nodes do not overcommit themselves when xz
# uses multiple worker threads.
XZ_MEMORY_BY_PRESET_MIB = {
    9: 674,
    8: 370,
    7: 186,
    6: 94,
    5: 48,
    4: 32,
    3: 24,
    2: 16,
    1: 8,
    0: 4,
}


def install():
    """Check that the external tools used for bounded compression are available."""
    missing = [tool for tool in ("tar", "xz") if shutil.which(tool) is None]
    if missing:
        raise RuntimeError(
            "compress_archive requires these command-line tools: " + ", ".join(missing)
        )
    logging.info("compress_archive dependencies are available: tar, xz")


def run(source, delivery, overwrite_allowed):
    """Compress source into delivery using xz with hardware-aware settings."""
    source_path = Path(source).expanduser().resolve()
    delivery_path = Path(delivery).expanduser().resolve() if delivery else None

    if not source_path.exists():
        raise FileNotFoundError(f"Source path does not exist: {source_path}")
    if delivery_path is None:
        raise ValueError("Delivery path is required for compress_archive.")
    if delivery_path.exists() and not overwrite_allowed:
        raise FileExistsError("Target delivery file exists and overwrite is disabled.")

    install()

    delivery_path.parent.mkdir(parents=True, exist_ok=True)
    size_bytes = _path_size(source_path)
    hardware = _hardware_profile()
    timeout_seconds = _compression_timeout_seconds(size_bytes, hardware)
    preset = _select_xz_preset(hardware)
    threads = _select_xz_threads(hardware, preset)

    logging.info(
        "Compressing %s (%s bytes) to %s with xz preset %s, %s thread(s), %ss timeout "
        "based on %s CPU(s) and %s MiB RAM.",
        source_path,
        size_bytes,
        delivery_path,
        preset,
        threads,
        timeout_seconds,
        hardware["cpu_count"],
        hardware["memory_mib"] or "unknown",
    )

    temp_path = _temporary_delivery_path(delivery_path)
    try:
        _compress_with_tar_xz(source_path, temp_path, preset, threads, timeout_seconds)
        os.replace(temp_path, delivery_path)
    except Exception:
        temp_path.unlink(missing_ok=True)
        raise

    logging.info(
        "Compression complete: %s -> %s (%s bytes).",
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


def _hardware_profile():
    return {
        "cpu_count": max(1, os.cpu_count() or 1),
        "memory_mib": _total_memory_mib(),
    }


def _total_memory_mib():
    meminfo = Path("/proc/meminfo")
    if meminfo.is_file():
        for line in meminfo.read_text(encoding="utf-8").splitlines():
            if line.startswith("MemTotal:"):
                parts = line.split()
                if len(parts) >= 2:
                    return int(parts[1]) // 1024

    try:
        page_size = os.sysconf("SC_PAGE_SIZE")
        page_count = os.sysconf("SC_PHYS_PAGES")
        return int(page_size * page_count / (1024 * 1024))
    except (AttributeError, OSError, ValueError):
        return None


def _compression_timeout_seconds(size_bytes, hardware):
    configured = os.environ.get(ENV_MAX_SECONDS)
    if configured:
        timeout = int(configured)
        if timeout <= 0:
            raise ValueError(f"{ENV_MAX_SECONDS} must be greater than zero.")
        return timeout

    size_mib = max(1, math.ceil(size_bytes / (1024 * 1024)))
    cpu_count = max(1, int(hardware["cpu_count"]))

    # xz -e favors small output over speed. Give larger inputs proportionally
    # more time, while faster farm nodes receive a lower wall-clock allowance
    # because they can do the same work with more parallel compression threads.
    estimated = 120 + (size_mib * 8 / math.sqrt(cpu_count))
    return int(max(DEFAULT_MIN_SECONDS, min(DEFAULT_MAX_SECONDS, estimated)))


def _select_xz_preset(hardware):
    configured = os.environ.get(ENV_PRESET)
    if configured:
        preset = int(configured)
        if preset not in XZ_MEMORY_BY_PRESET_MIB:
            raise ValueError(f"{ENV_PRESET} must be an integer from 0 through 9.")
        return preset

    memory_mib = hardware["memory_mib"]
    if memory_mib is None:
        return 6

    # Reserve at least half the machine for the OS and other farm processes.
    memory_budget_mib = max(64, memory_mib // 2)
    for preset in range(9, -1, -1):
        if XZ_MEMORY_BY_PRESET_MIB[preset] <= memory_budget_mib:
            return preset
    return 0


def _select_xz_threads(hardware, preset):
    cpu_count = max(1, int(hardware["cpu_count"]))
    memory_mib = hardware["memory_mib"]
    if memory_mib is None:
        return cpu_count

    memory_budget_mib = max(64, memory_mib // 2)
    memory_per_thread_mib = XZ_MEMORY_BY_PRESET_MIB[preset]
    memory_limited_threads = max(1, memory_budget_mib // memory_per_thread_mib)
    return max(1, min(cpu_count, memory_limited_threads))


def _temporary_delivery_path(delivery_path):
    with tempfile.NamedTemporaryFile(
        prefix=f".{delivery_path.name}.",
        suffix=".tmp",
        dir=delivery_path.parent,
        delete=False,
    ) as temp_file:
        return Path(temp_file.name)


def _compress_with_tar_xz(source_path, temp_path, preset, threads, timeout_seconds):
    tar_command = [
        "tar",
        "--warning=no-file-changed",
        "-C",
        str(source_path.parent),
        "-cf",
        "-",
        source_path.name,
    ]
    xz_command = ["xz", f"-{preset}", "-e", "-T", str(threads), "-c"]

    started_at = time.monotonic()
    tar_process = None
    xz_process = None
    with temp_path.open("wb") as output:
        try:
            tar_process = subprocess.Popen(
                tar_command,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                start_new_session=True,
            )
            xz_process = subprocess.Popen(
                xz_command,
                stdin=tar_process.stdout,
                stdout=output,
                stderr=subprocess.PIPE,
                start_new_session=True,
            )
            tar_process.stdout.close()

            _, xz_stderr = xz_process.communicate(timeout=timeout_seconds)
            elapsed = time.monotonic() - started_at
            _, tar_stderr = tar_process.communicate(
                timeout=max(1, timeout_seconds - int(elapsed))
            )
        except subprocess.TimeoutExpired as exc:
            _terminate_process_group(xz_process)
            _terminate_process_group(tar_process)
            raise TimeoutError(
                f"Compression exceeded the configured {timeout_seconds}s limit."
            ) from exc
        finally:
            _terminate_process_group(xz_process)
            _terminate_process_group(tar_process)

    if tar_process.returncode != 0:
        raise RuntimeError(
            _format_process_error("tar", tar_process.returncode, tar_stderr)
        )
    if xz_process.returncode != 0:
        raise RuntimeError(
            _format_process_error("xz", xz_process.returncode, xz_stderr)
        )


def _terminate_process_group(process):
    if process is None or process.poll() is not None:
        return

    try:
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=5)
    except ProcessLookupError:
        return
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()


def _format_process_error(name, returncode, stderr):
    message = stderr.decode("utf-8", errors="replace").strip() if stderr else ""
    return f"{name} exited with status {returncode}: {message}"
