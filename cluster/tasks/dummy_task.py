"""Example task that writes a processed output file."""

import logging
import os

TASK_NAME = "dummy_task"
DESCRIPTION = "A placeholder module to test the pipeline."


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
