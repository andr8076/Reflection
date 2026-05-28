import argparse
import importlib.util
import inspect
import logging
import os
import shutil
import socket
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Optional

# --- CONFIGURATION ---
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
SERVER_URL = "http://your-server-domain.com/farm_api.php"  # Target PHP endpoint
POLL_INTERVAL = 10  # Seconds to wait before checking for new jobs if idle
PC_ID = socket.gethostname()  # Unique identifier for this node
TASKS_DIR = Path(__file__).with_name("tasks")

# Setup logging to see what the farm bot is doing
logging.basicConfig(level=logging.INFO, format="%(asctime)s - [%(levelname)s] - %(message)s")


@dataclass(frozen=True)
class TaskDefinition:
    """A standardized task loaded from a Python file in the tasks folder."""

    name: str
    run: Callable[[str, str, bool], bool]
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


def discover_tasks():
    """Load every standardized task file from the tasks folder."""
    registry = {}

    for path in sorted(TASKS_DIR.glob("*.py")):
        if path.name.startswith("_"):
            continue

        try:
            definition = _load_task_definition(path)
            registry[definition.name] = definition
        except Exception as e:
            logging.error("Failed to load task file '%s': %s", path, e)

    return registry


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
        res = self.post_to_server(payload)
        return res and res.get("status") == "confirmed_by_server"

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

        return definition.run(source, delivery, overwrite_allowed)

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

            # 3. Confirm task taken
            if not self.confirm_task_taken(task_id):
                logging.warning("Failed to lock task %s. Skipping.", task_id)
                continue

            logging.info("Task %s locked. Starting module: '%s'", task_id, module_name)

            # 4. Perform the task via the Registry
            task_success = False
            error_message = ""

            try:
                task_success = self.run_task(module_name, source, delivery, overwrite_allowed)
            except Exception as e:
                error_message = str(e)
                logging.error("Execution failed on module '%s': %s", module_name, error_message)

            # 5 & 6. Report done & get server final confirmation
            server_confirmed = self.report_task_done(task_id, task_success, error_message)

            # 7. Clean up files if the server acknowledged the wrap-up
            if server_confirmed:
                if task_success:
                    self.cleanup_files(source)
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
