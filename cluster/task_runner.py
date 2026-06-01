#!/usr/bin/env python3
"""Isolated Reflection task runner.

This file is launched by Reflection.py in a child process. It imports one task,
runs it, and writes a compact JSON result. Keeping user/task code in this child
process prevents a broken task from taking down the main worker loop.
"""

import argparse
import json
import os
import sys
import traceback
from pathlib import Path
from task_registry import find_task_module, load_task_definition, normalize_task_result


def _atomic_write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_suffix(path.suffix + ".tmp")
    with temp_path.open("w", encoding="utf-8") as output:
        json.dump(payload, output, indent=2, sort_keys=True)
        output.write("\n")
    os.replace(temp_path, path)


def run_task(tasks_dir: Path, module_name: str, source: str, delivery: str, overwrite_allowed: bool) -> dict:
    path, module = find_task_module(tasks_dir, module_name)
    definition = load_task_definition(path, module)
    return normalize_task_result(definition.run(source, delivery, overwrite_allowed))


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one Reflection task in isolation.")
    parser.add_argument("--tasks-dir", required=True)
    parser.add_argument("--module", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--delivery", required=True)
    parser.add_argument("--result-file", required=True)
    parser.add_argument("--overwrite-allowed", action="store_true")
    args = parser.parse_args()

    result_path = Path(args.result_file)
    try:
        result = run_task(
            Path(args.tasks_dir),
            args.module,
            args.source,
            args.delivery,
            bool(args.overwrite_allowed),
        )
        _atomic_write_json(result_path, result)
        return 0 if result.get("success") else 2
    except SystemExit as exc:
        code = exc.code if isinstance(exc.code, int) else 1
        error = {
            "success": False,
            "stop_agent": False,
            "restart_agent": False,
            "reload_tasks": False,
            "cleanup_source": False,
            "message": f"Task called sys.exit({code}).",
        }
        _atomic_write_json(result_path, error)
        return code or 1
    except BaseException as exc:
        error = {
            "success": False,
            "stop_agent": False,
            "restart_agent": False,
            "reload_tasks": False,
            "cleanup_source": False,
            "message": f"{type(exc).__name__}: {exc}\n{traceback.format_exc()}",
        }
        _atomic_write_json(result_path, error)
        return 1


if __name__ == "__main__":
    sys.exit(main())
