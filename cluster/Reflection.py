import argparse
import contextlib
import ftplib
import hashlib
import importlib.util
import ipaddress
import json
import logging
import os
import platform
import shlex
import shutil
import socket
import subprocess
import tempfile
import sys
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import quote, unquote, urlparse

from task_registry import TaskDefinition, discover_task_definitions, normalize_task_result

# --- CONFIGURATION ---
DEFAULT_SERVER_URL = "http://your-server-domain.com/farm_api.php"
DEFAULT_POLL_INTERVAL = 10
DEFAULT_HEARTBEAT_INTERVAL = 60
DEFAULT_START_DELAY_SECONDS = 30
DEFAULT_SHUTDOWN_DELAY_SECONDS = 30
DEFAULT_TASK_TIMEOUT_SECONDS = 12 * 60 * 60
DEFAULT_TASK_LOG_TAIL_BYTES = 12000
DEFAULT_TASK_ISOLATION = True
DEFAULT_SHOW_TASK_TERMINAL = True
DEFAULT_PC_ID = socket.gethostname()
DEFAULT_MIN_FREE_SPACE_GB = 5
DEFAULT_MIN_FREE_SPACE_MULTIPLIER = 2.0
DEFAULT_LOCAL_TEMP_MAX_AGE_HOURS = 24
DEFAULT_QUARANTINE_KEEP_DAYS = 14
DEFAULT_QUARANTINE_MAX_GB = 100.0
DEFAULT_CLEANUP_ROOTS = []
DEFAULT_TRANSFER_AUTH = {
    "scheme": "ftp",
    "host": "",
    "port": 21,
    "username": DEFAULT_PC_ID,
    "password": "",
}
CONFIG_FILE_ENV = "REFLECTION_CONFIG_FILE"
CONFIG_FILE_NAME = "reflection_config.json"


def default_config_path():
    """Return the local agent configuration path."""
    configured = os.environ.get(CONFIG_FILE_ENV)
    if configured:
        return Path(configured).expanduser()
    return Path(__file__).with_name(CONFIG_FILE_NAME)


def load_agent_config(config_path=None):
    """Load agent settings from JSON while preserving safe defaults."""
    path = Path(config_path).expanduser() if config_path is not None else default_config_path()
    config = {
        "server_url": DEFAULT_SERVER_URL,
        "poll_interval": DEFAULT_POLL_INTERVAL,
        "start_delay_seconds": DEFAULT_START_DELAY_SECONDS,
        "shutdown_delay_seconds": DEFAULT_SHUTDOWN_DELAY_SECONDS,
        "pc_id": DEFAULT_PC_ID,
        "cleanup_roots": list(DEFAULT_CLEANUP_ROOTS),
        "task_timeout_seconds": DEFAULT_TASK_TIMEOUT_SECONDS,
        "task_timeouts": {},
        "task_log_tail_bytes": DEFAULT_TASK_LOG_TAIL_BYTES,
        "task_isolation": DEFAULT_TASK_ISOLATION,
        "show_task_terminal": DEFAULT_SHOW_TASK_TERMINAL,
        "min_free_space_gb": DEFAULT_MIN_FREE_SPACE_GB,
        "min_free_space_multiplier": DEFAULT_MIN_FREE_SPACE_MULTIPLIER,
        "local_temp_max_age_hours": DEFAULT_LOCAL_TEMP_MAX_AGE_HOURS,
        "quarantine_keep_days": DEFAULT_QUARANTINE_KEEP_DAYS,
        "quarantine_max_gb": DEFAULT_QUARANTINE_MAX_GB,
    }
    env_cleanup_roots = _cleanup_roots_from_env()
    if env_cleanup_roots:
        config["cleanup_roots"] = env_cleanup_roots

    if not path.is_file():
        return config

    with path.open(encoding="utf-8") as config_file:
        loaded = json.load(config_file)

    if not isinstance(loaded, dict):
        raise ValueError(f"Reflection config must contain a JSON object: {path}")

    if "server_url" in loaded:
        server_url = str(loaded["server_url"]).strip()
        if server_url:
            config["server_url"] = server_url

    if "poll_interval" in loaded:
        poll_interval = int(loaded["poll_interval"])
        if poll_interval <= 0:
            raise ValueError("poll_interval must be greater than zero.")
        config["poll_interval"] = poll_interval

    if "heartbeat_interval" in loaded:
        heartbeat_interval = int(loaded["heartbeat_interval"])
        if heartbeat_interval <= 0:
            raise ValueError("heartbeat_interval must be greater than zero.")
        config["heartbeat_interval"] = heartbeat_interval

    if "start_delay_seconds" in loaded:
        config["start_delay_seconds"] = max(0, int(loaded["start_delay_seconds"]))

    if "shutdown_delay_seconds" in loaded:
        config["shutdown_delay_seconds"] = max(0, int(loaded["shutdown_delay_seconds"]))

    if "task_timeout_seconds" in loaded:
        timeout = int(loaded["task_timeout_seconds"])
        if timeout < 0:
            raise ValueError("task_timeout_seconds must be zero or greater.")
        config["task_timeout_seconds"] = timeout

    if "task_timeouts" in loaded:
        raw_timeouts = loaded["task_timeouts"]
        if not isinstance(raw_timeouts, dict):
            raise ValueError("task_timeouts must be an object mapping task names to seconds.")
        timeouts = {}
        for task_name, timeout_value in raw_timeouts.items():
            timeout = int(timeout_value)
            if timeout < 0:
                raise ValueError("task_timeouts values must be zero or greater.")
            timeouts[str(task_name)] = timeout
        config["task_timeouts"] = timeouts

    if "task_log_tail_bytes" in loaded:
        log_tail_bytes = int(loaded["task_log_tail_bytes"])
        if log_tail_bytes < 1024:
            raise ValueError("task_log_tail_bytes must be at least 1024.")
        config["task_log_tail_bytes"] = log_tail_bytes

    if "task_isolation" in loaded:
        config["task_isolation"] = bool(loaded["task_isolation"])

    if "show_task_terminal" in loaded:
        config["show_task_terminal"] = bool(loaded["show_task_terminal"])


    if "min_free_space_gb" in loaded:
        config["min_free_space_gb"] = max(0.0, float(loaded["min_free_space_gb"]))

    if "min_free_space_multiplier" in loaded:
        config["min_free_space_multiplier"] = max(0.0, float(loaded["min_free_space_multiplier"]))

    if "local_temp_max_age_hours" in loaded:
        config["local_temp_max_age_hours"] = max(1, int(loaded["local_temp_max_age_hours"]))

    if "quarantine_keep_days" in loaded:
        config["quarantine_keep_days"] = max(1, int(loaded["quarantine_keep_days"]))

    if "quarantine_max_gb" in loaded:
        config["quarantine_max_gb"] = max(0.0, float(loaded["quarantine_max_gb"]))

    if "pc_id" in loaded:
        pc_id = str(loaded["pc_id"]).strip()
        if pc_id:
            config["pc_id"] = pc_id

    cleanup_roots = _normalize_cleanup_roots(loaded.get("cleanup_roots", []))
    env_cleanup_roots = _cleanup_roots_from_env()
    if cleanup_roots or env_cleanup_roots:
        config["cleanup_roots"] = cleanup_roots + env_cleanup_roots

    if isinstance(loaded.get("transfer_auth"), dict):
        transfer_auth = _normalize_transfer_auth(loaded["transfer_auth"])
        if transfer_auth is not None:
            config["transfer_auth"] = transfer_auth

    return config


def _cleanup_roots_from_env():
    """Return cleanup roots from REFLECTION_CLEANUP_ROOTS when configured."""
    raw_roots = os.environ.get("REFLECTION_CLEANUP_ROOTS", "")
    if not raw_roots.strip():
        return []
    return _normalize_cleanup_roots(raw_roots.split(os.pathsep))


def _normalize_cleanup_roots(value):
    """Normalize directories where task source cleanup is allowed."""
    if value in (None, ""):
        return []
    if isinstance(value, (str, os.PathLike)):
        value = [value]
    if not isinstance(value, list):
        raise ValueError("cleanup_roots must be a string or list of directory paths.")

    roots = []
    for entry in value:
        root = Path(entry).expanduser().resolve()
        roots.append(str(root))
    return roots


def _path_is_within(path, root):
    """Return true when path is inside root or equal to root."""
    try:
        Path(path).resolve().relative_to(Path(root).resolve())
        return True
    except ValueError:
        return False


def _normalize_transfer_auth(value):
    """Validate local transfer auth defaults from agent configuration."""
    if not isinstance(value, dict):
        return None

    scheme = str(value.get("scheme", DEFAULT_TRANSFER_AUTH["scheme"])).strip().lower()
    if scheme in {"", "none", "disabled", "off"}:
        return None
    if scheme not in {"ftp", "ftps", "sftp"}:
        raise ValueError("transfer_auth.scheme must be one of: ftp, ftps, sftp.")

    host = str(value.get("host", DEFAULT_TRANSFER_AUTH["host"])).strip()
    username = str(value.get("username", DEFAULT_TRANSFER_AUTH["username"])).strip()
    password = str(value.get("password", DEFAULT_TRANSFER_AUTH["password"]))
    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    port_value = value.get("port", default_port)
    try:
        port = int(port_value)
    except (TypeError, ValueError) as exc:
        raise ValueError("transfer_auth.port must be a whole number.") from exc
    if port <= 0:
        raise ValueError("transfer_auth.port must be greater than zero.")

    return {
        "scheme": scheme,
        "host": host,
        "port": port,
        "username": username,
        "password": password,
    }


def find_git_root(start_path=__file__):
    """Return the nearest parent directory that contains a .git checkout."""
    current = Path(start_path).resolve()
    if current.is_file():
        current = current.parent

    for candidate in (current, *current.parents):
        if (candidate / ".git").exists():
            return candidate

    return None


def _resolve_git_directory(repo_root):
    git_path = repo_root / ".git"
    if git_path.is_dir():
        return git_path
    if not git_path.is_file():
        return None

    content = git_path.read_text(encoding="utf-8", errors="replace").strip()
    prefix = "gitdir:"
    if not content.startswith(prefix):
        return None

    git_dir = Path(content[len(prefix):].strip())
    if not git_dir.is_absolute():
        git_dir = repo_root / git_dir
    return git_dir if git_dir.is_dir() else None


def _read_git_head_fallback(repo_root):
    git_dir = _resolve_git_directory(repo_root)
    if git_dir is None:
        return None

    head_path = git_dir / "HEAD"
    if not head_path.is_file():
        return None

    head = head_path.read_text(encoding="utf-8", errors="replace").strip()
    if not head:
        return None

    if not head.startswith("ref: "):
        return head[:12]

    ref = head[5:].strip()
    ref_path = git_dir / Path(*ref.split("/"))
    if ref_path.is_file():
        commit = ref_path.read_text(encoding="utf-8", errors="replace").strip()
        return commit[:12] if commit else None

    packed_refs = git_dir / "packed-refs"
    if packed_refs.is_file():
        for line in packed_refs.read_text(encoding="utf-8", errors="replace").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or line.startswith("^"):
                continue
            parts = line.split()
            if len(parts) >= 2 and parts[1] == ref:
                return parts[0][:12]

    return None


def _read_reflection_commit_file(repo_root):
    commit_path = Path(repo_root) / ".reflection_commit"
    try:
        commit = commit_path.read_text(encoding="utf-8", errors="replace").strip()
    except OSError:
        return None

    if re.fullmatch(r"[0-9a-fA-F]{7,40}", commit):
        return commit.lower()
    return None


