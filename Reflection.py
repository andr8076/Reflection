import argparse
import contextlib
import ftplib
import importlib.util
import inspect
import json
import logging
import os
import shutil
import socket
import tempfile
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Optional
from urllib.parse import unquote, urlparse

# --- CONFIGURATION ---
DEFAULT_SERVER_URL = "http://your-server-domain.com/farm_api.php"
DEFAULT_POLL_INTERVAL = 10
DEFAULT_PC_ID = socket.gethostname()
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
        "pc_id": DEFAULT_PC_ID,
    }

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

    if "pc_id" in loaded:
        pc_id = str(loaded["pc_id"]).strip()
        if pc_id:
            config["pc_id"] = pc_id

    return config


def _resolve_git_dir(start_path):
    """Return the repository Git directory by reading Git metadata files."""
    current = Path(start_path).resolve()

    for candidate in (current, *current.parents):
        git_path = candidate / ".git"
        if git_path.is_dir():
            return git_path

        if git_path.is_file():
            content = git_path.read_text(encoding="utf-8").strip()
            prefix = "gitdir:"
            if not content.lower().startswith(prefix):
                continue

            git_dir = Path(content[len(prefix) :].strip())
            if not git_dir.is_absolute():
                git_dir = candidate / git_dir
            return git_dir.resolve()

    return None


def _read_packed_ref(git_dir, ref_name):
    """Read a commit hash for ref_name from Git's packed-refs file."""
    packed_refs = git_dir / "packed-refs"
    if not packed_refs.is_file():
        return None

    with packed_refs.open(encoding="utf-8") as refs_file:
        for line in refs_file:
            line = line.strip()
            if not line or line.startswith("#") or line.startswith("^"):
                continue

            try:
                commit_id, packed_ref_name = line.split(" ", 1)
            except ValueError:
                continue

            if packed_ref_name == ref_name:
                return commit_id

    return None


def get_git_commit_id(start_path=__file__):
    """Return the current commit ID by reading the repository's Git files."""
    git_dir = _resolve_git_dir(Path(start_path).parent)
    if git_dir is None:
        return "unknown"

    head = git_dir / "HEAD"
    if not head.is_file():
        return "unknown"

    head_value = head.read_text(encoding="utf-8").strip()
    ref_prefix = "ref:"
    if not head_value.startswith(ref_prefix):
        return head_value

    ref_name = head_value[len(ref_prefix) :].strip()
    ref_path = git_dir / ref_name
    if ref_path.is_file():
        return ref_path.read_text(encoding="utf-8").strip()

    return _read_packed_ref(git_dir, ref_name) or "unknown"


VERSION = get_git_commit_id()
AGENT_CONFIG = load_agent_config()
SERVER_URL = AGENT_CONFIG["server_url"]  # Target PHP endpoint
POLL_INTERVAL = AGENT_CONFIG["poll_interval"]  # Seconds to wait before checking for new jobs if idle
PC_ID = AGENT_CONFIG["pc_id"]  # Unique identifier for this node
TASKS_DIR = Path(__file__).with_name("tasks")

# Setup logging to see what the farm bot is doing
logging.basicConfig(level=logging.INFO, format="%(asctime)s - [%(levelname)s] - %(message)s")


@dataclass(frozen=True)
class TaskOutcome:
    """Normalized result from a task run."""

    success: bool
    stop_agent: bool = False
    reload_tasks: bool = False
    cleanup_source: bool = True
    message: str = ""


@dataclass(frozen=True)
class TaskDefinition:
    """A standardized task loaded from a Python file in the tasks folder."""

    name: str
    run: Callable[[str, str, bool], Any]
    install: Optional[Callable[[], None]] = None
    description: str = ""


