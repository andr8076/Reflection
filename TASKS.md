# Reflection task modules

The core script is intentionally only split into two places:

- `Reflection.py` keeps the farm-agent lifecycle and task loader.
- `tasks/` contains one file per task.

To add a task, drop a new Python file into `tasks/`. The filename is used as the
task name unless the file defines `TASK_NAME`.

## Required task shape

Create `tasks/my_task.py` with this structure:

```python
TASK_NAME = "my_task"
DESCRIPTION = "Short human-readable description."


def install():
    """Optional dependency setup/checks for this task only."""
    # Example: check for Blender, FFmpeg, or Python packages here.
    pass


def run(source, delivery, overwrite_allowed):
    """Required task entrypoint."""
    # Do the work and return True on success.
    return True
```

Only `run(source, delivery, overwrite_allowed)` is required. The optional
`install()` area stays in the task file so dependency setup lives next to the
code that needs it.

## Running a task install area

Run a single task's optional installer through the main script:

```bash
python Reflection.py --install-task my_task
```

There is no separate centralized installer file. `Reflection.py` only discovers
the requested task and calls that task file's own `install()` function.