def read_git_version(start_path=__file__):
    """Return the Reflection version from Git, or from .reflection_commit for deployed copies."""
    repo_root = find_git_root(start_path)
    if repo_root is None:
        repo_root = Path(start_path).resolve()
        if repo_root.is_file():
            repo_root = repo_root.parent
        # Reflection.py lives in cluster/, so the project root is normally one level up.
        if repo_root.name == "cluster":
            repo_root = repo_root.parent
        return _read_reflection_commit_file(repo_root) or "unknown"

    try:
        result = subprocess.run(
            ["git", "-C", str(repo_root), "rev-parse", "--short=12", "HEAD"],
            check=True,
            capture_output=True,
            text=True,
            timeout=5,
        )
        version = result.stdout.strip()
        if version:
            return version
    except (OSError, subprocess.SubprocessError):
        pass

    return _read_git_head_fallback(repo_root) or _read_reflection_commit_file(repo_root) or "unknown"


VERSION = read_git_version()
AGENT_CONFIG = load_agent_config()
SERVER_URL = AGENT_CONFIG["server_url"]  # Target PHP endpoint
POLL_INTERVAL = AGENT_CONFIG["poll_interval"]  # Seconds to wait before checking for new jobs if idle
HEARTBEAT_INTERVAL = int(AGENT_CONFIG.get("heartbeat_interval", DEFAULT_HEARTBEAT_INTERVAL))
START_DELAY_SECONDS = max(0, int(AGENT_CONFIG.get("start_delay_seconds", DEFAULT_START_DELAY_SECONDS)))
SHUTDOWN_DELAY_SECONDS = max(0, int(AGENT_CONFIG.get("shutdown_delay_seconds", DEFAULT_SHUTDOWN_DELAY_SECONDS)))
PC_ID = AGENT_CONFIG["pc_id"]  # Unique identifier for this node
LOCAL_TRANSFER_AUTH = AGENT_CONFIG.get("transfer_auth", {})
CLEANUP_ROOTS = tuple(AGENT_CONFIG.get("cleanup_roots", []))
TASKS_DIR = Path(__file__).with_name("tasks")
TASK_TIMEOUT_SECONDS = int(AGENT_CONFIG.get("task_timeout_seconds", DEFAULT_TASK_TIMEOUT_SECONDS))
TASK_TIMEOUTS = dict(AGENT_CONFIG.get("task_timeouts", {}))
TASK_LOG_TAIL_BYTES = int(AGENT_CONFIG.get("task_log_tail_bytes", DEFAULT_TASK_LOG_TAIL_BYTES))
TASK_ISOLATION = bool(AGENT_CONFIG.get("task_isolation", DEFAULT_TASK_ISOLATION))
SHOW_TASK_TERMINAL = bool(AGENT_CONFIG.get("show_task_terminal", DEFAULT_SHOW_TASK_TERMINAL))
TASK_RUNNER_PATH = Path(__file__).with_name("task_runner.py")
TASK_LOG_VIEWER_PATH = Path(__file__).with_name("task_log_viewer.py")
UPDATE_SCRIPT_PATH = Path(__file__).resolve().parent.parent / "update.sh"
MIN_FREE_SPACE_BYTES = int(float(AGENT_CONFIG.get("min_free_space_gb", DEFAULT_MIN_FREE_SPACE_GB)) * 1024 * 1024 * 1024)
MIN_FREE_SPACE_MULTIPLIER = float(AGENT_CONFIG.get("min_free_space_multiplier", DEFAULT_MIN_FREE_SPACE_MULTIPLIER))
LOCAL_TEMP_MAX_AGE_HOURS = int(AGENT_CONFIG.get("local_temp_max_age_hours", DEFAULT_LOCAL_TEMP_MAX_AGE_HOURS))
QUARANTINE_KEEP_DAYS = int(AGENT_CONFIG.get("quarantine_keep_days", DEFAULT_QUARANTINE_KEEP_DAYS))
QUARANTINE_MAX_BYTES = int(float(AGENT_CONFIG.get("quarantine_max_gb", DEFAULT_QUARANTINE_MAX_GB)) * 1024 * 1024 * 1024)
_ACTIVE_TRANSFER_AUTH = {}

# Setup logging to see what the farm bot is doing
logging.basicConfig(level=logging.INFO, format="%(asctime)s - [%(levelname)s] - %(message)s")


def _merge_transfer_settings(task_transfer_server=None, task_transfer_auth=None):
    """Merge master-supplied server details with this worker's local login.

    The master should describe where the shared storage server lives. Each worker
    should keep its own username/password locally. Legacy master-supplied
    credentials are still accepted only as a fallback when the worker has none.
    """
    merged = {}
    local_auth = LOCAL_TRANSFER_AUTH if isinstance(LOCAL_TRANSFER_AUTH, dict) else {}
    legacy_auth = task_transfer_auth if isinstance(task_transfer_auth, dict) else {}
    server = task_transfer_server if isinstance(task_transfer_server, dict) else {}

    # Local values are useful defaults when a task URL already contains a host
    # but omits credentials, or while running older jobs without transfer_server.
    for key in ("id", "scheme", "host", "port", "root"):
        value = local_auth.get(key)
        if value not in (None, ""):
            merged[key] = value

    # Backwards compatibility: older masters used transfer_auth for both server
    # details and credentials. Keep accepting the server part from that object.
    for key in ("id", "scheme", "host", "port", "root"):
        value = legacy_auth.get(key)
        if value not in (None, ""):
            merged[key] = value

    # Preferred path: server connection details supplied by the master.
    for key in ("id", "scheme", "host", "port", "root"):
        value = server.get(key)
        if value not in (None, ""):
            merged[key] = value

    # Preferred credentials: the worker's own local login. Legacy task
    # credentials are only a fallback for older deployments.
    for key in ("username", "password"):
        value = local_auth.get(key)
        if value not in (None, ""):
            merged[key] = value
        else:
            fallback = legacy_auth.get(key)
            if fallback not in (None, ""):
                merged[key] = fallback

    return merged


def _merge_transfer_auth(task_transfer_auth):
    """Backward-compatible wrapper for tests and older code paths."""
    return _merge_transfer_settings(None, task_transfer_auth)


@dataclass(frozen=True)
class TaskOutcome:
    """Normalized result from a task run."""

    success: bool
    stop_agent: bool = False
    restart_agent: bool = False
    reboot_system: bool = False
    reload_tasks: bool = False
    cleanup_source: bool = False
    message: str = ""
    relinquished: bool = False


def run_task_installer(task_name):
    """Run the optional install() area inside one task file."""
    registry = discover_tasks()
    definition = registry.get(task_name)

    if definition is None:
        available_tasks = ", ".join(sorted(registry)) or "none"
        raise KeyError(f"Unknown task '{task_name}'. Available tasks: {available_tasks}")

    if definition.install is None:
        logging.info("Task '%s' has no install() function.", task_name)
        return

    logging.info("Running install() for task '%s'...", task_name)
    definition.install()


def _normalize_task_result(result):
    """Convert a task return value into a TaskOutcome."""
    normalized = normalize_task_result(result)
    return TaskOutcome(**normalized)


def _system_noop(source, delivery, overwrite_allowed):
    """Built-in task used to prove the worker can accept control jobs."""
    logging.info("System noop requested.")
    return True


def _system_status(source, delivery, overwrite_allowed):
    """Built-in task that records basic worker status when delivery is set."""
    status = {
        "pc_id": PC_ID,
        "version": VERSION,
        "timestamp": int(time.time()),
        "tasks": sorted(built_in_tasks()),
    }

    if delivery:
        delivery_path = Path(delivery)
        if delivery_path.exists() and not overwrite_allowed:
            raise FileExistsError("Target delivery file exists and overwrite is disabled.")
        if delivery_path.parent != Path(""):
            delivery_path.parent.mkdir(parents=True, exist_ok=True)
        delivery_path.write_text(json.dumps(status, indent=2) + "\n", encoding="utf-8")

    logging.info("System status requested: %s", status)
    return {
        "success": True,
        "cleanup_source": False,
        "message": "Agent status recorded." if delivery else "Agent status logged.",
    }


def _system_reload_tasks(source, delivery, overwrite_allowed):
    """Built-in task that asks the worker to rediscover local task files."""
    logging.info("System task reload requested.")
    return {
        "success": True,
        "reload_tasks": True,
        "cleanup_source": False,
        "message": "Task registry reload requested.",
    }


def _system_shutdown(source, delivery, overwrite_allowed):
    """Built-in task that asks the worker process to stop gracefully."""
    logging.info("System shutdown requested.")
    return {
        "success": True,
        "stop_agent": True,
        "cleanup_source": False,
        "message": "Agent shutdown requested.",
    }


def _normalize_master_commit(value):
    """Return a usable master commit string, or an empty string when absent."""
    commit = str(value or "").strip()
    if not commit or commit.lower() in {"unknown", "none", "null"}:
        return ""
    return commit


def _git_versions_match(local_version, target_version):
    local = str(local_version or "").strip()
    target = str(target_version or "").strip()
    if not local or not target:
        return False
    if local == target:
        return True
    shortest = min(len(local), len(target))
    return shortest >= 7 and local[:shortest] == target[:shortest]


def _run_update_script(target_commit=None):
    """Run update.sh, optionally pinned to the master's exact commit."""
    if not UPDATE_SCRIPT_PATH.is_file():
        raise FileNotFoundError(f"Missing updater script: {UPDATE_SCRIPT_PATH}")

    command = ["bash", str(UPDATE_SCRIPT_PATH)]
    target_commit = _normalize_master_commit(target_commit)
    if target_commit:
        command.extend(["--commit", target_commit])
        logging.info("Updating worker to master commit %s with %s.", target_commit, UPDATE_SCRIPT_PATH)
    else:
        logging.info("Updating worker from GitHub latest branch with %s.", UPDATE_SCRIPT_PATH)

    result = subprocess.run(
        command,
        cwd=str(UPDATE_SCRIPT_PATH.parent),
        capture_output=True,
        text=True,
        timeout=60 * 60,
        check=False,
    )
    output = "\n".join(part.strip() for part in (result.stdout, result.stderr) if part.strip())
    output = output[-4000:]
    if result.returncode != 0:
        raise RuntimeError(f"Worker update failed with exit code {result.returncode}: {output or 'no output'}")
    return output


def _system_update_worker(source, delivery, overwrite_allowed):
    """Download worker code and reboot this computer after acknowledgement."""
    target_commit = _normalize_master_commit(source)
    output = _run_update_script(target_commit or None)

    return {
        "success": True,
        "reboot_system": True,
        "cleanup_source": False,
        "message": output or "Worker updated successfully. Reboot requested.",
    }

def _normalize_wake_broadcast(broadcast):
    value = str(broadcast or "").strip()
    if not value:
        return "255.255.255.255"

    lowered = value.lower()
    if lowered in {"default", "limited", "broadcast"}:
        return "255.255.255.255"

    # 255.255.255.0 is a subnet mask, not a usable Wake-on-LAN broadcast
    # address. UDP can still report success when sending to it, so normalize
    # the common mistake to the limited broadcast address.
    if value == "0.0.0.0" or _looks_like_ipv4_subnet_mask(value):
        return "255.255.255.255"

    return value