def _import_task_file(path):
    """Import one task file without requiring the tasks folder to be a package."""
    module_name = f"reflection_task_{path.stem}"
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _load_task_definition(path):
    """Validate one task file and return its standardized definition."""
    module = _import_task_file(path)
    task_name = getattr(module, "TASK_NAME", path.stem)
    runner = getattr(module, "run", None)

    if not callable(runner):
        raise AttributeError(f"{path} must define run(source, delivery, overwrite_allowed).")

    signature = inspect.signature(runner)
    expected_args = ("source", "delivery", "overwrite_allowed")
    if tuple(signature.parameters) != expected_args:
        raise TypeError(f"{path} run function must be run(source, delivery, overwrite_allowed).")

    installer = getattr(module, "install", None)
    if installer is not None and not callable(installer):
        raise TypeError(f"{path} install value must be a function when provided.")

    return TaskDefinition(
        name=task_name,
        run=runner,
        install=installer,
        description=getattr(module, "DESCRIPTION", ""),
    )


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
    if isinstance(result, TaskOutcome):
        return result

    if isinstance(result, bool):
        return TaskOutcome(success=result)

    if isinstance(result, dict):
        return TaskOutcome(
            success=bool(result.get("success", False)),
            stop_agent=bool(result.get("stop_agent", False)),
            reload_tasks=bool(result.get("reload_tasks", False)),
            cleanup_source=bool(result.get("cleanup_source", True)),
            message=str(result.get("message", "")),
        )

    raise TypeError("Task run() must return a bool, dict, or TaskOutcome.")


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


def _normalize_wake_targets(source):
    if not source:
        return []

    try:
        parsed = json.loads(source)
    except (TypeError, json.JSONDecodeError):
        parsed = [part.strip() for part in str(source).replace(",", "\n").splitlines()]

    targets = []
    for entry in parsed:
        if isinstance(entry, dict):
            mac = str(entry.get("mac", "")).strip()
        else:
            mac = str(entry).strip()
        if mac:
            targets.append(mac)
    return targets


def _send_wake_packet(mac_address):
    clean_mac = mac_address.replace(":", "").replace("-", "").replace(".", "")
    if len(clean_mac) != 12:
        raise ValueError(f"Invalid MAC address: {mac_address}")
    payload = bytes.fromhex("FF" * 6 + clean_mac * 16)
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as wol_socket:
        wol_socket.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        wol_socket.sendto(payload, ("255.255.255.255", 9))


def _system_wake_farm(source, delivery, overwrite_allowed):
    """Built-in task that sends Wake-on-LAN packets for configured machines."""
    targets = _normalize_wake_targets(source)
    for mac in targets:
        _send_wake_packet(mac)
    return {
        "success": True,
        "cleanup_source": False,
        "message": f"Sent Wake-on-LAN packets to {len(targets)} target(s).",
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
        "wake_farm": TaskDefinition(
            name="wake_farm",
            run=_system_wake_farm,
            description="Built-in control task that sends Wake-on-LAN packets to configured farm computers.",
        ),
    }


def discover_tasks():
    """Load standardized task files plus reserved built-in system tasks."""
    registry = {}

    for path in sorted(TASKS_DIR.glob("*.py")):
        if path.name.startswith("_"):
            continue

        try:
            definition = _load_task_definition(path)
            registry[definition.name] = definition
        except Exception as e:
            logging.error("Failed to load task file '%s': %s", path, e)

    built_ins = built_in_tasks()
    reserved_names = sorted(set(registry) & set(built_ins))
    if reserved_names:
        logging.warning(
            "Task files cannot override built-in task names: %s",
            ", ".join(reserved_names),
        )
    registry.update(built_ins)
    return registry


def _is_ftp_uri(value):
    """Return true when value is an FTP/FTPS URI string."""
    if not value:
        return False
    return urlparse(str(value)).scheme.lower() in {"ftp", "ftps"}


