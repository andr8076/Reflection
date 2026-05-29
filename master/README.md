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
  `data/farm_store.json` beside the deployed master PHP files.
- `REFLECTION_REQUIRED_VERSION`: required worker Git commit. Defaults to the
  repository's current commit id when it can be read.

Allowed task names and safe source/delivery path roots are also defined in
`config.php`.

## Deploy on Synology NAS / shared hosting

The default JSON store path is `data/farm_store.json` beside these PHP files.
This repository includes the `data/` directory so PHP does not have to create it
on the first web request, which avoids a common Synology Web Station permission
error.

If `data/` exists but is not writable, the app automatically falls back to a
writable temporary store so the dashboard and worker API can load instead of
crashing. The dashboard shows a warning when that happens. Treat the fallback as
a recovery mode: NAS temporary directories can be cleaned by the system, so use a
persistent writable store for production.

For the default persistent store, create the directory manually and give the web
server user write access. On Synology, the web server user/group varies by Web
Station configuration, so use DSM File Station permissions or SSH as an
administrator to grant write access to the deployed `data/` directory. For
example, adjust the path to your deployment:

```bash
mkdir -p /volume1/web/api/farm/data
chmod 775 /volume1/web/api/farm/data
```

If your NAS policy does not allow the PHP app to write under the web root, point
`REFLECTION_MASTER_STORE` at another persistent writable JSON file path before
loading `index.php` or `farm_api.php`.

## Worker API lifecycle

The endpoint supports the three actions used by `Reflection.py`:

1. `request_task` records the worker check-in and returns either the next queued
   job, `no_jobs`, or `version_mismatch`.
2. `confirm_taken` atomically locks a queued job for the reporting worker.
3. `report_done` stores the final status and returns `confirmed_by_server` so
   the worker can perform its source cleanup.

The store uses file locking around the JSON file so a basic shared-hosting PHP
setup can safely handle multiple workers polling at the same time.
