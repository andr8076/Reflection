"""Run optional installers for task modules."""

import argparse

from reflection_agent.logging_config import configure_logging
from reflection_agent.task_loader import install_task_dependencies


def main():
    parser = argparse.ArgumentParser(description="Run optional install hooks for Reflection tasks.")
    parser.add_argument(
        "task",
        nargs="?",
        help="Optional task name to install. If omitted, installers for all tasks are run.",
    )
    args = parser.parse_args()

    configure_logging()
    install_task_dependencies(args.task)


if __name__ == "__main__":
    main()
