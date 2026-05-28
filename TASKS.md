# Reflection task modules

Task modules live in the `tasks/` folder. Each file is auto-discovered when the
agent starts, which makes adding a new task a matter of dropping in one
standardized Python module.

## Required task shape

Create `tasks/my_task.py` with this structure:

```python
TASK_NAME = "my_task"
DESCRIPTION = "Short human-readable description."


def install():
    """Optional dependency installer/checker for this task."""
    # Example: check for Blender, FFmpeg, or Python packages here.
    pass


def run(source, delivery, overwrite_allowed):
    """Required task entrypoint."""
    # Do the work and return True on success.
    return True
```

Only `run(source, delivery, overwrite_allowed)` is required. `TASK_NAME`,
`DESCRIPTION`, and `install()` are optional, but recommended.

## Optional install area

Use the optional `install()` function inside a task module for task-specific
software setup or checks. Run installers with:

```bash
python install_tasks.py
```

Run a single task installer with:

```bash
python install_tasks.py my_task
```

Keep installation logic task-specific so a worker only needs the software for the
tasks it will actually execute.