def _looks_like_ipv4_subnet_mask(value):
    try:
        address = ipaddress.IPv4Address(value)
    except ipaddress.AddressValueError:
        return False

    if str(address) == "255.255.255.255":
        return False

    bits = bin(int(address))[2:].zfill(32)
    return bits.startswith("1") and bits.endswith("0") and "01" not in bits


def _normalize_wake_job(source):
    """Parse legacy MAC lists and newer relay payloads from the master."""
    if not source:
        return [], "255.255.255.255", 9

    broadcast = "255.255.255.255"
    port = 9
    try:
        parsed = json.loads(source)
    except (TypeError, json.JSONDecodeError):
        parsed = [part.strip() for part in str(source).replace(",", "\n").splitlines()]

    if isinstance(parsed, dict):
        broadcast = _normalize_wake_broadcast(parsed.get("broadcast") or broadcast)
        try:
            port = int(parsed.get("port") or port)
        except (TypeError, ValueError):
            port = 9
        parsed_targets = parsed.get("targets", [])
    else:
        parsed_targets = parsed

    targets = []
    for entry in parsed_targets:
        if isinstance(entry, dict):
            mac = str(entry.get("mac", "")).strip()
        else:
            mac = str(entry).strip()
        if mac:
            targets.append(mac)

    port = max(1, min(65535, port))
    return targets, broadcast, port


def _send_wake_packet(mac_address, broadcast="255.255.255.255", port=9):
    broadcast = _normalize_wake_broadcast(broadcast)
    clean_mac = mac_address.replace(":", "").replace("-", "").replace(".", "")
    if len(clean_mac) != 12:
        raise ValueError(f"Invalid MAC address: {mac_address}")
    payload = bytes.fromhex("FF" * 6 + clean_mac * 16)
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as wol_socket:
        wol_socket.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        wol_socket.sendto(payload, (broadcast, port))


def _system_wake_farm(source, delivery, overwrite_allowed):
    """Built-in task that sends Wake-on-LAN packets for configured machines."""
    targets, broadcast, port = _normalize_wake_job(source)
    if not targets:
        raise ValueError("Wake-on-LAN task did not include any target MAC addresses.")

    errors = []
    sent = 0
    for mac in targets:
        try:
            _send_wake_packet(mac, broadcast, port)
            sent += 1
        except Exception as exc:
            errors.append(f"{mac}: {exc}")
            logging.warning("Wake-on-LAN failed for %s: %s", mac, exc)

    if errors:
        return {
            "success": False,
            "cleanup_source": False,
            "message": f"Sent {sent}/{len(targets)} Wake-on-LAN packet(s). Errors: {'; '.join(errors)}",
        }

    return {
        "success": True,
        "cleanup_source": False,
        "message": f"Sent Wake-on-LAN packets to {len(targets)} target(s) via {broadcast}:{port}.",
    }



def _system_storage_test(source, delivery, overwrite_allowed):
    """Built-in task that verifies worker credentials against the supplied storage server."""
    message = _storage_test_operation(_ACTIVE_TRANSFER_AUTH)
    logging.info(message)
    return {
        "success": True,
        "cleanup_source": False,
        "message": message,
    }

def _normalize_quarantine_purge_source(source):
    if isinstance(source, dict):
        payload = source
    else:
        try:
            payload = json.loads(str(source or ""))
        except (TypeError, json.JSONDecodeError):
            payload = {"uri": str(source or "")}
    if not isinstance(payload, dict):
        payload = {"uri": str(source or "")}
    uri = str(payload.get("uri") or payload.get("quarantine_uri") or "").strip()
    path = str(payload.get("path") or "").strip()
    if not uri and path:
        auth = _ACTIVE_TRANSFER_AUTH if isinstance(_ACTIVE_TRANSFER_AUTH, dict) else {}
        scheme = str(auth.get("scheme", "ftp")).lower()
        host = str(auth.get("host", "")).strip()
        default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
        port = int(auth.get("port") or default_port)
        netloc = host + (f":{port}" if port != default_port else "")
        if not path.startswith("/"):
            path = "/" + path
        uri = f"{scheme}://{netloc}{_quote_remote_path(path)}"
    if not uri:
        raise ValueError("Quarantine purge task did not include a quarantine URI.")
    parsed = urlparse(uri)
    quarantine_path = _transfer_uri_path(parsed)
    if not quarantine_path.endswith("/.reflection_quarantine") or ".." in quarantine_path:
        raise ValueError(f"Refusing to purge unsafe quarantine path: {quarantine_path}")
    return parsed, quarantine_path


def _purge_ftp_quarantine(parsed, quarantine_path, transfer_auth):
    deleted = 0
    skipped = 0
    freed = 0
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        names = []
        try:
            for name, facts in ftp.mlsd(quarantine_path):
                if name in {".", ".."}:
                    continue
                if isinstance(facts, dict) and str(facts.get("type", "file")).lower() not in {"file", ""}:
                    skipped += 1
                    continue
                names.append(name)
        except Exception:
            try:
                names = [Path(name).name for name in ftp.nlst(quarantine_path) if Path(name).name not in {".", ".."}]
            except Exception:
                names = []
        for name in names:
            remote_file = f"{quarantine_path.rstrip('/')}/{name}"
            size = _ftp_remote_size(ftp, remote_file) or 0
            try:
                ftp.delete(remote_file)
                deleted += 1
                freed += int(size)
            except Exception as exc:
                skipped += 1
                logging.info("Could not delete FTP quarantine file %s: %s", remote_file, exc)
        with contextlib.suppress(Exception):
            ftp.rmd(quarantine_path)
    return deleted, freed, skipped


def _purge_sftp_quarantine(parsed, quarantine_path, transfer_auth):
    deleted = 0
    skipped = 0
    freed = 0
    client, transport = _sftp_client(parsed, transfer_auth)
    try:
        try:
            entries = client.listdir_attr(quarantine_path)
        except Exception:
            entries = []
        for entry in entries:
            filename = entry.filename
            if filename in {".", ".."}:
                continue
            remote_file = f"{quarantine_path.rstrip('/')}/{filename}"
            try:
                # Quarantine should contain files only. Directories are skipped deliberately.
                client.remove(remote_file)
                deleted += 1
                freed += int(getattr(entry, "st_size", 0) or 0)
            except Exception as exc:
                skipped += 1
                logging.info("Could not delete SFTP quarantine file %s: %s", remote_file, exc)
        with contextlib.suppress(Exception):
            client.rmdir(quarantine_path)
    finally:
        client.close()
        transport.close()
    return deleted, freed, skipped


def _system_purge_quarantine(source, delivery, overwrite_allowed):
    """Built-in task that manually empties one tracked remote quarantine folder."""
    parsed, quarantine_path = _normalize_quarantine_purge_source(source)
    scheme = parsed.scheme.lower()
    if scheme in {"ftp", "ftps"}:
        deleted, freed, skipped = _purge_ftp_quarantine(parsed, quarantine_path, _ACTIVE_TRANSFER_AUTH)
    elif scheme == "sftp":
        deleted, freed, skipped = _purge_sftp_quarantine(parsed, quarantine_path, _ACTIVE_TRANSFER_AUTH)
    else:
        raise ValueError(f"Unsupported quarantine protocol: {scheme}")
    message = f"Purged quarantine folder {quarantine_path}: deleted {deleted} file(s), freed about {freed} byte(s)."
    if skipped:
        message += f" Skipped {skipped} item(s)."
    logging.info(message)
    return {
        "success": skipped == 0,
        "cleanup_source": False,
        "message": message,
    }

def built_in_tasks():
    """Return control tasks that are always available without task files."""
    return {
        "noop": TaskDefinition(
            name="noop",
            run=_system_noop,
            description="Built-in connectivity check that immediately succeeds.",
        ),
        "status": TaskDefinition(
            name="status",
            run=_system_status,
            description="Built-in health snapshot for the worker.",
        ),
        "reload_tasks": TaskDefinition(
            name="reload_tasks",
            run=_system_reload_tasks,
            description="Built-in control task that reloads the local task registry.",
        ),
        "shutdown": TaskDefinition(
            name="shutdown",
            run=_system_shutdown,
            description="Built-in control task that stops the worker after reporting success.",
        ),
        "update_worker": TaskDefinition(
            name="update_worker",
            run=_system_update_worker,
            description="Built-in control task that downloads updates and reboots this farm computer.",
        ),
        "wake_farm": TaskDefinition(
            name="wake_farm",
            run=_system_wake_farm,
            description="Built-in control task that sends Wake-on-LAN packets to configured farm computers.",
        ),
        "storage_test": TaskDefinition(
            name="storage_test",
            run=_system_storage_test,
            description="Built-in control task that tests storage server access with this worker's local login.",
        ),
        "purge_quarantine": TaskDefinition(
            name="purge_quarantine",
            run=_system_purge_quarantine,
            description="Built-in control task that manually empties a tracked remote overwrite quarantine folder.",
        ),
    }


def discover_tasks():
    """Load standardized task files plus reserved built-in system tasks."""
    registry = discover_task_definitions(
        TASKS_DIR,
        on_error=lambda path, exc: logging.error("Failed to load task file '%s': %s", path, exc),
    )

    built_ins = built_in_tasks()
    reserved_names = sorted(set(registry) & set(built_ins))
    if reserved_names:
        logging.warning(
            "Task files cannot override built-in task names: %s",
            ", ".join(reserved_names),
        )
    registry.update(built_ins)
    return registry


def _is_transfer_uri(value):
    """Return true when value is a supported transfer URI string."""
    if not value:
        return False
    return urlparse(str(value)).scheme.lower() in {"ftp", "ftps", "sftp"}


def _is_ftp_uri(value):
    """Return true when value is an FTP/FTPS URI string."""
    if not value:
        return False
    return urlparse(str(value)).scheme.lower() in {"ftp", "ftps"}


def _is_sftp_uri(value):
    """Return true when value is an SFTP URI string."""
    if not value:
        return False
    return urlparse(str(value)).scheme.lower() == "sftp"


def _transfer_server_is_usable(transfer_auth):
    """Return true when a shared storage server is described for this job."""
    if not isinstance(transfer_auth, dict):
        return False
    scheme = str(transfer_auth.get("scheme", "ftp")).lower()
    return scheme in {"ftp", "ftps", "sftp"} and bool(str(transfer_auth.get("host", "")).strip())


def _quote_remote_path(remote_path):
    """Quote a remote path while preserving directory separators."""
    return quote(str(remote_path), safe="/")


def _apply_transfer_root(remote_path, transfer_auth):
    """Apply an optional remote root/prefix to a worker-visible path."""
    path = str(remote_path or "").strip()
    root = str((transfer_auth or {}).get("root", "")).strip()
    if not root or root == "/":
        return path or "/"

    root = root.rstrip("/")
    if path.startswith(root + "/") or path == root:
        return path
    if path.startswith("/"):
        return root + path
    return root + "/" + path


def _transfer_uri_from_plain_path(value, transfer_auth):
    """Build a credential-free transfer URI for a remote path supplied by the master."""
    if value in (None, ""):
        return value
    if _is_transfer_uri(value):
        return value
    if not _transfer_server_is_usable(transfer_auth):
        return value

    scheme = str(transfer_auth.get("scheme", "ftp")).lower()
    host = str(transfer_auth.get("host", "")).strip()
    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    try:
        port = int(transfer_auth.get("port") or default_port)
    except (TypeError, ValueError):
        port = default_port

    remote_path = _apply_transfer_root(str(value), transfer_auth)
    if not remote_path.startswith("/"):
        remote_path = "/" + remote_path
    netloc = host
    if port and port != default_port:
        netloc = f"{netloc}:{port}"
    return f"{scheme}://{netloc}{_quote_remote_path(remote_path)}"


