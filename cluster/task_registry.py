"""Shared task loading and result normalization for Reflection workers."""

import importlib.util
import inspect
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Optional


@dataclass(frozen=True)
class TaskDefinition:
    """A standardized task loaded from a Python file in the tasks folder."""

    name: str
    run: Callable[[str, str, bool], Any]
    install: Optional[Callable[[], None]] = None
    description: str = ""


def import_task_file(path: Path):
    """Import one task file without requiring the tasks folder to be a package."""
    module_name = f"reflection_task_{path.stem}"
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        raise ImportError(f"Could not build import spec for task file: {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_task_definition(path: Path, module=None) -> TaskDefinition:
    """Validate one task file and return its standardized definition."""
    module = module or import_task_file(path)
    task_name = str(getattr(module, "TASK_NAME", path.stem))
    runner = getattr(module, "run", None)
    if not callable(runner):
        raise AttributeError(f"{path} must define run(source, delivery, overwrite_allowed).")

    signature = inspect.signature(runner)
    expected_args = ("source", "delivery", "overwrite_allowed")
    if tuple(signature.parameters) != expected_args:
        raise TypeError(f"{path} run function must be run(source, delivery, overwrite_allowed).")

    installer = getattr(module, "install", None)
    if installer is not None and not callable(installer):
        raise TypeError(f"{path} install value must be a function when provided.")

    return TaskDefinition(
        name=task_name,
        run=runner,
        install=installer,
        description=str(getattr(module, "DESCRIPTION", "")),
    )


def discover_task_definitions(tasks_dir: Path, on_error=None) -> dict:
    """Load valid task definitions while reporting and skipping broken files."""
    registry = {}
    for path in sorted(tasks_dir.glob("*.py")):
        if path.name.startswith("_"):
            continue
        try:
            definition = load_task_definition(path)
            registry[definition.name] = definition
        except Exception as exc:
            if on_error is not None:
                on_error(path, exc)
    return registry


def find_task_module(tasks_dir: Path, task_name: str):
    """Return one requested task module while tolerating unrelated broken files."""
    matches = []
    available = []
    for path in sorted(tasks_dir.glob("*.py")):
        if path.name.startswith("_"):
            continue
        try:
            module = import_task_file(path)
        except Exception:
            available.append(path.stem + " (failed to load)")
            continue

        name = str(getattr(module, "TASK_NAME", path.stem))
        available.append(name)
        if name == task_name:
            matches.append((path, module))

    if not matches:
        raise KeyError(
            f"Unknown task '{task_name}'. Available tasks: {', '.join(available) or 'none'}"
        )
    if len(matches) > 1:
        paths = ", ".join(str(path) for path, _module in matches)
        raise RuntimeError(f"Task name '{task_name}' is defined more than once: {paths}")
    return matches[0]


def normalize_task_result(result: Any) -> dict:
    """Convert supported task return values into a serializable result dictionary."""
    if isinstance(result, bool):
        return {
            "success": result,
            "stop_agent": False,
            "restart_agent": False,
            "reload_tasks": False,
            "cleanup_source": False,
            "message": "",
        }
    if isinstance(result, dict):
        return {
            "success": bool(result.get("success", False)),
            "stop_agent": bool(result.get("stop_agent", False)),
            "restart_agent": bool(result.get("restart_agent", False)),
            "reload_tasks": bool(result.get("reload_tasks", False)),
            "cleanup_source": bool(result.get("cleanup_source", False)),
            "message": str(result.get("message", "")),
        }
    if hasattr(result, "success"):
        return {
            "success": bool(getattr(result, "success")),
            "stop_agent": bool(getattr(result, "stop_agent", False)),
            "restart_agent": bool(getattr(result, "restart_agent", False)),
            "reload_tasks": bool(getattr(result, "reload_tasks", False)),
            "cleanup_source": bool(getattr(result, "cleanup_source", False)),
            "message": str(getattr(result, "message", "")),
        }
    raise TypeError("Task run() must return a bool, dict, or TaskOutcome-like object.")
