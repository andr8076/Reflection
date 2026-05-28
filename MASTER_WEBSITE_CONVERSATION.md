# Master website conversation guide

This file sketches the conversations the future master website should support.
It is not implementation code. It is a shared guide for designing pages, API
payloads, and status messages around the Reflection worker lifecycle.

## Actors

- **User**: Person submitting work from the master website.
- **Master website**: Web UI and backend that accepts work, stores jobs, and
  talks to workers.
- **Worker**: A machine running `Reflection.py`.
- **Task file**: A standardized file in `tasks/` with `run(source, delivery,
  overwrite_allowed)` and an optional `install()` dependency setup area.

## 1. Website creates a job

**User:**
> I want to run `dummy_task` on `incoming/source.dat` and save the result to
> `outputs/result.txt`. Do not overwrite existing output.

**Master website should do:**

1. Validate that `dummy_task` is an allowed task name.
2. Confirm the source path or upload exists.
3. Confirm the delivery path is allowed.
4. Store a queued job with `overwrite_allowed` set to `false`.
5. Show the user a queued status and job id.

**Example queued job record:**

```json
{
  "task_id": "job_1001",
  "module": "dummy_task",
  "source": "incoming/source.dat",
  "delivery": "outputs/result.txt",
  "overwrite_allowed": false,
  "status": "queued"
}
```

**Website response to user:**
> Job `job_1001` has been queued for `dummy_task`. Waiting for an available
> worker.

## 2. Worker asks the website for work

**Worker request:**

```json
{
  "action": "request_task",
  "version": "1.0.0",
  "pc_id": "render-node-01"
}
```

**Master website response when work is available:**

```json
{
  "status": "task_available",
  "task": {
    "task_id": "job_1001",
    "module": "dummy_task",
    "source": "incoming/source.dat",
    "delivery": "outputs/result.txt",
    "overwrite_allowed": false
  }
}
```

**Master website response when no work is available:**

```json
{
  "status": "no_jobs"
}
```

**Master website response when worker version is wrong:**

```json
{
  "status": "version_mismatch",
  "required_version": "1.0.0"
}
```

## 3. Worker locks the job

**Worker request:**

```json
{
  "action": "confirm_taken",
  "version": "1.0.0",
  "pc_id": "render-node-01",
  "task_id": "job_1001"
}
```

**Master website should do:**

1. Confirm the job is still queued.
2. Mark it as running.
3. Store which worker took it.
4. Store a start timestamp.

**Master website response:**

```json
{
  "status": "acknowledged"
}
```

**Website response to user:**
> Job `job_1001` is now running on `render-node-01`.

## 4. Worker runs the local task file

The worker finds the task file by matching the job's `module` value to a task
name loaded from `tasks/`. For example, `module: "dummy_task"` maps to
`tasks/dummy_task.py`.

The task file receives exactly these values:

```python
run(
    source="incoming/source.dat",
    delivery="outputs/result.txt",
    overwrite_allowed=False,
)
```

If the task needs additional software, the worker operator can prepare that
machine ahead of time by running the task's local install area:

```bash
python Reflection.py --install-task dummy_task
```

## 5. Worker reports completion

**Success request:**

```json
{
  "action": "report_done",
  "version": "1.0.0",
  "pc_id": "render-node-01",
  "task_id": "job_1001",
  "status": "success",
  "error": ""
}
```

**Failure request:**

```json
{
  "action": "report_done",
  "version": "1.0.0",
  "pc_id": "render-node-01",
  "task_id": "job_1001",
  "status": "failed",
  "error": "Target delivery file exists and overwrite is disabled."
}
```

**Master website should do:**

1. Store the final status.
2. Store the error message when present.
3. Store a finish timestamp.
4. Make the result visible to the user when successful.
5. Allow retry or troubleshooting when failed.

**Master website response:**

```json
{
  "status": "confirmed_by_server"
}
```

## 6. Website shows final status to user

**Success message:**
> Job `job_1001` finished successfully. Your output is available at
> `outputs/result.txt`.

**Failure message:**
> Job `job_1001` failed on `render-node-01`: Target delivery file exists and
> overwrite is disabled.

## Suggested website pages

- **Submit job**: Choose task, source, delivery path, and overwrite policy.
- **Job queue**: Show queued, running, successful, and failed jobs.
- **Job detail**: Show task name, paths, worker id, timestamps, status, and
  error text.
- **Worker list**: Show worker id, version, last check-in time, current job, and
  whether the worker is compatible with the website version.
- **Task catalog**: Show available task names, descriptions, and setup notes from
  each task file.

## Suggested job statuses

- `queued`: The website has accepted the job, but no worker has taken it yet.
- `running`: A worker has acknowledged and locked the job.
- `success`: The worker completed the task and the website confirmed closeout.
- `failed`: The worker reported an error.
- `stale`: A worker took the job but has not checked in within the expected
  timeout window.

## Design notes for the future website

- Keep task names stable because workers use the task name to find the local
  task file.
- Do not let users submit arbitrary Python. Users should choose from approved
  task names.
- Treat source and delivery paths as untrusted input. Validate that they stay
  inside approved storage locations.
- Show worker version mismatches clearly so old workers can be updated.
- Store the worker's error text exactly, but display it safely in the UI.