def _transfer_connection_details(parsed_uri, transfer_auth):
    """Resolve transfer connection settings using URI values first, then auth defaults."""
    auth = transfer_auth if isinstance(transfer_auth, dict) else {}
    scheme = (parsed_uri.scheme or str(auth.get("scheme", "ftp"))).lower()
    host = parsed_uri.hostname or str(auth.get("host", ""))
    if not host:
        raise ValueError(
            "Transfer URI must include a host or transfer_auth.host must be configured."
        )

    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    port = parsed_uri.port or int(auth.get("port") or default_port)
    username = unquote(parsed_uri.username or str(auth.get("username", "")))
    password = unquote(parsed_uri.password or str(auth.get("password", "")))
    if not username or not password:
        raise ValueError("Transfer credentials are required for farm file transfers.")

    return scheme, host, port, username, password



def cleanup_stale_worker_temp_dirs(max_age_hours):
    """Remove old Reflection temp folders left behind by crashes."""
    cutoff = time.time() - max(1, int(max_age_hours)) * 3600
    temp_root = Path(tempfile.gettempdir())
    patterns = ("reflection-*",)
    removed = 0
    for pattern in patterns:
        for candidate in temp_root.glob(pattern):
            try:
                if not candidate.is_dir() or candidate.stat().st_mtime > cutoff:
                    continue
                shutil.rmtree(candidate, ignore_errors=True)
                removed += 1
            except Exception as exc:
                logging.warning("Could not remove stale temp folder %s: %s", candidate, exc)
    if removed:
        logging.info("Removed %s stale Reflection temp folder(s).", removed)


def _ensure_local_space(directory, source_size=None):
    """Fail early if local temp storage is too small for a job."""
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    free = shutil.disk_usage(str(directory)).free
    required = int(MIN_FREE_SPACE_BYTES)
    if source_size is not None and source_size > 0:
        required = max(required, int(float(source_size) * MIN_FREE_SPACE_MULTIPLIER))
    if required > 0 and free < required:
        raise RuntimeError(
            f"Not enough local temp space. Free {free} bytes, required at least {required} bytes."
        )


def _ftp_remote_exists(ftp, remote_path):
    try:
        ftp.size(remote_path)
        return True
    except Exception:
        return False


def _ftp_remote_size(ftp, remote_path):
    try:
        size = ftp.size(remote_path)
        return int(size) if size is not None else None
    except Exception:
        return None


def _sftp_remote_exists(client, remote_path):
    try:
        client.stat(remote_path)
        return True
    except Exception:
        return False


def _sftp_remote_size(client, remote_path):
    try:
        return int(client.stat(remote_path).st_size)
    except Exception:
        return None


def _remote_side_paths(remote_path, task_id):
    remote = Path(remote_path)
    parent = str(remote.parent)
    if parent == ".":
        parent = ""
    basename = remote.name or "delivery"
    task_stamp = str(task_id or int(time.time()))
    time_stamp = time.strftime("%Y%m%d%H%M%S", time.gmtime())
    tmp_dir = (parent.rstrip("/") + "/.reflection_tmp").replace("//", "/") if parent else ".reflection_tmp"
    quarantine_dir = (parent.rstrip("/") + "/.reflection_quarantine").replace("//", "/") if parent else ".reflection_quarantine"
    tmp_path = f"{tmp_dir}/{basename}.{task_stamp}.tmp"
    quarantine_path = f"{quarantine_dir}/{basename}.{time_stamp}.{task_stamp}.original"
    return tmp_dir, tmp_path, quarantine_dir, quarantine_path

def _remote_quarantine_uri(parsed_uri, transfer_auth, quarantine_dir):
    auth = transfer_auth if isinstance(transfer_auth, dict) else {}
    scheme = (parsed_uri.scheme or str(auth.get("scheme", "ftp"))).lower()
    host = parsed_uri.hostname or str(auth.get("host", "")).strip()
    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    port = parsed_uri.port or int(auth.get("port") or default_port)
    path = str(quarantine_dir or "").strip()
    if not path.startswith("/"):
        path = "/" + path
    netloc = host
    if port and int(port) != default_port:
        netloc = f"{netloc}:{int(port)}"
    return f"{scheme}://{netloc}{_quote_remote_path(path)}"


def _report_quarantine_location(agent, parsed_uri, transfer_auth, quarantine_dir, task_id, stats=None):
    """Best-effort report of a remote quarantine folder back to the master."""
    if agent is None or not hasattr(agent, "report_quarantine_location"):
        return
    auth = transfer_auth if isinstance(transfer_auth, dict) else {}
    scheme = (parsed_uri.scheme or str(auth.get("scheme", "ftp"))).lower()
    host = parsed_uri.hostname or str(auth.get("host", "")).strip()
    if not host:
        return
    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    try:
        port = int(parsed_uri.port or auth.get("port") or default_port)
    except (TypeError, ValueError):
        port = default_port
    path = str(quarantine_dir or "").strip()
    if not path.startswith("/"):
        path = "/" + path
    stats = stats if isinstance(stats, dict) else {}
    payload = {
        "scheme": scheme,
        "host": host,
        "port": port,
        "path": path,
        "uri": _remote_quarantine_uri(parsed_uri, auth, path),
        "server_id": str(auth.get("id", "") or ""),
        "task_id": str(task_id or ""),
        "file_count": int(stats.get("file_count") or 0),
        "size_bytes": int(stats.get("size_bytes") or 0),
    }
    try:
        agent.report_quarantine_location(payload)
    except Exception as exc:
        logging.info("Could not report quarantine folder %s: %s", payload.get("uri"), exc)

def _ftp_connection(parsed_uri, transfer_auth):
    """Open an FTP/FTPS connection using URI credentials first, then task auth."""
    scheme, host, port, username, password = _transfer_connection_details(parsed_uri, transfer_auth)
    if scheme not in {"ftp", "ftps"}:
        raise ValueError(f"Unsupported FTP scheme: {scheme}")
    ftp_class = ftplib.FTP_TLS if scheme == "ftps" else ftplib.FTP

    ftp = ftp_class()
    ftp.connect(host, port, timeout=30)
    ftp.login(username, password)
    if isinstance(ftp, ftplib.FTP_TLS):
        ftp.prot_p()
    return ftp


def _sftp_client(parsed_uri, transfer_auth):
    """Open an SFTP client using URI credentials first, then task auth."""
    if importlib.util.find_spec("paramiko") is None:
        raise RuntimeError("SFTP transfers require the optional 'paramiko' package.")
    import paramiko

    scheme, host, port, username, password = _transfer_connection_details(parsed_uri, transfer_auth)
    if scheme != "sftp":
        raise ValueError(f"Unsupported SFTP scheme: {scheme}")

    transport = paramiko.Transport((host, port))
    transport.connect(username=username, password=password)
    client = paramiko.SFTPClient.from_transport(transport)
    return client, transport


def _transfer_uri_path(parsed_uri):
    """Decode and validate the path component from a transfer URI."""
    remote_path = unquote(parsed_uri.path or "")
    if remote_path in {"", "/"}:
        raise ValueError("Transfer URI must point at a file path.")
    return remote_path


def _ftp_uri_path(parsed_uri):
    """Decode and validate the path component from an FTP URI."""
    return _transfer_uri_path(parsed_uri)


def _download_ftp_file(uri, transfer_auth, local_directory):
    """Download one FTP/FTPS file into local_directory and return the local path."""
    parsed = urlparse(str(uri))
    remote_path = _ftp_uri_path(parsed)
    local_name = Path(remote_path).name or "source"
    local_path = local_directory / local_name

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Downloading FTP source %s to local worker storage.", safe_uri)
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        _ensure_local_space(local_directory, _ftp_remote_size(ftp, remote_path))
        with local_path.open("wb") as local_file:
            ftp.retrbinary(f"RETR {remote_path}", local_file.write)
    return str(local_path)


def _download_sftp_file(uri, transfer_auth, local_directory):
    """Download one SFTP file into local_directory and return the local path."""
    parsed = urlparse(str(uri))
    remote_path = _transfer_uri_path(parsed)
    local_name = Path(remote_path).name or "source"
    local_path = local_directory / local_name

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Downloading SFTP source %s to local worker storage.", safe_uri)
    client, transport = _sftp_client(parsed, transfer_auth)
    try:
        _ensure_local_space(local_directory, _sftp_remote_size(client, remote_path))
        client.get(remote_path, str(local_path))
    finally:
        client.close()
        transport.close()
    return str(local_path)


def _ensure_ftp_directory(ftp, remote_directory):
    """Create remote FTP directories when they are missing."""
    if remote_directory in {"", "/", "."}:
        return

    current = "" if remote_directory.startswith("/") else ftp.pwd()
    for part in remote_directory.strip("/").split("/"):
        if not part:
            continue
        current = f"{current}/{part}" if current else part
        with contextlib.suppress(ftplib.error_perm):
            ftp.mkd(current)


