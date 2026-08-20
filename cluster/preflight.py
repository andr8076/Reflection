#!/usr/bin/env python3
"""Validate a Reflection worker installation without requesting a job."""

from __future__ import annotations

import argparse
import sys

from Reflection import FarmAgent


def run_preflight(*, check_server: bool = True) -> list[str]:
    agent = FarmAgent()
    problems: list[str] = []
    capabilities = agent.worker_capabilities()

    if not capabilities.get("terminal_available"):
        problems.append("No supported visible terminal emulator is available.")
    if not capabilities.get("task_isolation") or not capabilities.get("show_task_terminal"):
        problems.append("Visible isolated task execution is not enabled.")

    for name, readiness in sorted(agent.task_readiness.items()):
        definition = agent.task_registry[name]
        if definition.spec.get("production_ready", True) is False:
            continue
        if not readiness.get("ready"):
            problems.append(f"Task {name} is unavailable: {readiness.get('reason') or 'unknown reason'}")

    try:
        agent.state_store.pending_completion()
    except RuntimeError as exc:
        problems.append(str(exc))

    if check_server and not problems:
        response = agent.post_to_server(
            {"action": "system_check", "capabilities": capabilities},
            attempts=3,
        )
        if not response or response.get("status") != "ready":
            problems.append(f"Master system check failed: {response!r}")

    return problems


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--skip-server",
        action="store_true",
        help="Validate only this computer; do not contact the configured master.",
    )
    args = parser.parse_args(argv)

    problems = run_preflight(check_server=not args.skip_server)
    if problems:
        print("Reflection worker preflight failed:", file=sys.stderr)
        for problem in problems:
            print(f"- {problem}", file=sys.stderr)
        return 1

    print("Reflection worker preflight passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
