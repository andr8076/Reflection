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

The second command installs the single once-per-minute tick for the current user. If another scheduler is preferred, run this command once per minute:

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

## H.265 jobs

`h265_encode` processes exactly one video per job and writes MKV. A folder submitted through the dashboard is recursively enumerated by the master and expanded into one independently leased job per supported video. Leave delivery blank for a folder so each output can be generated beside its source.

The master must be able to enumerate a submitted folder. For an unmounted remote folder, use Bulk import with one video path per line or configure an automation scan. The worker rejects directory jobs so a single process can never hide an entire multi-video batch.

## Local task modules

Bundled tasks live in `cluster/tasks/`. Site-specific tasks belong in `cluster/tasks_local/`, which the updater preserves. Every task file must define:

- `TASK_NAME`;
- `TASK_SPEC` with `name`, `production_ready`, valid `source.mode`, valid `delivery.mode`, and `output.kind`;
- `run(source, delivery, overwrite_allowed)`;
- optional declarative `requirements` and optional `install()`.

Tasks marked `production_ready: false` remain examples and are never advertised to the scheduler. A local task cannot replace a bundled or built-in task name.

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