def _file_md5(path):
    """Return an MD5 checksum for a local file."""
    digest = hashlib.md5()
    with Path(path).open("rb") as file_obj:
        for chunk in iter(lambda: file_obj.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _ftp_remote_md5(ftp, remote_path):
    """Return an MD5 checksum for a remote FTP/FTPS file by reading it back."""
    digest = hashlib.md5()
    ftp.retrbinary(f"RETR {remote_path}", digest.update)
    return digest.hexdigest()


def _verify_ftp_upload_md5(ftp, local_path, remote_path):
    """Verify an FTP/FTPS upload by comparing local and remote MD5 checksums."""
    local_md5 = _file_md5(local_path)
    remote_md5 = _ftp_remote_md5(ftp, remote_path)
    if remote_md5 != local_md5:
        raise RuntimeError(
            "FTP delivery upload verification failed: "
            f"MD5 mismatch for {remote_path} (local {local_md5}, remote {remote_md5})."
        )
    logging.info("FTP delivery upload verified by MD5: %s", remote_path)


def _upload_ftp_file(local_path, uri, transfer_auth, overwrite_allowed=False):
    """Upload one local file to an FTP/FTPS URI and verify it by MD5."""
    parsed = urlparse(str(uri))
    remote_path = _ftp_uri_path(parsed)
    source_path = Path(local_path)
    if not source_path.is_file():
        raise FileNotFoundError(f"FTP delivery upload expected a file: {source_path}")

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Uploading task delivery to FTP target %s.", safe_uri)
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        _ensure_ftp_directory(ftp, str(Path(remote_path).parent))
        if not overwrite_allowed and _ftp_remote_exists(ftp, remote_path):
            raise FileExistsError(f"Remote delivery exists and overwrite is disabled: {remote_path}")
        with source_path.open("rb") as source_file:
            ftp.storbinary(f"STOR {remote_path}", source_file)
        _verify_ftp_upload_md5(ftp, source_path, remote_path)


def _ensure_sftp_directory(client, remote_directory):
    """Create remote SFTP directories when they are missing."""
    if remote_directory in {"", "/", "."}:
        return

    current = "" if remote_directory.startswith("/") else "."
    for part in remote_directory.strip("/").split("/"):
        if not part:
            continue
        current = f"{current}/{part}" if current not in {"", "."} else part
        if remote_directory.startswith("/"):
            current_path = f"/{current}".replace("//", "/")
        else:
            current_path = current
        with contextlib.suppress(OSError):
            client.mkdir(current_path)


def _sftp_remote_md5(client, remote_path):
    """Return an MD5 checksum for a remote SFTP file by reading it back."""
    digest = hashlib.md5()
    with client.open(remote_path, "rb") as remote_file:
        for chunk in iter(lambda: remote_file.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _verify_sftp_upload_md5(client, local_path, remote_path):
    """Verify an SFTP upload by comparing local and remote MD5 checksums."""
    local_md5 = _file_md5(local_path)
    remote_md5 = _sftp_remote_md5(client, remote_path)
    if remote_md5 != local_md5:
        raise RuntimeError(
            "SFTP delivery upload verification failed: "
            f"MD5 mismatch for {remote_path} (local {local_md5}, remote {remote_md5})."
        )
    logging.info("SFTP delivery upload verified by MD5: %s", remote_path)


def _upload_sftp_file(local_path, uri, transfer_auth, overwrite_allowed=False):
    """Upload one local file to an SFTP URI and verify it by MD5."""
    parsed = urlparse(str(uri))
    remote_path = _transfer_uri_path(parsed)
    source_path = Path(local_path)
    if not source_path.is_file():
        raise FileNotFoundError(f"SFTP delivery upload expected a file: {source_path}")

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Uploading task delivery to SFTP target %s.", safe_uri)
    client, transport = _sftp_client(parsed, transfer_auth)
    try:
        _ensure_sftp_directory(client, str(Path(remote_path).parent))
        if not overwrite_allowed and _sftp_remote_exists(client, remote_path):
            raise FileExistsError(f"Remote delivery exists and overwrite is disabled: {remote_path}")
        client.put(str(source_path), remote_path)
        _verify_sftp_upload_md5(client, source_path, remote_path)
    finally:
        client.close()
        transport.close()




def _call_upload_ftp_file(local_path, uri, transfer_auth, overwrite_allowed=False):
    try:
        return _upload_ftp_file(local_path, uri, transfer_auth, overwrite_allowed=overwrite_allowed)
    except TypeError as exc:
        if "overwrite_allowed" not in str(exc):
            raise
        # Backward-compatible for tests/plugins that monkeypatch the old 3-arg helper.
        return _upload_ftp_file(local_path, uri, transfer_auth)


def _call_upload_sftp_file(local_path, uri, transfer_auth, overwrite_allowed=False):
    try:
        return _upload_sftp_file(local_path, uri, transfer_auth, overwrite_allowed=overwrite_allowed)
    except TypeError as exc:
        if "overwrite_allowed" not in str(exc):
            raise
        return _upload_sftp_file(local_path, uri, transfer_auth)

def _safe_replace_ftp_file(local_path, uri, transfer_auth, task_id, agent=None):
    """Upload to temp, verify, move original to quarantine, then rename temp into place."""
    parsed = urlparse(str(uri))
    remote_path = _ftp_uri_path(parsed)
    source_path = Path(local_path)
    if not source_path.is_file():
        raise FileNotFoundError(f"FTP safe replace expected a file: {source_path}")

    tmp_dir, tmp_path, quarantine_dir, quarantine_path = _remote_side_paths(remote_path, task_id)
    logging.info("Safely replacing FTP target %s via temp upload %s.", remote_path, tmp_path)
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        _ensure_ftp_directory(ftp, tmp_dir)
        _ensure_ftp_directory(ftp, quarantine_dir)
        _cleanup_ftp_quarantine(ftp, quarantine_dir, reserve_bytes=source_path.stat().st_size)
        with source_path.open("rb") as source_file:
            ftp.storbinary(f"STOR {tmp_path}", source_file)
        _verify_ftp_upload_md5(ftp, source_path, tmp_path)
        if _ftp_remote_exists(ftp, remote_path):
            with contextlib.suppress(Exception):
                ftp.delete(quarantine_path)
            ftp.rename(remote_path, quarantine_path)
        ftp.rename(tmp_path, remote_path)
        _verify_ftp_upload_md5(ftp, source_path, remote_path)
        stats = _cleanup_ftp_quarantine(ftp, quarantine_dir)
        _report_quarantine_location(agent, parsed, transfer_auth, quarantine_dir, task_id, stats)


def _safe_replace_sftp_file(local_path, uri, transfer_auth, task_id, agent=None):
    """Upload to temp, verify, move original to quarantine, then rename temp into place."""
    parsed = urlparse(str(uri))
    remote_path = _transfer_uri_path(parsed)
    source_path = Path(local_path)
    if not source_path.is_file():
        raise FileNotFoundError(f"SFTP safe replace expected a file: {source_path}")

    tmp_dir, tmp_path, quarantine_dir, quarantine_path = _remote_side_paths(remote_path, task_id)
    logging.info("Safely replacing SFTP target %s via temp upload %s.", remote_path, tmp_path)
    client, transport = _sftp_client(parsed, transfer_auth)
    try:
        _ensure_sftp_directory(client, tmp_dir)
        _ensure_sftp_directory(client, quarantine_dir)
        _cleanup_sftp_quarantine(client, quarantine_dir, reserve_bytes=source_path.stat().st_size)
        client.put(str(source_path), tmp_path)
        _verify_sftp_upload_md5(client, source_path, tmp_path)
        if _sftp_remote_exists(client, remote_path):
            with contextlib.suppress(Exception):
                client.remove(quarantine_path)
            client.rename(remote_path, quarantine_path)
        client.rename(tmp_path, remote_path)
        _verify_sftp_upload_md5(client, source_path, remote_path)
        stats = _cleanup_sftp_quarantine(client, quarantine_dir)
        _report_quarantine_location(agent, parsed, transfer_auth, quarantine_dir, task_id, stats)
    finally:
        client.close()
        transport.close()



def _parse_quarantine_timestamp(name, fallback_mtime=None):
    """Return quarantine creation-ish time from the filename, falling back to server mtime."""
    for part in reversed(str(name).split(".")):
        if len(part) == 14 and part.isdigit():
            try:
                return time.mktime(time.strptime(part, "%Y%m%d%H%M%S"))
            except Exception:
                pass
    if fallback_mtime is not None:
        return float(fallback_mtime)
    return time.time()


def _cleanup_quarantine_entries(entries, remove_func, label, reserve_bytes=0):
    """Delete old quarantine files and enforce the configured size ceiling."""
    cutoff = time.time() - max(1, int(QUARANTINE_KEEP_DAYS)) * 86400
    max_bytes = max(0, int(QUARANTINE_MAX_BYTES))
    reserve_bytes = max(0, int(reserve_bytes or 0))
    remaining = []
    deleted = 0
    freed = 0

    for entry in sorted(entries, key=lambda item: item.get("mtime", time.time())):
        if entry.get("mtime", time.time()) < cutoff:
            if remove_func(entry["path"]):
                deleted += 1
                freed += int(entry.get("size") or 0)
                entry["_deleted"] = True
                continue
        remaining.append(entry)

    if max_bytes > 0:
        target_bytes = max(0, max_bytes - reserve_bytes)
        known_total = sum(int(entry.get("size") or 0) for entry in remaining if entry.get("size") is not None)
        unknown_count = sum(1 for entry in remaining if entry.get("size") is None)
        for entry in sorted(remaining, key=lambda item: item.get("mtime", time.time())):
            if known_total <= target_bytes:
                break
            if remove_func(entry["path"]):
                deleted += 1
                entry["_deleted"] = True
                entry_size = int(entry.get("size") or 0)
                freed += entry_size
                known_total = max(0, known_total - entry_size)
        if unknown_count:
            logging.info(
                "%s quarantine size cap could not count %s file(s) because the server did not report their size.",
                label,
                unknown_count,
            )

    kept_entries = [entry for entry in remaining if not entry.get("_deleted")]
    if deleted:
        logging.info("%s quarantine cleanup removed %s file(s), freeing about %s bytes.", label, deleted, freed)
    return {
        "deleted": deleted,
        "freed_bytes": freed,
        "file_count": len(kept_entries),
        "size_bytes": sum(int(entry.get("size") or 0) for entry in kept_entries if entry.get("size") is not None),
    }

def _cleanup_ftp_quarantine(ftp, quarantine_dir, reserve_bytes=0):
    """Best-effort cleanup of old or over-budget FTP quarantine files."""
    try:
        entries = []
        for name, facts in ftp.mlsd(quarantine_dir):
            if name in {".", ".."}:
                continue
            path = f"{quarantine_dir.rstrip('/')}/{name}"
            modified = facts.get("modify") if isinstance(facts, dict) else None
            fallback_mtime = None
            if modified and len(modified) >= 14:
                try:
                    fallback_mtime = time.mktime(time.strptime(modified[:14], "%Y%m%d%H%M%S"))
                except Exception:
                    fallback_mtime = None
            size = None
            if isinstance(facts, dict) and str(facts.get("type", "file")).lower() not in {"file", ""}:
                continue
            if isinstance(facts, dict) and str(facts.get("size", "")).strip().isdigit():
                size = int(facts.get("size"))
            if size is None:
                size = _ftp_remote_size(ftp, path)
            entries.append({
                "name": name,
                "path": path,
                "mtime": _parse_quarantine_timestamp(name, fallback_mtime),
                "size": size,
            })

        def remove(path):
            try:
                ftp.delete(path)
                return True
            except Exception as exc:
                logging.info("Could not delete FTP quarantine file %s: %s", path, exc)
                return False

        return _cleanup_quarantine_entries(entries, remove, "FTP", reserve_bytes=reserve_bytes)
    except Exception as exc:
        logging.info("FTP quarantine cleanup skipped for %s: %s", quarantine_dir, exc)
        return {"deleted": 0, "freed_bytes": 0, "file_count": 0, "size_bytes": 0}


def _cleanup_sftp_quarantine(client, quarantine_dir, reserve_bytes=0):
    """Best-effort cleanup of old or over-budget SFTP quarantine files."""
    try:
        entries = []
        for entry in client.listdir_attr(quarantine_dir):
            filename = entry.filename
            if filename in {".", ".."}:
                continue
            path = f"{quarantine_dir.rstrip('/')}/{filename}"
            entries.append({
                "name": filename,
                "path": path,
                "mtime": _parse_quarantine_timestamp(filename, getattr(entry, "st_mtime", None)),
                "size": int(getattr(entry, "st_size", 0)) if getattr(entry, "st_size", None) is not None else None,
            })

        def remove(path):
            try:
                client.remove(path)
                return True
            except Exception as exc:
                logging.info("Could not delete SFTP quarantine file %s: %s", path, exc)
                return False

        return _cleanup_quarantine_entries(entries, remove, "SFTP", reserve_bytes=reserve_bytes)
    except Exception as exc:
        logging.info("SFTP quarantine cleanup skipped for %s: %s", quarantine_dir, exc)
        return {"deleted": 0, "freed_bytes": 0, "file_count": 0, "size_bytes": 0}

def _storage_test_operation(transfer_auth):
    """Verify that this worker can create, verify, rename, and delete a remote file."""
    if not _transfer_server_is_usable(transfer_auth):
        raise RuntimeError("No usable transfer server was supplied for the storage test.")
    scheme = str(transfer_auth.get("scheme", "ftp")).lower()
    host = str(transfer_auth.get("host", "")).strip()
    default_port = 22 if scheme == "sftp" else (990 if scheme == "ftps" else 21)
    port = int(transfer_auth.get("port") or default_port)
    root = str(transfer_auth.get("root", "")).strip().rstrip("/")
    base = (root + "/.reflection_tests") if root else "/.reflection_tests"
    test_name = f"{PC_ID}-{int(time.time())}.txt".replace("/", "_")
    remote_path = f"{base}/{test_name}"
    renamed_path = f"{base}/{test_name}.renamed"
    with tempfile.NamedTemporaryFile("w", delete=False, encoding="utf-8") as handle:
        local = Path(handle.name)
        handle.write(f"Reflection storage test from {PC_ID} at {time.time()}\n")
    try:
        uri = f"{scheme}://{host}:{port}{_quote_remote_path(remote_path)}"
        renamed_uri = f"{scheme}://{host}:{port}{_quote_remote_path(renamed_path)}"
        if scheme in {"ftp", "ftps"}:
            parsed = urlparse(uri)
            with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
                _ensure_ftp_directory(ftp, base)
                with local.open("rb") as source_file:
                    ftp.storbinary(f"STOR {remote_path}", source_file)
                _verify_ftp_upload_md5(ftp, local, remote_path)
                ftp.rename(remote_path, renamed_path)
                _verify_ftp_upload_md5(ftp, local, renamed_path)
                ftp.delete(renamed_path)
        elif scheme == "sftp":
            parsed = urlparse(uri)
            client, transport = _sftp_client(parsed, transfer_auth)
            try:
                _ensure_sftp_directory(client, base)
                client.put(str(local), remote_path)
                _verify_sftp_upload_md5(client, local, remote_path)
                client.rename(remote_path, renamed_path)
                _verify_sftp_upload_md5(client, local, renamed_path)
                client.remove(renamed_path)
            finally:
                client.close(); transport.close()
        else:
            raise RuntimeError(f"Unsupported storage-test scheme: {scheme}")
        return f"Storage test passed for {scheme}://{host}:{port}{base}. Created, verified, renamed, and deleted a test file."
    finally:
        with contextlib.suppress(Exception):
            local.unlink()

def _transfer_uri_with_child(uri, child_name):
    """Return a transfer URI with child_name appended to its path."""
    parsed = urlparse(str(uri))
    base_path = parsed.path or "/"
    separator = "" if base_path.endswith("/") else "/"
    return parsed._replace(path=f"{base_path}{separator}{quote(child_name)}").geturl()


def _delivery_uri_points_to_directory(uri):
    """Return true when a transfer delivery URI looks like a directory target."""
    parsed = urlparse(str(uri))
    remote_path = unquote(parsed.path or "")
    if remote_path.endswith("/"):
        return True

    return Path(remote_path).suffix == ""


def _transfer_delivery_target(delivery, prepared_source, temp_path):
    """Return local delivery path and final upload URI for transfer delivery."""
    parsed_delivery = urlparse(str(delivery))
    remote_path = unquote(parsed_delivery.path or "")
    source_name = Path(str(prepared_source)).name or "delivery"

    if _delivery_uri_points_to_directory(delivery):
        delivery_name = source_name
        upload_delivery = _transfer_uri_with_child(delivery, delivery_name)
    else:
        delivery_name = Path(remote_path or "delivery").name or source_name
        upload_delivery = delivery

    return str(temp_path / "delivery" / delivery_name), upload_delivery


def _prepare_transfer_paths(
    source,
    delivery,
    task_id,
    transfer_auth,
    use_transfer_server_for_plain_paths=False,
):
    """Convert remote transfer paths into local task paths and final upload URI."""
    temp_directory = None
    prepared_source = source
    prepared_delivery = delivery
    upload_delivery = delivery

    if use_transfer_server_for_plain_paths:
        source = _transfer_uri_from_plain_path(source, transfer_auth)
        delivery = _transfer_uri_from_plain_path(delivery, transfer_auth)

    if _is_transfer_uri(source) or _is_transfer_uri(delivery):
        temp_directory = tempfile.TemporaryDirectory(
            prefix=f"reflection-{task_id or 'task'}-"
        )
        temp_path = Path(temp_directory.name)

    if _is_ftp_uri(source):
        prepared_source = _download_ftp_file(source, transfer_auth, temp_path)
    elif _is_sftp_uri(source):
        prepared_source = _download_sftp_file(source, transfer_auth, temp_path)

    if _is_transfer_uri(delivery):
        prepared_delivery, upload_delivery = _transfer_delivery_target(
            delivery,
            prepared_source,
            temp_path,
        )

    return prepared_source, prepared_delivery, upload_delivery, temp_directory


def _task_timeout_for(module_name):
    """Return the timeout for one task. Zero disables the timeout."""
    specific = TASK_TIMEOUTS.get(module_name)
    if specific is None:
        specific = TASK_TIMEOUTS.get("default", TASK_TIMEOUT_SECONDS)
    try:
        return max(0, int(specific))
    except (TypeError, ValueError):
        return max(0, int(TASK_TIMEOUT_SECONDS))


def _read_tail(path, max_bytes=None):
    """Read the tail of a text log file without letting it grow memory usage."""
    max_bytes = int(max_bytes or TASK_LOG_TAIL_BYTES)
    log_path = Path(path)
    if not log_path.is_file():
        return ""

    try:
        with log_path.open("rb") as log_file:
            log_file.seek(0, os.SEEK_END)
            size = log_file.tell()
            log_file.seek(max(0, size - max_bytes))
            data = log_file.read()
        text = data.decode("utf-8", errors="replace").strip()
        if size > max_bytes:
            return "... log truncated ...\n" + text
        return text
    except Exception as exc:
        return f"Could not read task log {log_path}: {exc}"


def _process_group_kwargs():
    """Return subprocess kwargs that let us terminate child process trees."""
    if os.name == "nt":
        return {"creationflags": getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)}
    return {"start_new_session": True}


def _task_terminal_commands(title, viewer_command):
    """Return supported terminal emulator commands for a task log viewer."""
    return [
        ["x-terminal-emulator", "-T", title, "-e", *viewer_command],
        ["gnome-terminal", f"--title={title}", "--", *viewer_command],
        ["konsole", "-p", f"tabtitle={title}", "-e", *viewer_command],
        ["xfce4-terminal", f"--title={title}", "-x", *viewer_command],
        ["mate-terminal", f"--title={title}", "--", *viewer_command],
        ["lxterminal", f"--title={title}", "-e", shlex.join(viewer_command)],
        ["xterm", "-T", title, "-e", *viewer_command],
    ]


def _launch_task_log_terminal(module_name, task_id, stdout_path, stderr_path, done_path):
    """Best-effort launch of a visible terminal that closes when the task ends."""
    if not SHOW_TASK_TERMINAL:
        return False
    if not TASK_LOG_VIEWER_PATH.is_file():
        logging.warning("Cannot open task terminal because log viewer is missing: %s", TASK_LOG_VIEWER_PATH)
        return False

    title = f"Reflection Task {task_id or 'task'} - {module_name}"
    viewer_command = [
        sys.executable,
        str(TASK_LOG_VIEWER_PATH),
        "--title",
        title,
        "--stdout-log",
        str(stdout_path),
        "--stderr-log",
        str(stderr_path),
        "--done-file",
        str(done_path),
    ]
    for command in _task_terminal_commands(title, viewer_command):
        if shutil.which(command[0]) is None:
            continue
        try:
            subprocess.Popen(
                command,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                start_new_session=True,
            )
            logging.info("Opened visible task terminal for %s using %s.", module_name, command[0])
            return True
        except OSError as exc:
            logging.warning("Could not open task terminal with %s: %s", command[0], exc)

    logging.warning("Could not open visible task terminal for %s: no supported terminal emulator was found.", module_name)
    return False


def _terminate_process_tree(process):
    """Best-effort termination of a task runner and its child processes."""
    if process.poll() is not None:
        return

    try:
        if os.name == "nt":
            subprocess.run(
                ["taskkill", "/F", "/T", "/PID", str(process.pid)],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                check=False,
            )
        else:
            os.killpg(process.pid, 15)
    except Exception:
        with contextlib.suppress(Exception):
            process.terminate()

    try:
        process.wait(timeout=10)
        return
    except subprocess.TimeoutExpired:
        pass

    try:
        if os.name == "nt":
            subprocess.run(
                ["taskkill", "/F", "/T", "/PID", str(process.pid)],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                check=False,
            )
        else:
            os.killpg(process.pid, 9)
    except Exception:
        with contextlib.suppress(Exception):
            process.kill()

    with contextlib.suppress(Exception):
        process.wait(timeout=5)


def _load_task_runner_result(result_path):
    """Read and normalize the JSON result emitted by task_runner.py."""
    try:
        with Path(result_path).open(encoding="utf-8") as result_file:
            result = json.load(result_file)
    except FileNotFoundError:
        raise RuntimeError("Task runner did not produce a result file.")
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"Task runner produced invalid JSON result: {exc}") from exc

    return _normalize_task_result(result)


