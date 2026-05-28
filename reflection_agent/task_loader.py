"""Task discovery and optional installer helpers."""

from __future__ import annotations

import importlib
import inspect
import logging
import pkgutil
from dataclasses import dataclass
from types import ModuleType
from typing import Callable

import tasks

TaskRunner = Callable[[str, str, bool], bool]
TaskInstaller = Callable[[], None]


@dataclass(frozen=True)
class TaskDefinition:
    """Standardized task metadata loaded from a module in the tasks package."""

    name: str
    run: TaskRunner
    install: TaskInstaller | None = None
    description: str = ""


def _load_task_definition(module: ModuleType) -> TaskDefinition:
    """Validate one task module and convert it to a TaskDefinition."""
    name = getattr(module, "TASK_NAME", module.__name__.rsplit(".", 1)[-1])
    runner = getattr(module, "run", None)

    if not callable(runner):
        raise AttributeError(
            f"Task module '{module.__name__}' must define callable run(source, delivery, overwrite_allowed)."
        )

    signature = inspect.signature(runner)
    expected = ("source", "delivery", "overwrite_allowed")
    if tuple(signature.parameters) != expected:
        raise TypeError(
            f"Task module '{module.__name__}' run signature must be run(source, delivery, overwrite_allowed)."
        )

    installer = getattr(module, "install", None)
    if installer is not None and not callable(installer):
        raise TypeError(
            f"Task module '{module.__name__}' install attribute must be callable when provided."
        )

    return TaskDefinition(
        name=name,
        run=runner,
        install=installer,
        description=getattr(module, "DESCRIPTION", ""),
    )


def discover_tasks() -> dict[str, TaskDefinition]:
    """Discover every standardized task module in the tasks package."""
    registry: dict[str, TaskDefinition] = {}

    for module_info in pkgutil.iter_modules(tasks.__path__, prefix=f"{tasks.__name__}."):
        if module_info.ispkg or module_info.name.endswith(".template"):
            continue

        try:
            module = importlib.import_module(module_info.name)
            definition = _load_task_definition(module)
            registry[definition.name] = definition
        except Exception as exc:
            logging.error("Failed to load task module '%s': %s", module_info.name, exc)

    return registry


def install_task_dependencies(task_name: str | None = None) -> None:
    """Run optional install hooks for one task or for every task that defines one."""
    registry = discover_tasks()

    if task_name:
        definition = registry.get(task_name)
        if definition is None:
            available_tasks = ', '.join(sorted(registry))
            raise KeyError(f"Unknown task '{task_name}'. Available tasks: {available_tasks}")
        definitions = [definition]
    else:
        definitions = registry.values()

    for definition in definitions:
        if definition.install is None:
            logging.info("Task '%s' has no optional installer.", definition.name)
            continue

        logging.info("Running optional installer for task '%s'...", definition.name)
        definition.install()
