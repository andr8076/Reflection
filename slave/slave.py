# Main slave loop
# Handles:
# - loading tasks dynamically
# - requesting jobs from server
# - validating and executing tasks
# - reporting results

import importlib.util
import json
import pathlib
import time
import requests

from task_base import TASKS


# Dynamically load all task modules from /tasks folder
def load_task_modules():
    tasks_dir = pathlib.Path(__file__).parent / "tasks"

    for file in tasks_dir.glob("*.py"):
        if file.name.startswith("_"):
            continue

        module_name = f"task_{file.stem}"
        spec = importlib.util.spec_from_file_location(module_name, file)

        if spec and spec.loader:
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)


# Load all tasks at startup
load_task_modules()

# Load config
config = json.load(open("config.json"))


def request_job():
    # Ask server for a job
    return requests.post(config["server_url"], json={
        "action": "request_job",
        "computer_id": config["computer_id"]
    }).json()


def accept_job(job_id):
    # Confirm we will execute job
    requests.post(config["server_url"], json={
        "action": "accept_job",
        "computer_id": config["computer_id"],
        "job_id": job_id
    })


def finish_job(job_id, result, message=""):
    # Report job result
    return requests.post(config["server_url"], json={
        "action": "finish_job",
        "computer_id": config["computer_id"],
        "job_id": job_id,
        "result": result,
        "message": message
    }).json()


def valid_version(job):
    # Check version compatibility
    if config["repo_id"] == job["expected_repo_id"]:
        return True
    return job["allow_version_override"]


# Main loop
while True:
    response = request_job()

    if response["action"] == "sleep":
        time.sleep(response["sleep_seconds"])
        continue

    job = response["job"]

    if not valid_version(job):
        time.sleep(10)
        continue

    task_name = job["task"]

    if task_name not in TASKS:
        finish_job(job["job_id"], "failed", f"unknown task: {task_name}")
        continue

    task = TASKS[task_name]

    try:
        task.validate(job)
    except Exception as e:
        finish_job(job["job_id"], "failed", str(e))
        continue

    accept_job(job["job_id"])

    try:
        task.run(job)
        response = finish_job(job["job_id"], "done")
    except Exception as e:
        response = finish_job(job["job_id"], "failed", str(e))

    if response["action"] == "sleep":
        time.sleep(response["sleep_seconds"])