def _format_isolated_failure(prefix, stdout_path, stderr_path):
    """Build a useful error message from isolated task logs."""
    stderr_tail = _read_tail(stderr_path)
    stdout_tail = _read_tail(stdout_path)
    parts = [prefix]
    if stderr_tail:
        parts.append("stderr tail:\n" + stderr_tail)
    if stdout_tail:
        parts.append("stdout tail:\n" + stdout_tail)
    return "\n\n".join(parts)


def _run_task_in_subprocess(module_name, source, delivery, overwrite_allowed, task_id, workspace_root, cancellation_event=None):
    """Run an external task in an isolated subprocess so the worker survives task crashes."""
    if not TASK_RUNNER_PATH.is_file():
        raise FileNotFoundError(f"Missing task runner: {TASK_RUNNER_PATH}")

    timeout_seconds = _task_timeout_for(module_name)
    runtime_dir = Path(workspace_root) / "runner"
    runtime_dir.mkdir(parents=True, exist_ok=True)
    result_path = runtime_dir / "result.json"
    stdout_path = runtime_dir / "stdout.log"
    stderr_path = runtime_dir / "stderr.log"
    done_path = runtime_dir / "done"

    command = [
        sys.executable,
        str(TASK_RUNNER_PATH),
        "--tasks-dir",
        str(TASKS_DIR),
        "--module",
        str(module_name),
        "--source",
        str(source or ""),
        "--delivery",
        str(delivery or ""),
        "--result-file",
        str(result_path),
    ]
    if overwrite_allowed:
        command.append("--overwrite-allowed")

    logging.info(
        "Starting isolated task process for %s%s.",
        module_name,
        f" with timeout {timeout_seconds}s" if timeout_seconds else " without timeout",
    )

    with stdout_path.open("wb") as stdout_file, stderr_path.open("wb") as stderr_file:
        process = subprocess.Popen(
            command,
            cwd=str(Path(__file__).parent),
            stdout=stdout_file,
            stderr=stderr_file,
            **_process_group_kwargs(),
        )
        _launch_task_log_terminal(module_name, task_id, stdout_path, stderr_path, done_path)
        try:
            started_at = time.monotonic()
            while process.poll() is None:
                if cancellation_event is not None and cancellation_event.wait(1):
                    _terminate_process_tree(process)
                    message = f"Task '{module_name}' was relinquished by the master and its process was killed."
                    logging.warning(message)
                    return TaskOutcome(success=False, relinquished=True, message=message)
                if timeout_seconds and time.monotonic() - started_at >= timeout_seconds:
                    _terminate_process_tree(process)
                    message = _format_isolated_failure(
                        f"Task '{module_name}' timed out after {timeout_seconds} seconds and was killed.",
                        stdout_path,
                        stderr_path,
                    )
                    logging.error(message)
                    return TaskOutcome(success=False, message=message)
        finally:
            done_path.touch()

    stdout_tail = _read_tail(stdout_path)
    stderr_tail = _read_tail(stderr_path)
    if stdout_tail:
        logging.info("Task %s stdout tail:\n%s", module_name, stdout_tail)
    if stderr_tail:
        logging.warning("Task %s stderr tail:\n%s", module_name, stderr_tail)

    if result_path.exists():
        try:
            outcome = _load_task_runner_result(result_path)
            if process.returncode != 0 and not outcome.message:
                return TaskOutcome(
                    success=False,
                    message=f"Task '{module_name}' exited with code {process.returncode}.",
                )
            if process.returncode != 0 and outcome.success:
                return TaskOutcome(
                    success=False,
                    message=(
                        f"Task '{module_name}' reported success but exited with code "
                        f"{process.returncode}: {outcome.message}"
                    ),
                )
            return outcome
        except Exception as exc:
            message = _format_isolated_failure(
                f"Task '{module_name}' finished but its result could not be read: {exc}",
                stdout_path,
                stderr_path,
            )
            logging.error(message)
            return TaskOutcome(success=False, message=message)

    if process.returncode != 0:
        message = _format_isolated_failure(
            f"Task '{module_name}' crashed or exited with code {process.returncode} before writing a result.",
            stdout_path,
            stderr_path,
        )
        logging.error(message)
        return TaskOutcome(success=False, message=message)

    message = _format_isolated_failure(
        f"Task '{module_name}' exited successfully but did not write a result file.",
        stdout_path,
        stderr_path,
    )
    logging.error(message)
    return TaskOutcome(success=False, message=message)


