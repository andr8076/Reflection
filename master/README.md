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

Farm-specific defaults live in `farm_settings.php`. Copy/edit this file for each deployed farm so the farm id, display name, dashboard login, storage path, runtime defaults, and allowed task list stay together in one place. The shipped default dashboard / JSON Tool login is:

- Username: `reflection`
- Password: `reflection`

Allowed task names are defined in `config.php`. Source and delivery values are worker-readable paths/URIs such as FTP, HTTP, SFTP, SMB, or local paths on the workers; the master only stores and passes those strings through. The `compress_archive` task expects a worker-local source path and writes a `.tar.xz` archive to the delivery path. The `invert_image` task expects a worker-local image path and writes an inverted image to the delivery path.
Change those credentials before exposing the master website outside a trusted network. The credentials protect operator web pages with HTTP Basic authentication; `farm_api.php` remains unauthenticated so existing workers can continue to poll it.

`config.php` also reads two optional environment variables, which override the matching `farm_settings.php` values:

- `REFLECTION_MASTER_STORE`: path to the JSON store file. Defaults to the `storage_path` in `farm_settings.php`, which ships as `data/farm_store.json` beside the deployed master PHP files.
- `REFLECTION_REQUIRED_VERSION`: required worker Git commit. Defaults to the `required_version` in `farm_settings.php`, or the repository's current commit id when it can be read.

Allowed task names are defined in `farm_settings.php`. Source and delivery values are worker-readable paths/URIs such as FTP, HTTP, SFTP, SMB, or local paths on the workers; the master only stores and passes those strings through.

The bundled `h265_encode` task converts local worker-readable video files to H.265/HEVC MP4 using FFmpeg. Queue a single source video with an optional delivery file, or bulk import several source videos and use a delivery template such as `outputs/{name}_h265.mp4`.

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



## General options, retries, ESS SOC, and Wake-on-LAN

The dashboard leans on status cards for queue, energy, and farm-computer state. The **General options** panel stores runtime policy in the farm store, seeded by the `runtime_defaults` section in `farm_settings.php`:

- Version enforcement can be enabled or disabled without editing PHP files.
- Failed jobs can either remain failed or be copied to the end of the queue until
  the configured retry limit is reached.
- ESS state-of-charge (SOC), an optional ESS status URL, minimum SOC, and
  per-computer SOC margins determine how many workers the master should keep
  active or wake. The default SOC URL is `http://192.168.1.245:8076`. The SOC
  endpoint may return a plain fraction such as `0.974381625411616`, a percent
  such as `97`, or JSON keys such as `soc`, `SOC`, `stateOfCharge`, or
  `battery.soc`.
- When SOC is below the minimum, API responses ask idle workers to stop, and
  workers that understand `shutdown_after_task` stop after completing their
  current task.
- The computer list format is `pc_id,mac,soc_margin_percent,wake_enabled`.

The **Queue Wake-on-LAN task** button creates a `wake_farm` control job. A worker
that receives it sends Wake-on-LAN magic packets for the configured computers
that fit within the current SOC budget.

## Bulk import jobs from a file list

Use the dashboard's **Create jobs** panel and switch **Submit mode** to **Bulk import** to paste or upload a newline list
of source URIs/paths. It also accepts a JSON array of path strings. Every imported
source value is validated as a non-empty worker-readable path/URI before a job is
queued. The master does not create, move, download, or otherwise manage those files.

A helper script can build the list from a folder:

```bash
# From the deployed farm directory, list matching source files.
tools/reflection-file-list.sh -r incoming 'img*.png' > import.list
tools/reflection-file-list.sh -r incoming '*.mp4' -o mp4.list

# When scanning by absolute path, set the base so output remains relative.
tools/reflection-file-list.sh -r /volume1/web/api/farm/incoming -b /volume1/web/api/farm --all > import.list
```

Quote glob patterns such as `*.mp4` so your shell does not expand them before the
script can pass them to `find`.

The optional delivery template supports `{source}`, `{basename}`, `{name}`, and
`{ext}`. For example, `ftp://farm.local/outputs/{basename}` maps
`ftp://farm.local/incoming/img001.png` to
`ftp://farm.local/outputs/img001.png`.


## Logs and asset/URI history

The master writes operational metadata beside the selected farm store file:

- `farm_events.log`: newline-delimited JSON events for queued, started,
  successful, failed, and stale jobs.
- `farm_file_history.json`: per-asset/URI history showing when each source or
  delivery string was queued, started, and finished.

The dashboard shows recent log entries and a compact asset/URI-history table. If the
app is using the temporary fallback store, these files live in the same temporary
fallback directory; configure `REFLECTION_MASTER_STORE` or fix `data/`
permissions for persistent logs.

## Manual JSON worker simulator

Open `json_tool.php` beside the dashboard to send editable JSON requests to
`farm_api.php`. The tool includes presets for the worker lifecycle:

1. `request_task`
2. `confirm_taken`
3. `report_success` / `report_failed`

Edit the JSON before sending to simulate versions, worker IDs, job IDs, and
worker completion responses.

## Worker API lifecycle

The endpoint supports the three actions used by `Reflection.py`:

1. `request_task` records the worker check-in and returns either the next queued
   job, `no_jobs`, or `version_mismatch`.
2. `confirm_taken` atomically locks a queued job for the reporting worker.
3. `report_done` stores the final status and returns `confirmed_by_server` so
   the worker can perform its source cleanup.

The store uses file locking around the JSON file so a basic shared-hosting PHP
setup can safely handle multiple workers polling at the same time.
