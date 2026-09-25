# Reflection

Reflection is a small PHP + JSON render/transcode farm master with Python desktop workers. The master owns the queue and recurring policy; each worker reports exactly which tasks it can run, claims one leased job, opens that job in a visible terminal, and does not request more work until the final result is acknowledged.

## Runtime model

- No database server is required. Master state is stored as locked, atomically replaced JSON with last-good `.bak` files.
- A job is offered only to a worker that reports the task, its dependencies, the selected transfer protocol, enough known temporary capacity, isolated execution, and a supported visible terminal.
- A claim is atomic and carries a random lease token. Heartbeats renew it; the worker stops local processing before an unacknowledged lease can expire.
- Worker completion uses a durable local outbox and a stable `completion_id`, making a lost completion response safe to retry.
- `automation_tick.php` is the only recurring master process. It refreshes ESS data, expires leases, runs due automation rules, decides demand wake, and performs retention maintenance.

## Master installation

The master needs PHP 8.1+ with JSON support and a web server that can execute the PHP files. From the repository root:

```bash
./install_master.sh --check
./install_master.sh --install-cron
```

The second command installs the single once-per-minute tick for the current user. Dashboard and System checks requests also make a fallback attempt when the last tick was at least 60 seconds ago. The cron remains necessary while the website is idle. If another scheduler is preferred, run this command once per minute:

```bash
php /absolute/path/to/Reflection/automation_tick.php
```

Open `system_checks.php` after the first tick. It reports JSON writability, tick health, storage configuration, recent workers, visible-terminal readiness, ESS state, automation rules, and blocked jobs.

Master overrides belong in `farm_settings.local.php`; the updater preserves that file and `data/`.

## Worker installation

Workers require a logged-in Linux desktop session and one supported terminal emulator (`x-terminal-emulator`, GNOME Terminal, Konsole, XFCE Terminal, MATE Terminal, LXTerminal, or xterm). Desktop auto-login is needed if the worker should become available immediately after boot.

From `cluster/` as the desktop user, without `sudo`:

```bash
./install.sh
```

The installer:

1. creates `cluster/.venv`;
2. installs the exact package versions in `requirements.txt`;
3. writes or preserves every worker runtime setting;
4. runs production task installers and dependency checks;
5. calls the master's non-job `system_check` endpoint;
6. creates desktop autostart that launches the venv worker in a visible terminal.

Use `./install.sh --skip-server-check` only when preparing a worker before its master is reachable. Re-run `./install.sh --configure` to edit configuration. `./uninstall.sh` removes desktop autostart and keeps configuration unless `--remove-config` is supplied.

## Hardcore Archive jobs

The `hardcore_archive` task sends one folder as one job to the linked [Hardcore Archive](https://github.com/andr8076/Hardcore-Archive) project. It refreshes the repository from `main` before each run and initializes the encoder submodules at the revisions pinned by that commit.

Leave delivery blank to write `<source-folder>.7z` beside the source, or provide a `.7z` delivery path. The source folder must be directly readable by the worker, such as a local or shared mount; the current FTP/SFTP transfer adapter transfers individual files, not whole directories.

The task runs Hardcore Archive's normal verified archive command and never enables source deletion. If a machine is missing a required media tool, the project's doctor handles that in the visible worker terminal.

## H.265 jobs

The `h265_encode` worker task delegates encoding to [265Encode](https://github.com/andr8076/265Encode) through its protocol-2 dependency interface. Reflection does not carry a second FFmpeg encoder implementation.

Worker setup clones the dependency into `cluster/.dependencies/265Encode`. Before each H.265 job and each optional preflight, the worker fetches the latest `main` commit. If GitHub cannot be reached but a previous checkout is available, the worker logs the update failure and uses that installed commit. The repository link, branch, and update policy are declared in `cluster/dependencies.json`; the updater preserves the runtime checkout.

AUTO uses only a capability-proven hardware encoder. It does not silently fall back to CPU encoding. To request software encoding explicitly, use source JSON with `"mode":"software"`. 265Encode evaluates a bounded sample, seals a plan against the encoder/runtime/source/requirements, and validates the complete output before Reflection publishes it. The default policy targets VMAF 92, favors the smallest output, and copies all audio streams.

The task accepts exactly one video per job and writes MKV. A blank delivery path creates `{name}_h265.mkv` beside the source. A folder submitted through the dashboard is recursively enumerated by the master and expanded into one independently leased job per supported video.

The optional worker command filter remains available for candidate screening. It now asks 265Encode to plan a bounded sample and checks predicted saving and quality; details and supported options are in [H.265 preflight](docs/H265_PREFLIGHT.md).

The master must be able to enumerate a submitted folder. For an unmounted remote folder, use Bulk import with one video path per line or configure an automation scan. The worker rejects directory jobs so a single process can never hide an entire multi-video batch.
## Worker task modules

The bundled production task catalogue contains:

- `compress_archive` — compress a source directory into a ZIP archive.
- `hardcore_archive` — archive one source folder into a verified `.7z` using linked Hardcore Archive.
- `h265_encode` — encode one video through the linked [265Encode](https://github.com/andr8076/265Encode) dependency.
- `invert_image` — create an image with inverted colors.

Site-specific tasks belong in `cluster/tasks_local/`, which the updater preserves. Every task file must define:

- `TASK_NAME`;
- `TASK_SPEC` with `name`, `production_ready`, valid `source.mode`, valid `delivery.mode`, and `output.kind`;
- `run(source, delivery, overwrite_allowed)`;
- optional declarative `requirements` and optional `install()`.

Only tasks marked `production_ready: true` are advertised to the scheduler. Keep unfinished examples out of the bundled `cluster/tasks/` catalogue. A local task cannot replace a bundled or built-in task name.

## Updating

From the repository root:

```bash
./update.sh
./update.sh --commit <commit>
```

The updater fetches and validates the replacement before touching live code, resolves exact Python requirements on configured workers, preserves JSON data, local settings, worker configuration, the completion outbox, local tasks, and the worker venv, and restores a full rollback copy if the installed result fails validation. Master-only installations do not require a graphical worker session. The updater does not perform unrelated operating-system upgrades.

## Verification

```bash
python3 -m unittest discover -s cluster/tests -v
for test_file in tests/*_test.php; do php "$test_file"; done
bash -n install_master.sh update.sh cluster/install.sh cluster/uninstall.sh
```

The same checks run in GitHub Actions.
