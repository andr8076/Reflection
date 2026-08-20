"""Example task that writes a processed output file."""

import json
import logging
import os

TASK_NAME = "dummy_task"
DESCRIPTION = "A placeholder module to test the pipeline."
TASK_SPEC_JSON = r'''
{
  "name": "dummy_task",
  "description": "Placeholder pipeline test task.",
  "production_ready": false,
  "unavailable_reason": "dummy_task is a development pipeline fixture and is not available for production scheduling.",
  "source": {
    "mode": "required",
    "label": "Source value",
    "help": "A test source value or path."
  },
  "delivery": {
    "mode": "auto",
    "label": "Dummy output",
    "help": "Automatically written beside the source as {name}.out unless overridden.",
    "template": "{dir}/{name}.out",
    "extension": ".out"
  },
  "output": {
    "kind": "file",
    "extension": ".out"
  }
}
'''
TASK_SPEC = json.loads(TASK_SPEC_JSON)



def install():
    """Optional installer for task-specific dependencies."""
    logging.info("dummy_task does not require additional software.")


def run(source, delivery, overwrite_allowed):
    """Process a dummy task using the standardized task signature."""
    logging.info("Processing dummy task. Source: %s -> Delivery: %s", source, delivery)

    if os.path.exists(delivery) and not overwrite_allowed:
        raise FileExistsError("Target delivery file exists and overwrite is disabled.")

    delivery_dir = os.path.dirname(delivery)
    if delivery_dir:
        os.makedirs(delivery_dir, exist_ok=True)
    with open(delivery, "w") as f:
        f.write(f"Processed data from {source}")
    return True
