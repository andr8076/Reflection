# Reflection task modules

The core script is intentionally only split into two places:

- `Reflection.py` keeps the farm-agent lifecycle, task loader, and built-in
  control tasks.
- `tasks/` contains one file per file-processing or render-style task.

To add a regular task, drop a new Python file into `tasks/`. The filename is
used as the task name unless the file defines `TASK_NAME`.


## Included file-processing tasks

- `compress_archive`: compresses a worker-readable source file or directory into
  a small xz-compressed tar archive at `delivery`. The task selects the strongest
  xz preset that fits the worker hardware, uses as many compression threads as
  the CPU/RAM budget allows, and enforces a wall-clock timeout. Set
  `REFLECTION_COMPRESS_MAX_SECONDS` to override the timeout and
  `REFLECTION_COMPRESS_XZ_PRESET` to force an xz preset from `0` through `9`.
- `invert_image`: inverts the colors of a worker-readable image file and writes
  the result to `delivery`. Workers with Pillow installed can process common
  formats such as PNG and JPEG; without Pillow, the task still supports
  uncompressed 24-bit and 32-bit BMP files.

## Built-in control tasks

These task names are reserved by `Reflection.py` and are always available even
when no files exist in `tasks/`:

- `noop`: connectivity check that immediately succeeds.
- `status`: logs worker id, commit version, timestamp, and built-in task names;
  if `delivery` is set, writes that snapshot as JSON.
- `reload_tasks`: reloads the local task registry after reporting success to the
  server.
- `shutdown`: reports success to the server, then exits the agent loop cleanly.

Built-in control tasks do not clean up `source` paths. If a task file tries to
use one of these reserved names, the built-in task wins.


## Included file-processing tasks

- `dummy_task`: placeholder pipeline test task.
- `render_frame`: placeholder for future frame rendering work.
- `h265_encode`: FFmpeg/FFprobe-based H.265 batch encoder. It accepts a
  normal file or folder path in `source`; optional JSON source values can set
  `path`, `recursive`, `extensions`, `skip_hevc`, and `mode` (`software`,
  `hardware`, or `auto`). When `delivery` is blank, outputs are written beside
  each input as `<name>_h265.mp4`; when encoding multiple inputs, `delivery` is
  treated as the output directory.

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

## Advanced task outcomes

Most task files should return `True` or `False`. Control-style tasks can return
a dictionary with these fields:

```python
return {
    "success": True,
    "message": "Optional status text to send as the report error/message field.",
    "reload_tasks": False,
    "stop_agent": False,
    "cleanup_source": True,
}
```

The built-in `reload_tasks` and `shutdown` tasks use this advanced outcome shape
so the agent can finish the normal report-to-server step before changing its own
lifecycle.

## Running a task install area

Run a single task's optional installer through the main script:

```bash
python Reflection.py --install-task my_task
```

There is no separate centralized installer file. `Reflection.py` discovers the
requested task and calls that task file's own `install()` function. Built-in
control tasks do not have install areas.