def _run_task_with_transfer_handling(
    agent,
    module_name,
    source,
    delivery,
    overwrite_allowed,
    task_id,
    transfer_auth,
    use_transfer_server_for_plain_paths=False,
    cancellation_event=None,
):
    """Run one task and treat download/upload/process errors as task failures."""
    transfer_workspace = None
    execution_workspace = None
    try:
        (
            prepared_source,
            prepared_delivery,
            upload_delivery,
            transfer_workspace,
        ) = _prepare_transfer_paths(
            source,
            delivery,
            task_id,
            transfer_auth,
            use_transfer_server_for_plain_paths,
        )

        if transfer_workspace is not None:
            workspace_root = Path(transfer_workspace.name)
        else:
            execution_workspace = tempfile.TemporaryDirectory(prefix=f"reflection-runner-{task_id or 'task'}-")
            workspace_root = Path(execution_workspace.name)

        should_isolate = (
            TASK_ISOLATION
            and hasattr(agent, "task_registry")
            and module_name not in built_in_tasks()
        )
        global _ACTIVE_TRANSFER_AUTH
        _ACTIVE_TRANSFER_AUTH = dict(transfer_auth or {})
        try:
            if should_isolate:
                task_outcome = _run_task_in_subprocess(
                    module_name,
                    prepared_source,
                    prepared_delivery,
                    overwrite_allowed,
                    task_id,
                    workspace_root,
                    cancellation_event,
                )
            else:
                task_outcome = agent.run_task(
                    module_name,
                    prepared_source,
                    prepared_delivery,
                    overwrite_allowed,
                )
        finally:
            _ACTIVE_TRANSFER_AUTH = {}

        if cancellation_event is not None and cancellation_event.is_set():
            return TaskOutcome(
                success=False,
                relinquished=True,
                message=f"Task '{module_name}' was relinquished by the master; temporary files were removed.",
            )

        if task_outcome.success and _is_ftp_uri(upload_delivery):
            if overwrite_allowed:
                _safe_replace_ftp_file(prepared_delivery, upload_delivery, transfer_auth, task_id, agent)
            else:
                _call_upload_ftp_file(prepared_delivery, upload_delivery, transfer_auth, overwrite_allowed=False)
        elif task_outcome.success and _is_sftp_uri(upload_delivery):
            if overwrite_allowed:
                _safe_replace_sftp_file(prepared_delivery, upload_delivery, transfer_auth, task_id, agent)
            else:
                _call_upload_sftp_file(prepared_delivery, upload_delivery, transfer_auth, overwrite_allowed=False)
        return task_outcome
    except Exception as e:
        error_message = str(e)
        logging.exception("Execution failed on module '%s': %s", module_name, error_message)
        return TaskOutcome(success=False, message=error_message)
    finally:
        if transfer_workspace is not None:
            transfer_workspace.cleanup()
        if execution_workspace is not None:
            execution_workspace.cleanup()


class TaskHeartbeat:
    """Send best-effort progress heartbeats while a long task is running."""

    def __init__(self, agent, task_id, interval):
        self.agent = agent
        self.task_id = task_id
        self.interval = max(5, int(interval))
        self.stop_event = threading.Event()
        self.cancel_event = threading.Event()
        self.cancel_reason = ""
        self.thread = threading.Thread(target=self._loop, name=f"reflection-heartbeat-{task_id}", daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, exc_type, exc, traceback):
        self.stop_event.set()
        self.thread.join(timeout=2)

    def _loop(self):
        while not self.stop_event.wait(self.interval):
            try:
                response = self.agent.heartbeat_task(self.task_id)
                if response and response.get("status") == "heartbeat_acknowledged":
                    continue

                if response and response.get("instruction") == "relinquish_task":
                    self.cancel_reason = str(response.get("status", "relinquish_task"))
                    self.cancel_event.set()
                    logging.warning(
                        "Master explicitly instructed worker to relinquish task %s: %s",
                        self.task_id,
                        response,
                    )
                    return

                logging.warning(
                    "Heartbeat for task %s was not acknowledged, but no relinquish instruction was received. Continuing local work: %s",
                    self.task_id,
                    response,
                )
            except Exception as exc:
                logging.warning(
                    "Heartbeat for task %s could not reach the master. Continuing local work until the master explicitly says to stop: %s",
                    self.task_id,
                    exc,
                )

# --- CORE FARM AGENT CLASS ---
def _command_from_env(env_name):
    """Return a shell-like command override from an environment variable."""
    raw_command = os.environ.get(env_name, "").strip()
    if not raw_command:
        return None

    import shlex
    return shlex.split(raw_command)


def _reboot_command_from_env():
    """Return a configured reboot command from REFLECTION_REBOOT_COMMAND when present."""
    return _command_from_env("REFLECTION_REBOOT_COMMAND")


def _shutdown_command_from_env():
    """Return a configured shutdown command from REFLECTION_SHUTDOWN_COMMAND when present."""
    return _command_from_env("REFLECTION_SHUTDOWN_COMMAND")


def _default_reboot_command():
    """Return a platform-appropriate command that requests an immediate reboot."""
    system_name = platform.system().lower()
    if system_name == "windows":
        return ["shutdown", "/r", "/t", "0"]

    if shutil.which("systemctl"):
        return ["systemctl", "reboot"]

    return ["shutdown", "-r", "now"]


def _default_shutdown_command():
    """Return a platform-appropriate command that requests an immediate poweroff."""
    system_name = platform.system().lower()
    if system_name == "windows":
        return ["shutdown", "/s", "/t", "0"]

    if shutil.which("systemctl"):
        return ["systemctl", "poweroff"]

    return ["shutdown", "-h", "now"]


def _request_system_reboot():
    """Request a real machine reboot and return immediately."""
    command = _reboot_command_from_env() or _default_reboot_command()
    logging.info("Requesting system reboot with command: %s", " ".join(command))
    try:
        subprocess.Popen(command, start_new_session=True)
    except Exception as exc:
        logging.error("Failed to request system reboot: %s", exc)
        raise


def _delay_before_agent_start():
    """Wait briefly on worker startup so the desktop remains reachable after boot."""
    if START_DELAY_SECONDS <= 0:
        return
    logging.info("Worker start delay: waiting %s seconds before contacting the master.", START_DELAY_SECONDS)
    time.sleep(START_DELAY_SECONDS)


def _delay_before_system_shutdown():
    """Wait briefly before requesting OS shutdown so there is time to access the worker."""
    if SHUTDOWN_DELAY_SECONDS <= 0:
        return
    logging.info("Worker shutdown delay: waiting %s seconds before requesting system shutdown.", SHUTDOWN_DELAY_SECONDS)
    time.sleep(SHUTDOWN_DELAY_SECONDS)


def _request_system_shutdown():
    """Request a real machine poweroff and return immediately."""
    _delay_before_system_shutdown()
    command = _shutdown_command_from_env() or _default_shutdown_command()
    logging.info("Requesting system shutdown with command: %s", " ".join(command))
    try:
        subprocess.Popen(command, start_new_session=True)
    except Exception as exc:
        logging.error("Failed to request system shutdown: %s", exc)
        raise


def _server_shutdown_debug_mode(response):
    """Return true when the master says shutdowns should only stop the agent."""
    if not isinstance(response, dict):
        return False
    return bool(response.get("shutdown_debug_mode") or response.get("debug_shutdown"))


def _handle_server_shutdown_request(context, response, agent=None):
    """Handle a master shutdown request. Return False to leave the worker loop."""
    reason = "server_shutdown_request"
    if isinstance(response, dict):
        reason = str(response.get("reason") or response.get("version_reason") or reason)

    if agent is not None and hasattr(agent, "confirm_shutdown"):
        try:
            confirmed = agent.confirm_shutdown(reason)
            if not confirmed:
                logging.warning("Master shutdown confirmation was not acknowledged. Continuing local shutdown anyway.")
        except Exception:
            logging.exception("Failed to confirm shutdown order with master. Continuing local shutdown anyway.")

    if _server_shutdown_debug_mode(response):
        logging.info("%s. Shutdown debug mode is enabled, so only the farm agent will stop.", context)
        return False

    logging.info("%s. Requesting real farm computer shutdown.", context)
    try:
        _request_system_shutdown()
    except Exception:
        # Even if the platform command fails, stop polling so the master sees this
        # worker go offline/stale instead of immediately taking more work.
        logging.exception("System shutdown command failed; stopping agent anyway.")
    return False


