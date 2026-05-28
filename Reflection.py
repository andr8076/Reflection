import os
import sys
import time
import socket
import shutil
import logging
import requests

# --- CONFIGURATION ---
VERSION = "1.0.0"
SERVER_URL = "http://your-server-domain.com/farm_api.php"  # Target PHP endpoint
POLL_INTERVAL = 10  # Seconds to wait before checking for new jobs if idle
PC_ID = socket.gethostname()  # Unique identifier for this node

# Setup logging to see what the farm bot is doing
logging.basicConfig(level=logging.INFO, format='%(asctime)s - [%(levelname)s] - %(message)s')

# --- TASK MODULES REGISTRY ---
# Standardize your commands here. Every module must accept: source, delivery, and overwrite_allowed.
class TaskModules:
    @staticmethod
    def dummy_task(source, delivery, overwrite_allowed):
        """A placeholder module to test the pipeline pipeline."""
        logging.info(f"Processing dummy task. Source: {source} -> Delivery: {delivery}")
        
        if os.path.exists(delivery) and not overwrite_allowed:
            raise FileExistsError("Target delivery file exists and overwrite is disabled.")
            
        # Simulating file generation/processing
        os.makedirs(os.path.dirname(delivery), exist_ok=True)
        with open(delivery, "w") as f:
            f.write(f"Processed data from {source}")
        return True

    @staticmethod
    def render_frame(source, delivery, overwrite_allowed):
        """Example of a real world task module."""
        # Your Blender/FFmpeg/Processing logic goes here
        pass

# Map the server command strings to our Python functions
MODULE_REGISTRY = {
    "dummy_task": TaskModules.dummy_task,
    "render_frame": TaskModules.render_frame,
}

# --- CORE FARM AGENT CLASS ---
class FarmAgent:
    def __init__(self):
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})

    def post_to_server(self, payload):
        """Helper to handle API communication safely."""
        try:
            response = self.session.post(SERVER_URL, json=payload, timeout=30)
            if response.status_code == 200:
                return response.json()
            else:
                logging.error(f"Server returned status code {response.status_code}")
        except requests.exceptions.RequestException as e:
            logging.error(f"Network error connecting to server: {e}")
        return None

    def check_for_task(self):
        """Step 1 & 2: Connect to server, post status, receive job."""
        payload = {
            "action": "request_task",
            "version": VERSION,
            "pc_id": PC_ID
        }
        logging.info("Checking server for available tasks...")
        return self.post_to_server(payload)

    def confirm_task_taken(self, task_id):
        """Step 3: Confirm to the server that we are starting the task."""
        payload = {
            "action": "confirm_taken",
            "version": VERSION,
            "pc_id": PC_ID,
            "task_id": task_id
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
            "error": error_msg
        }
        res = self.post_to_server(payload)
        return res and res.get("status") == "confirmed_by_server"

    def cleanup_files(self, source_path):
        """Step 7: Local cleanup of source files if requested or necessary."""
        logging.info(f"Cleaning up local files: {source_path}")
        try:
            if os.path.exists(source_path):
                if os.path.isdir(source_path):
                    shutil.rmtree(source_path)
                else:
                    os.remove(source_path)
                logging.info("Cleanup successful.")
        except Exception as e:
            logging.error(f"Failed to cleanup files: {e}")

    def run_lifecycle(self):
        """Step 8: The loop."""
        logging.info(f"Farm Agent started. Version: {VERSION} | PC: {PC_ID}")
        
        while True:
            # 1 & 2. Ask for work
            response = self.check_for_task()
            
            if not response:
                logging.info(f"No response or connection issue. Retrying in {POLL_INTERVAL}s...")
                time.sleep(POLL_INTERVAL)
                continue

            if response.get("status") == "no_jobs":
                logging.info(f"Server has no jobs. Sleeping for {POLL_INTERVAL}s...")
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
                logging.warning(f"Failed to lock task {task_id}. Skipping.")
                continue

            logging.info(f"Task {task_id} locked. Starting module: '{module_name}'")
            
            # 4. Perform the task via the Registry
            task_success = False
            error_message = ""
            
            if module_name in MODULE_REGISTRY:
                try:
                    # Execute the standardized module
                    task_success = MODULE_REGISTRY[module_name](source, delivery, overwrite_allowed)
                except Exception as e:
                    error_message = str(e)
                    logging.error(f"Execution failed on module '{module_name}': {error_message}")
            else:
                error_message = f"Module '{module_name}' not pre-installed on this agent."
                logging.error(error_message)

            # 5 & 6. Report done & get server final confirmation
            server_confirmed = self.report_task_done(task_id, task_success, error_message)
            
            # 7. Clean up files if the server acknowledged the wrap-up
            if server_confirmed:
                if task_success:
                    self.cleanup_files(source)
                logging.info(f"Lifecycle finished for Task {task_id}. Repeating loop...\n")
            else:
                logging.error(f"Server did not acknowledge task closeout for {task_id}. Holding cleanup.")

            time.sleep(2) # Minor breather between tasks

if __name__ == "__main__":
    agent = FarmAgent()
    agent.run_lifecycle()