#!/usr/bin/env python3
"""Run setup installers exposed by Reflection task modules."""

from __future__ import annotations

import argparse
import logging
import sys
from dataclasses import dataclass
from typing import Iterable, Mapping, Sequence

from Reflection import TaskDefinition, discover_tasks

EXIT_OK = 0
EXIT_INSTALL_FAILED = 1
EXIT_BAD_TASK = 2

LOGGER = logging.getLogger(__name__)


@dataclass(frozen=True)
class SetupResult:
    """Outcome from one task setup attempt."""

    task_name: str
    status: str
    message: str = ""

    @property
    def failed(self) -> bool:
        return self.status == "failed"


def installable_task_names(registry: Mapping[str, TaskDefinition]) -> list[str]:
    """Return sorted task names that expose an install() hook."""
    return sorted(
        task_name
        for task_name, definition in registry.items()
        if definition.install is not None
    )


def selected_task_names(
    registry: Mapping[str, TaskDefinition], requested: Iterable[str]
) -> list[str]:
    """Resolve CLI task selections into task names to process."""
    requested_names = list(dict.fromkeys(requested))
    if not requested_names:
        return installable_task_names(registry)

    unknown = sorted(set(requested_names) - set(registry))
    if unknown:
        available = ", ".join(sorted(registry)) or "none"
        raise KeyError(
            f"Unknown task(s): {', '.join(unknown)}. Available tasks: {available}"
        )
    return requested_names


def run_setups(
    task_names: Iterable[str] = (),
    *,
    stop_on_error: bool = False,
    registry: Mapping[str, TaskDefinition] | None = None,
) -> list[SetupResult]:
    """Run install() for every selected task and return SetupResult entries."""
    resolved_registry = registry or discover_tasks()
    results: list[SetupResult] = []

    for task_name in selected_task_names(resolved_registry, task_names):
        definition = resolved_registry[task_name]
        if definition.install is None:
            result = SetupResult(task_name, "skipped", "No install() function.")
            LOGGER.info("Skipping %s: %s", task_name, result.message)
            results.append(result)
            continue

        LOGGER.info("Running setup for task '%s'...", task_name)
        try:
            definition.install()
        except Exception as exc:  # noqa: BLE001 - installers should not abort summary output.
            result = SetupResult(task_name, "failed", str(exc))
            LOGGER.error("Setup failed for task '%s': %s", task_name, exc)
            results.append(result)
            if stop_on_error:
                break
        else:
            result = SetupResult(task_name, "ok", "install() completed.")
            LOGGER.info("Setup finished for task '%s'.", task_name)
            results.append(result)

    return results


def print_task_list(registry: Mapping[str, TaskDefinition] | None = None) -> None:
    """Print every discovered task and whether it exposes install()."""
    resolved_registry = registry or discover_tasks()
    for task_name in sorted(resolved_registry):
        definition = resolved_registry[task_name]
        setup_state = "installer" if definition.install is not None else "no installer"
        description = f" - {definition.description}" if definition.description else ""
        print(f"{task_name}: {setup_state}{description}")


def print_summary(results: Sequence[SetupResult]) -> None:
    """Print a stable machine-readable-ish summary for operators."""
    print("\nReflection setup summary:")
    if not results:
        print("- no task installers found")
        return

    for result in results:
        print(f"- {result.task_name}: {result.status} ({result.message})")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Run setup/install functions from Reflection task modules.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument(
        "--task",
        action="append",
        default=[],
        help=(
            "Task name to set up. Repeat to run multiple tasks. "
            "Defaults to every task with install()."
        ),
    )
    parser.add_argument(
        "--list",
        action="store_true",
        help="List discovered tasks and exit without running installers.",
    )
    parser.add_argument(
        "--stop-on-error",
        action="store_true",
        help="Stop after the first failed installer instead of reporting all failures.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Enable debug logging.",
    )
    return parser


def configure_logging(verbose: bool = False) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s - [%(levelname)s] - %(message)s",
        force=True,
    )


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    configure_logging(args.verbose)

    if args.list:
        print_task_list()
        return EXIT_OK

    try:
        results = run_setups(args.task, stop_on_error=args.stop_on_error)
    except KeyError as exc:
        print(str(exc), file=sys.stderr)
        return EXIT_BAD_TASK

    print_summary(results)
    return EXIT_INSTALL_FAILED if any(result.failed for result in results) else EXIT_OK


if __name__ == "__main__":
    raise SystemExit(main())
