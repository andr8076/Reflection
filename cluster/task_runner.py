#!/usr/bin/env python3
"""Isolated Reflection task runner.

This file is launched by Reflection.py in a child process. It imports one task,
runs it, and writes a compact JSON result. Keeping user/task code in this child
process prevents a broken task from taking down the main worker loop.
"""

import argparse
import importlib.util
import inspect
import json
import os
import sys
import traceback
from pathlib import Path
from typing import Any


def _atomic_write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_suffix(path.suffix + ".tmp")
    with temp_path.open("w", encoding="utf-8") as output:
        json.dump(payload, output, indent=2, sort_keys=True)
        output.write("\n")
    os.replace(temp_path, path)


def _import_task_file(path: Path):
    module_name = f"reflection_isolated_task_{path.stem}"
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        raise ImportError(f"Could not build import spec for task file: {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _find_task(tasks_dir: Path, task_name: str):
    matches = []
    for path in sorted(tasks_dir.glob("*.py")):
        if path.name.startswith("_"):
            continue
        module = _import_task_file(path)
        name = str(getattr(module, "TASK_NAME", path.stem))
        if name == task_name:
            matches.append((path, module))

    if not matches:
        available = []
        for path in sorted(tasks_dir.glob("*.py")):
            if path.name.startswith("_"):
                continue
            try:
                module = _import_task_file(path)
                available.append(str(getattr(module, "TASK_NAME", path.stem)))
            except Exception:
                available.append(path.stem + " (failed to load)")
        raise KeyError(
            f"Unknown task '{task_name}'. Available tasks: {', '.join(available) or 'none'}"
        )

    if len(matches) > 1:
        paths = ", ".join(str(path) for path, _module in matches)
        raise RuntimeError(f"Task name '{task_name}' is defined more than once: {paths}")

    return matches[0]


def _normalize_task_result(result: Any) -> dict:
    if isinstance(result, bool):
        return {
            "success": result,
            "stop_agent": False,
            "reload_tasks": False,
            "cleanup_source": False,
            "message": "",
        }

    if isinstance(result, dict):
        return {
            "success": bool(result.get("success", False)),
            "stop_agent": bool(result.get("stop_agent", False)),
            "reload_tasks": bool(result.get("reload_tasks", False)),
            "cleanup_source": bool(result.get("cleanup_source", False)),
            "message": str(result.get("message", "")),
        }

    # TaskOutcome-like object support without importing Reflection.py.
    if hasattr(result, "success"):
        return {
            "success": bool(getattr(result, "success")),
            "stop_agent": bool(getattr(result, "stop_agent", False)),
            "reload_tasks": bool(getattr(result, "reload_tasks", False)),
            "cleanup_source": bool(getattr(result, "cleanup_source", False)),
            "message": str(getattr(result, "message", "")),
        }

    raise TypeError("Task run() must return a bool, dict, or TaskOutcome-like object.")


def run_task(tasks_dir: Path, module_name: str, source: str, delivery: str, overwrite_allowed: bool) -> dict:
    path, module = _find_task(tasks_dir, module_name)
    runner = getattr(module, "run", None)
    if not callable(runner):
        raise AttributeError(f"{path} must define run(source, delivery, overwrite_allowed).")

    signature = inspect.signature(runner)
    expected_args = ("source", "delivery", "overwrite_allowed")
    if tuple(signature.parameters) != expected_args:
        raise TypeError(f"{path} run function must be run(source, delivery, overwrite_allowed).")

    return _normalize_task_result(runner(source, delivery, overwrite_allowed))


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
            "reload_tasks": False,
            "cleanup_source": False,
            "message": f"{type(exc).__name__}: {exc}\n{traceback.format_exc()}",
        }
        _atomic_write_json(result_path, error)
        return 1


if __name__ == "__main__":
    sys.exit(main())