class FarmAgent:
    def __init__(self):
        import requests

        self.requests = requests
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})
        self.task_registry = discover_tasks()

    def post_to_server(self, payload):
        """Send a worker request to the master endpoint."""
        try:
            response = self.session.post(SERVER_URL, json=payload, timeout=30)
            if response.status_code == 200:
                return response.json()
            logging.error("Server returned status code %s", response.status_code)
        except self.requests.exceptions.RequestException as e:
            logging.error("Network error connecting to server: %s", e)
        return None

    def worker_capabilities(self):
        total, used, free = shutil.disk_usage(tempfile.gettempdir())
        return {
            "tasks": sorted(self.task_registry),
            "task_specs": {
                name: definition.spec
                for name, definition in self.task_registry.items()
                if getattr(definition, "spec", None)
            },
            "can_send_wol": True,
            "ffmpeg": shutil.which("ffmpeg") is not None,
            "ffprobe": shutil.which("ffprobe") is not None,
            "task_isolation": TASK_ISOLATION,
            "show_task_terminal": SHOW_TASK_TERMINAL,
            "free_temp_bytes": int(free),
            "free_disk_bytes": int(free),
            "temp_dir": tempfile.gettempdir(),
            "platform": platform.platform(),
            "python": platform.python_version(),
        }

    def check_for_task(self):
        """Step 1 & 2: Connect to server, post status, receive job."""
        payload = {
            "action": "request_task",
            "version": VERSION,
            "pc_id": PC_ID,
            "capabilities": self.worker_capabilities(),
        }
        logging.info("Checking server for available tasks...")
        return self.post_to_server(payload)

    def handle_master_version_policy(self, response):
        """Self-update before accepting work when the master advertises a newer commit.

        Return None when normal response handling should continue. Return True to
        keep the agent polling, or False when the agent should exit after a
        reboot/update action. Old masters that do not transmit master_commit are
        ignored as a safe fallback.
        """
        if not isinstance(response, dict):
            return None

        master_commit = _normalize_master_commit(response.get("master_commit"))
        if not master_commit:
            return None

        version_enforced = bool(response.get("version_enforced"))
        if not version_enforced:
            return None

        if _git_versions_match(VERSION, master_commit):
            return None

        logging.warning(
            "Worker version %s does not match master commit %s. Updating before accepting work.",
            VERSION,
            master_commit,
        )
        try:
            output = _run_update_script(master_commit)
        except Exception as exc:
            logging.exception("Version-follow update failed; no job will be accepted this cycle: %s", exc)
            time.sleep(POLL_INTERVAL)
            return True

        if output:
            logging.info("Version-follow update completed: %s", output[-1000:])
        logging.info("Version-follow update complete. Rebooting farm computer.")
        try:
            _request_system_reboot()
        except Exception:
            # If the reboot command fails, stop polling anyway. The updated code
            # has already replaced the live tree; continuing in the old process
            # risks accepting work with a stale in-memory agent.
            logging.exception("System reboot command failed after version-follow update; stopping agent anyway.")
        return False

    def confirm_task_taken(self, task_id):
        """Step 3: Confirm to the server that we are starting the task."""
        payload = {
            "action": "confirm_taken",
            "version": VERSION,
            "pc_id": PC_ID,
            "task_id": task_id,
        }
        res = self.post_to_server(payload)
        return res and res.get("status") == "acknowledged"

    def heartbeat_task(self, task_id):
        """Tell the master that the current long-running task is still alive."""
        payload = {
            "action": "heartbeat_task",
            "version": VERSION,
            "pc_id": PC_ID,
            "task_id": task_id,
        }
        return self.post_to_server(payload)

    def report_quarantine_location(self, location):
        """Tell the master where an overwrite quarantine folder exists."""
        payload = {
            "action": "register_quarantine",
            "version": VERSION,
            "pc_id": PC_ID,
            "quarantine": location if isinstance(location, dict) else {},
        }
        return self.post_to_server(payload)

    def report_task_done(self, task_id, success, error_msg=""):
        """Step 5 & 6: Report status and wait for server's cleanup greenlight."""
        payload = {
            "action": "report_done",
            "version": VERSION,
            "pc_id": PC_ID,
            "task_id": task_id,
            "status": "success" if success else "failed",
            "error": error_msg,
        }
        return self.post_to_server(payload)

    def confirm_shutdown(self, reason="server_shutdown_request"):
        """Tell the master this worker has accepted a shutdown order."""
        payload = {
            "action": "confirm_shutdown",
            "version": VERSION,
            "pc_id": PC_ID,
            "reason": str(reason or "server_shutdown_request"),
        }
        res = self.post_to_server(payload)
        return bool(res and res.get("status") == "shutdown_confirmed")

    def cleanup_files(self, source_path):
        """Step 7: Local cleanup of source files if explicitly allowed and safe."""
        path = Path(source_path).expanduser()
        if not CLEANUP_ROOTS:
            logging.warning(
                "Skipping cleanup for %s because no cleanup_roots are configured.",
                source_path,
            )
            return

        try:
            resolved_path = path.resolve()
        except OSError as exc:
            logging.error("Failed to resolve cleanup path %s: %s", source_path, exc)
            return

        if not any(_path_is_within(resolved_path, root) for root in CLEANUP_ROOTS):
            logging.warning(
                "Skipping cleanup for %s because it is outside configured cleanup_roots.",
                resolved_path,
            )
            return

        logging.info("Cleaning up local files: %s", resolved_path)
        try:
            if resolved_path.exists():
                if resolved_path.is_dir():
                    shutil.rmtree(resolved_path)
                else:
                    resolved_path.unlink()
                logging.info("Cleanup successful.")
        except Exception as e:
            logging.error("Failed to cleanup files: %s", e)

    def run_task(self, module_name, source, delivery, overwrite_allowed):
        """Execute one discovered task by name."""
        definition = self.task_registry.get(module_name)
        if definition is None:
            raise KeyError(f"Module '{module_name}' not pre-installed on this agent.")

        return _normalize_task_result(definition.run(source, delivery, overwrite_allowed))

    def reload_task_registry(self):
        """Refresh the task registry while keeping built-in tasks available."""
        self.task_registry = discover_tasks()
        logging.info("Reloaded task modules: %s", ", ".join(sorted(self.task_registry)) or "none")

    def run_lifecycle(self):
        """Main worker loop with a top-level guard against unexpected failures."""
        _delay_before_agent_start()
        logging.info("Farm Agent started. Version: %s | PC: %s", VERSION, PC_ID)
        logging.info("Loaded task modules: %s", ", ".join(sorted(self.task_registry)) or "none")
        logging.info(
            "Task isolation: %s | Default task timeout: %ss",
            "enabled" if TASK_ISOLATION else "disabled",
            TASK_TIMEOUT_SECONDS,
        )
        cleanup_stale_worker_temp_dirs(LOCAL_TEMP_MAX_AGE_HOURS)

        while True:
            try:
                should_continue = self._run_lifecycle_cycle()
                if not should_continue:
                    return
            except KeyboardInterrupt:
                logging.info("Keyboard interrupt received. Stopping agent.")
                return
            except Exception as exc:
                # The worker should never fall apart because a single loop
                # iteration hit a bug. Keep the process alive and try again.
                logging.exception("Unhandled worker loop error. Continuing after backoff: %s", exc)
                time.sleep(max(5, POLL_INTERVAL))

    def _run_lifecycle_cycle(self):
        """Run one polling/task cycle. Return False when the agent should stop."""
        # 1 & 2. Ask for work
        response = self.check_for_task()

        if not response:
            logging.info("No response or connection issue. Retrying in %ss...", POLL_INTERVAL)
            time.sleep(POLL_INTERVAL)
            return True

        version_policy_result = FarmAgent.handle_master_version_policy(self, response)
        if version_policy_result is not None:
            return version_policy_result

        if response.get("status") == "no_jobs":
            if response.get("shutdown_after_task"):
                reason = response.get("reason", "server_policy")
                return _handle_server_shutdown_request(
                    f"Server requested idle shutdown ({reason})",
                    response,
                    self,
                )
            logging.info("Server has no jobs. Sleeping for %ss...", POLL_INTERVAL)
            time.sleep(POLL_INTERVAL)
            return True

        if response.get("status") == "version_mismatch":
            required = response.get("required_version", "unknown")
            logging.warning(
                "Worker version %s does not match required version %s, but no master_commit was supplied for self-update. Retrying in %ss.",
                VERSION,
                required,
                POLL_INTERVAL,
            )
            time.sleep(POLL_INTERVAL)
            return True

        if response.get("status") != "task_available":
            logging.warning("Unexpected server response. Sleeping before retry: %s", response)
            time.sleep(POLL_INTERVAL)
            return True

        # Extract task details
        task = response.get("task", {})
        task_id = task.get("task_id")
        module_name = task.get("module")
        source = task.get("source")
        delivery = task.get("delivery")
        overwrite_allowed = task.get("overwrite_allowed", False)
        transfer_auth = _merge_transfer_settings(
            task.get("transfer_server", {}),
            task.get("transfer_auth", {}),
        )
        global QUARANTINE_KEEP_DAYS, QUARANTINE_MAX_BYTES, LOCAL_TEMP_MAX_AGE_HOURS
        QUARANTINE_KEEP_DAYS = max(1, int(task.get("quarantine_keep_days", QUARANTINE_KEEP_DAYS) or QUARANTINE_KEEP_DAYS))
        QUARANTINE_MAX_BYTES = int(max(0.0, float(task.get("quarantine_max_gb", QUARANTINE_MAX_BYTES / (1024 * 1024 * 1024)) or 0.0)) * 1024 * 1024 * 1024)
        LOCAL_TEMP_MAX_AGE_HOURS = max(1, int(task.get("worker_temp_max_age_hours", LOCAL_TEMP_MAX_AGE_HOURS) or LOCAL_TEMP_MAX_AGE_HOURS))
        use_transfer_server_for_plain_paths = (
            task.get("path_mode") == "transfer"
            and isinstance(task.get("transfer_server"), dict)
        )

        if not task_id or not module_name:
            logging.error("Server returned malformed task payload: %s", task)
            time.sleep(POLL_INTERVAL)
            return True

        # 3. Confirm task taken
        if not self.confirm_task_taken(task_id):
            logging.warning("Failed to lock task %s. Skipping.", task_id)
            return True

        logging.info("Task %s locked. Starting module: '%s'", task_id, module_name)
        # 4. Perform the task via isolated execution where possible.
        task_outcome = TaskOutcome(success=False)
        error_message = ""

        with TaskHeartbeat(self, task_id, HEARTBEAT_INTERVAL) as heartbeat:
            task_outcome = _run_task_with_transfer_handling(
                self,
                module_name,
                source,
                delivery,
                overwrite_allowed,
                task_id,
                transfer_auth,
                use_transfer_server_for_plain_paths,
                heartbeat.cancel_event,
            )
        error_message = task_outcome.message
        if heartbeat.cancel_event.is_set() or task_outcome.relinquished:
            logging.warning(
                "Task %s was relinquished after master heartbeat instruction %s. Temporary work files were cleaned; requesting another task.",
                task_id,
                heartbeat.cancel_reason or "relinquish_task",
            )
            return True

        # 5 & 6. Report done & get server final confirmation
        server_response = self.report_task_done(task_id, task_outcome.success, error_message)
        server_confirmed = bool(server_response and server_response.get("status") == "confirmed_by_server")

        # 7. Clean up files if the server acknowledged the wrap-up
        if server_confirmed:
            if task_outcome.success and task_outcome.cleanup_source and source:
                self.cleanup_files(source)

            if task_outcome.reload_tasks:
                self.reload_task_registry()

            if task_outcome.reboot_system:
                logging.info("Update task %s confirmed by server. Rebooting farm computer.", task_id)
                _request_system_reboot()
                return False

            if task_outcome.restart_agent:
                logging.info("Restart task %s confirmed by server. Restarting agent.", task_id)
                os.execv(sys.executable, [sys.executable, *sys.argv])

            if task_outcome.stop_agent:
                return _handle_server_shutdown_request(
                    f"Shutdown task {task_id} confirmed by server",
                    server_response,
                    self,
                )

            if server_response.get("shutdown_after_task"):
                return _handle_server_shutdown_request(
                    f"Server requested shutdown after task {task_id} due to SOC policy",
                    server_response,
                    self,
                )

            logging.info("Lifecycle finished for Task %s. Repeating loop...\n", task_id)
        else:
            logging.error("Server did not acknowledge task closeout for %s. Holding cleanup.", task_id)

        time.sleep(2)  # Minor breather between tasks
        return True


def main():
    parser = argparse.ArgumentParser(description="Run the Reflection farm agent.")
    parser.add_argument(
        "--install-task",
        help="Run the optional install() function inside one task file, then exit.",
    )
    args = parser.parse_args()

    if args.install_task:
        run_task_installer(args.install_task)
        return

    agent = FarmAgent()
    agent.run_lifecycle()


if __name__ == "__main__":
    main()