def _ftp_connection(parsed_uri, transfer_auth):
    """Open an FTP/FTPS connection using URI credentials first, then task auth."""
    auth = transfer_auth if isinstance(transfer_auth, dict) else {}
    scheme = parsed_uri.scheme.lower() or str(auth.get("scheme", "ftp")).lower()
    ftp_class = ftplib.FTP_TLS if scheme == "ftps" else ftplib.FTP
    host = parsed_uri.hostname or str(auth.get("host", ""))
    if not host:
        raise ValueError(
            "FTP URI must include a host or transfer_auth.host must be configured."
        )

    default_port = 990 if scheme == "ftps" else 21
    port = parsed_uri.port or int(auth.get("port") or default_port)
    username = unquote(parsed_uri.username or str(auth.get("username", "")))
    password = unquote(parsed_uri.password or str(auth.get("password", "")))
    if not username or not password:
        raise ValueError("FTP transfer credentials are required for farm file transfers.")

    ftp = ftp_class()
    ftp.connect(host, port, timeout=30)
    ftp.login(username, password)
    if isinstance(ftp, ftplib.FTP_TLS):
        ftp.prot_p()
    return ftp


def _ftp_uri_path(parsed_uri):
    """Decode and validate the path component from an FTP URI."""
    remote_path = unquote(parsed_uri.path or "")
    if remote_path in {"", "/"}:
        raise ValueError("FTP URI must point at a file path.")
    return remote_path


def _download_ftp_file(uri, transfer_auth, local_directory):
    """Download one FTP/FTPS file into local_directory and return the local path."""
    parsed = urlparse(str(uri))
    remote_path = _ftp_uri_path(parsed)
    local_name = Path(remote_path).name or "source"
    local_path = local_directory / local_name

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Downloading FTP source %s to local worker storage.", safe_uri)
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        with local_path.open("wb") as local_file:
            ftp.retrbinary(f"RETR {remote_path}", local_file.write)
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


def _upload_ftp_file(local_path, uri, transfer_auth):
    """Upload one local file to an FTP/FTPS URI."""
    parsed = urlparse(str(uri))
    remote_path = _ftp_uri_path(parsed)
    source_path = Path(local_path)
    if not source_path.is_file():
        raise FileNotFoundError(f"FTP delivery upload expected a file: {source_path}")

    safe_uri = parsed._replace(netloc=parsed.hostname or "").geturl()
    logging.info("Uploading task delivery to FTP target %s.", safe_uri)
    with contextlib.closing(_ftp_connection(parsed, transfer_auth)) as ftp:
        _ensure_ftp_directory(ftp, str(Path(remote_path).parent))
        with source_path.open("rb") as source_file:
            ftp.storbinary(f"STOR {remote_path}", source_file)


def _prepare_transfer_paths(source, delivery, task_id, transfer_auth):
    """Convert FTP source/delivery URIs into local paths for task execution."""
    temp_directory = None
    prepared_source = source
    prepared_delivery = delivery

    if _is_ftp_uri(source) or _is_ftp_uri(delivery):
        temp_directory = tempfile.TemporaryDirectory(
            prefix=f"reflection-{task_id or 'task'}-"
        )
        temp_path = Path(temp_directory.name)

    if _is_ftp_uri(source):
        prepared_source = _download_ftp_file(source, transfer_auth, temp_path)

    if _is_ftp_uri(delivery):
        parsed_delivery = urlparse(str(delivery))
        delivery_name = Path(unquote(parsed_delivery.path or "delivery")).name or "delivery"
        prepared_delivery = str(temp_path / delivery_name)

    return prepared_source, prepared_delivery, temp_directory

