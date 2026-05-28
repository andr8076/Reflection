"""Core Reflection farm agent lifecycle."""

import logging
import os
import shutil
import sys
import time

import requests

from .config import PC_ID, POLL_INTERVAL, SERVER_URL, VERSION
from .logging_config import configure_logging
from .task_loader import discover_tasks

configure_logging()


class FarmAgent:
    """Poll the farm server, execute standardized task modules, and report results."""

    def __init__(self):
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
        except requests.exceptions.RequestException as e:
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

            # 4. Perform the task via the registry
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
