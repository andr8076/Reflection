# Reflection Farm Master PHP website

This directory contains a small PHP master website for the Reflection worker
farm. It provides both:

- `index.php`: an operator dashboard for queueing approved jobs, viewing queue
  state, and seeing worker check-ins.
- `farm_api.php`: the JSON endpoint expected by `Reflection.py` workers.

## Run locally

```bash
php -S 127.0.0.1:8080 -t master
```

Then open <http://127.0.0.1:8080/> and point workers at
`http://127.0.0.1:8080/farm_api.php` while testing.

## Configuration

`config.php` reads two optional environment variables:

- `REFLECTION_MASTER_STORE`: path to the JSON store file. Defaults to
  `master/data/farm_store.json`.
- `REFLECTION_REQUIRED_VERSION`: required worker Git commit. Defaults to the
  repository's current commit id when it can be read.

Allowed task names and safe source/delivery path roots are also defined in
`config.php`.

## Worker API lifecycle

The endpoint supports the three actions used by `Reflection.py`:

1. `request_task` records the worker check-in and returns either the next queued
   job, `no_jobs`, or `version_mismatch`.
2. `confirm_taken` atomically locks a queued job for the reporting worker.
3. `report_done` stores the final status and returns `confirmed_by_server` so
   the worker can perform its source cleanup.

The store uses file locking around the JSON file so a basic shared-hosting PHP
setup can safely handle multiple workers polling at the same time.