# --- CORE FARM AGENT CLASS ---
class FarmAgent:
    def __init__(self):
        import requests

        self.requests = requests
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})
        self.task_registry = discover_tasks()

    def post_to_server(self, payload):
        """Helper to handle API communication safely."""
        try:
            response = self.session.post(SERVER_URL, json=payload, timeout=30)
            if response.status_code == 200:
                return response.json()
            logging.error("Server returned status code %s", response.status_code)
        except self.requests.exceptions.RequestException as e:
            logging.error("Network error connecting to server: %s", e)
        return None

    def check_for_task(self):
        """Step 1 & 2: Connect to server, post status, receive job."""
        payload = {
            "action": "request_task",
            "version": VERSION,
            "pc_id": PC_ID,
        }
        logging.info("Checking server for available tasks...")
        return self.post_to_server(payload)

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

    def cleanup_files(self, source_path):
        """Step 7: Local cleanup of source files if requested or necessary."""
        logging.info("Cleaning up local files: %s", source_path)
        try:
            if os.path.exists(source_path):
                if os.path.isdir(source_path):
                    shutil.rmtree(source_path)
                else:
                    os.remove(source_path)
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
        """Step 8: The loop."""
        logging.info("Farm Agent started. Version: %s | PC: %s", VERSION, PC_ID)
        logging.info("Loaded task modules: %s", ", ".join(sorted(self.task_registry)) or "none")

        while True:
            # 1 & 2. Ask for work
            response = self.check_for_task()

            if not response:
                logging.info("No response or connection issue. Retrying in %ss...", POLL_INTERVAL)
                time.sleep(POLL_INTERVAL)
                continue

            if response.get("status") == "no_jobs":
                if response.get("shutdown_after_task"):
                    logging.info("Server requested idle shutdown due to SOC policy. Stopping agent.")
                    return
                logging.info("Server has no jobs. Sleeping for %ss...", POLL_INTERVAL)
                time.sleep(POLL_INTERVAL)
                continue

            if response.get("status") == "version_mismatch":
                logging.critical("Fatal: Script version does not match server version! Halting agent.")
                sys.exit(1)

            # Extract task details
            task = response.get("task", {})
            task_id = task.get("task_id")
            module_name = task.get("module")
            source = task.get("source")
            delivery = task.get("delivery")
            overwrite_allowed = task.get("overwrite_allowed", False)
            transfer_auth = task.get("transfer_auth", {})

            # 3. Confirm task taken
            if not self.confirm_task_taken(task_id):
                logging.warning("Failed to lock task %s. Skipping.", task_id)
                continue

            logging.info("Task %s locked. Starting module: '%s'", task_id, module_name)

            # 4. Perform the task via the Registry
            task_outcome = TaskOutcome(success=False)
            error_message = ""

            transfer_workspace = None
            try:
                prepared_source, prepared_delivery, transfer_workspace = _prepare_transfer_paths(
                    source,
                    delivery,
                    task_id,
                    transfer_auth,
                )
                task_outcome = self.run_task(
                    module_name,
                    prepared_source,
                    prepared_delivery,
                    overwrite_allowed,
                )
                if task_outcome.success and _is_ftp_uri(delivery):
                    _upload_ftp_file(prepared_delivery, delivery, transfer_auth)
                error_message = task_outcome.message
            except Exception as e:
                error_message = str(e)
                logging.error("Execution failed on module '%s': %s", module_name, error_message)
            finally:
                if transfer_workspace is not None:
                    transfer_workspace.cleanup()

            # 5 & 6. Report done & get server final confirmation
            server_response = self.report_task_done(task_id, task_outcome.success, error_message)
            server_confirmed = bool(server_response and server_response.get("status") == "confirmed_by_server")

            # 7. Clean up files if the server acknowledged the wrap-up
            if server_confirmed:
                if task_outcome.success and task_outcome.cleanup_source and source:
                    self.cleanup_files(source)

                if task_outcome.reload_tasks:
                    self.reload_task_registry()

                if task_outcome.stop_agent:
                    logging.info("Shutdown task %s confirmed by server. Stopping agent.", task_id)
                    return

                if server_response.get("shutdown_after_task"):
                    logging.info("Server requested shutdown after task %s due to SOC policy. Stopping agent.", task_id)
                    return

                logging.info("Lifecycle finished for Task %s. Repeating loop...\n", task_id)
            else:
                logging.error("Server did not acknowledge task closeout for %s. Holding cleanup.", task_id)

            time.sleep(2)  # Minor breather between tasks


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
