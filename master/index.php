<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';

$config = reflection_master_config();
$store = new FarmStore($config['storage_path']);
$message = null;
$error = null;

function reflection_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reflection_validate_task(string $module, array $config): ?string
{
    return array_key_exists($module, $config['allowed_tasks']) ? null : 'Choose an allowed task.';
}

function reflection_path_allowed(?string $path, bool $required): ?string
{
    if ($path === null || $path === '') {
        return $required ? 'Path or URI is required for this task.' : null;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return 'Paths and URIs may not contain control characters.';
    }

    return null;
}

function reflection_import_lines(string $raw): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return [];
    }

    $json = json_decode($trimmed, true);
    if (is_array($json)) {
        $paths = [];
        foreach ($json as $entry) {
            if (is_string($entry)) {
                $paths[] = $entry;
                continue;
            }

            if (is_array($entry) && isset($entry['source']) && is_string($entry['source'])) {
                $paths[] = $entry['source'];
            }
        }

        return $paths;
    }

    return preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
}

function reflection_clean_import_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || reflection_string_starts_with($path, '#')) {
        return '';
    }

    return str_replace('\\', '/', $path);
}

function reflection_apply_delivery_template(string $template, string $source): ?string
{
    $template = trim($template);
    if ($template === '') {
        return null;
    }

    $basename = basename($source);
    $extension = pathinfo($basename, PATHINFO_EXTENSION);
    $name = $extension !== '' ? substr($basename, 0, -strlen($extension) - 1) : $basename;

    return strtr($template, [
        '{source}' => $source,
        '{basename}' => $basename,
        '{name}' => $name,
        '{ext}' => $extension,
    ]);
}

function reflection_uploaded_import_text(string $field): string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return '';
    }

    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmpName = (string) ($_FILES[$field]['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return '';
    }

    return (string) file_get_contents($tmpName);
}

function reflection_parse_machine_list(string $raw): array
{
    $machines = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || reflection_string_starts_with($line, '#')) {
            continue;
        }

        $parts = array_map('trim', explode(',', $line));
        $machines[] = [
            'pc_id' => $parts[0] ?? '',
            'mac' => $parts[1] ?? '',
            'soc_margin_percent' => (int) ($parts[2] ?? 5),
            'wake_enabled' => !isset($parts[3]) || !in_array(strtolower($parts[3]), ['0', 'false', 'no', 'off'], true),
        ];
    }

    return $machines;
}

function reflection_machine_list_text(array $machines): string
{
    $lines = [];
    foreach ($machines as $machine) {
        $lines[] = implode(',', [
            $machine['pc_id'] ?? '',
            $machine['mac'] ?? '',
            $machine['soc_margin_percent'] ?? 5,
            !empty($machine['wake_enabled']) ? '1' : '0',
        ]);
    }

    return implode(PHP_EOL, $lines);
}

function reflection_worker_cards(array $workers, array $machines): array
{
    $cards = [];
    foreach ($machines as $machine) {
        $pcId = (string) ($machine['pc_id'] ?? '');
        if ($pcId === '') {
            continue;
        }

        $cards[$pcId] = [
            'pc_id' => $pcId,
            'mac' => $machine['mac'] ?? '',
            'soc_margin_percent' => $machine['soc_margin_percent'] ?? 5,
            'wake_enabled' => !empty($machine['wake_enabled']),
            'version' => '—',
            'current_job' => null,
            'last_check_in' => null,
            'state' => 'configured',
        ];
    }

    foreach ($workers as $worker) {
        $pcId = (string) ($worker['pc_id'] ?? 'unknown');
        $lastCheckIn = (string) ($worker['last_check_in'] ?? '');
        $lastSeen = $lastCheckIn !== '' ? strtotime($lastCheckIn) : false;
        $state = !empty($worker['current_job']) ? 'running' : 'idle';
        if ($lastSeen !== false && (time() - $lastSeen) > 15 * 60) {
            $state = 'stale';
        }

        $cards[$pcId] = array_merge($cards[$pcId] ?? [
            'pc_id' => $pcId,
            'mac' => '',
            'soc_margin_percent' => 5,
            'wake_enabled' => false,
        ], [
            'version' => $worker['version'] ?? '—',
            'current_job' => $worker['current_job'] ?? null,
            'last_check_in' => $worker['last_check_in'] ?? null,
            'state' => $state,
        ]);
    }

    ksort($cards);
    return array_values($cards);
}

function reflection_count_worker_states(array $workerCards): array
{
    $counts = [];
    foreach ($workerCards as $card) {
        $state = (string) ($card['state'] ?? 'unknown');
        $counts[$state] = ($counts[$state] ?? 0) + 1;
    }

    return $counts;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'single');
    $module = trim((string) ($_POST['module'] ?? ''));
    $delivery = trim((string) ($_POST[$formAction === 'bulk' ? 'bulk_delivery' : 'single_delivery'] ?? ''));
    $overwriteAllowed = isset($_POST['overwrite_allowed']);
    $controlTasks = ['noop', 'status', 'reload_tasks', 'shutdown', 'wake_farm'];
    $isControlTask = in_array($module, $controlTasks, true);

    if ($formAction === 'settings') {
        $settings = $store->updateSettings([
            'enforce_version' => isset($_POST['enforce_version']),
            'failure_strategy' => (string) ($_POST['failure_strategy'] ?? 'mark_failed'),
            'max_retries' => (int) ($_POST['max_retries'] ?? 0),
            'ess_soc_percent' => (int) ($_POST['ess_soc_percent'] ?? 100),
            'ess_soc_url' => trim((string) ($_POST['ess_soc_url'] ?? '')),
            'ess_min_soc_percent' => (int) ($_POST['ess_min_soc_percent'] ?? 20),
            'ess_shutdown_below_minimum' => isset($_POST['ess_shutdown_below_minimum']),
        ]);
        $store->updateMachines(reflection_parse_machine_list((string) ($_POST['machines'] ?? '')));
        $message = 'Saved general options.';
    } elseif ($formAction === 'wake_farm') {
        $targets = $store->wakeTargetsForCurrentSoc();
        if ($targets === []) {
            $error = 'No wake-enabled computers fit the current SOC budget.';
        } else {
            $job = $store->createJob('wake_farm', json_encode($targets, JSON_UNESCAPED_SLASHES), null, true);
            $message = 'Queued ' . $job['task_id'] . ' to wake ' . count($targets) . ' computer(s).';
        }
    } elseif ($formAction === 'bulk') {
        $importText = (string) ($_POST['source_list'] ?? '');
        $uploadedText = reflection_uploaded_import_text('source_file');
        if ($uploadedText !== '') {
            $importText .= PHP_EOL . $uploadedText;
        }

        $paths = reflection_import_lines($importText);
        $queued = 0;
        $skipped = [];
        $error = reflection_validate_task($module, $config);

        if ($error === null) {
            foreach ($paths as $lineNumber => $path) {
                $source = reflection_clean_import_path((string) $path);
                if ($source === '') {
                    continue;
                }

                $deliveryPath = reflection_apply_delivery_template($delivery, $source);
                $lineLabel = 'line ' . ((int) $lineNumber + 1) . ' (' . $source . ')';
                $pathError = reflection_path_allowed($source, !$isControlTask)
                    ?? reflection_path_allowed($deliveryPath, false);

                if ($pathError !== null) {
                    $skipped[] = $lineLabel . ': ' . $pathError;
                    continue;
                }

                $store->createJob($module, $source, $deliveryPath, $overwriteAllowed);
                $queued++;
            }

            if ($queued > 0) {
                $message = 'Imported ' . $queued . ' job(s) for ' . $module . '.';
            }

            if ($skipped !== []) {
                $error = 'Skipped ' . count($skipped) . ' item(s): ' . implode(' | ', array_slice($skipped, 0, 6));
                if (count($skipped) > 6) {
                    $error .= ' | ...';
                }
            } elseif ($queued === 0) {
                $error = 'No importable source paths found.';
            }
        }
    } else {
        $source = trim((string) ($_POST['single_source'] ?? ''));
        $error = reflection_validate_task($module, $config)
            ?? reflection_path_allowed($source !== '' ? $source : null, !$isControlTask)
            ?? reflection_path_allowed($delivery !== '' ? $delivery : null, false);

        if ($error === null) {
            $job = $store->createJob(
                $module,
                $source !== '' ? $source : null,
                $delivery !== '' ? $delivery : null,
                $overwriteAllowed,
            );
            $message = 'Queued ' . $job['task_id'] . ' for ' . $job['module'] . '.';
        }
    }
}

$store->refreshEssSocFromConfiguredEndpoint();
$staleCount = $store->requeueStaleJobs((int) $config['stale_after_seconds']);
$data = $store->read();
$jobs = array_reverse($data['jobs']);
$workers = $data['workers'];
$events = $store->readRecentEvents(25);
$fileHistory = array_slice($store->readFileHistory(), 0, 50, true);
$settings = $store->effectiveSettings();
$machines = $store->machines();
$allowedActiveWorkers = $store->allowedActiveWorkers();
$wakeTargetCount = count($store->wakeTargetsForCurrentSoc());
$workerCards = reflection_worker_cards($workers, $machines);
$workerStateCounts = reflection_count_worker_states($workerCards);
$scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$apiPath = ($scriptDirectory === '' ? '' : $scriptDirectory) . '/farm_api.php';
$statusCounts = array_count_values(array_map(static fn (array $job): string => (string) $job['status'], $data['jobs']));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reflection Farm Master</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <div>
            <p class="eyebrow">Reflection</p>
            <h1>Farm Master</h1>
            <p class="lede">Queue approved farm jobs, serve workers through the JSON API, and watch the fleet from one PHP dashboard.</p>
        </div>
        <div class="version-card">
            <span>Required worker version</span>
            <strong><?= reflection_h($config['required_version'] ?? 'not enforced') ?></strong>
        </div>
    </header>

    <?php if ($message !== null): ?>
        <div class="alert success"><?= reflection_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert error"><?= reflection_h($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($config['storage_warning'])): ?>
        <div class="alert warning"><?= reflection_h($config['storage_warning']) ?></div>
    <?php endif; ?>
    <?php if ($staleCount > 0): ?>
        <div class="alert warning"><?= reflection_h($staleCount) ?> stale job(s) were marked for operator review.</div>
    <?php endif; ?>

    <main>
        <section class="panel submit-panel">
            <h2>General options</h2>
            <form method="post">
                <input type="hidden" name="form_action" value="settings">
                <label class="check-row">
                    <input type="checkbox" name="enforce_version" value="1" <?= !empty($settings['enforce_version']) ? 'checked' : '' ?>>
                    Enforce worker version
                </label>
                <label>
                    Failed task behavior
                    <select name="failure_strategy">
                        <option value="mark_failed" <?= ($settings['failure_strategy'] ?? '') === 'mark_failed' ? 'selected' : '' ?>>Mark failed and stop retrying</option>
                        <option value="retry_to_end" <?= ($settings['failure_strategy'] ?? '') === 'retry_to_end' ? 'selected' : '' ?>>Retry by pushing a copy to the end of the queue</option>
                    </select>
                </label>
                <label>
                    Max retries
                    <input type="number" name="max_retries" min="0" value="<?= (int) ($settings['max_retries'] ?? 0) ?>">
                </label>
                <div class="option-grid">
                    <label>
                        ESS SOC %
                        <input type="number" name="ess_soc_percent" min="0" max="100" value="<?= (int) ($settings['ess_soc_percent'] ?? 100) ?>">
                    </label>
                    <label>
                        Minimum SOC %
                        <input type="number" name="ess_min_soc_percent" min="0" max="100" value="<?= (int) ($settings['ess_min_soc_percent'] ?? 20) ?>">
                    </label>
                </div>
                <label>
                    ESS SOC URL
                    <input name="ess_soc_url" value="<?= reflection_h($settings['ess_soc_url'] ?? '') ?>" placeholder="http://192.168.1.245:8076">
                    <small>Defaults to <code>http://192.168.1.245:8076</code>. The master accepts a plain fraction like <code>0.974</code>, a percent like <code>97</code>, or JSON keys like <code>soc</code>, <code>SOC</code>, or <code>battery.soc</code>.</small>
                </label>
                <label class="check-row">
                    <input type="checkbox" name="ess_shutdown_below_minimum" value="1" <?= !empty($settings['ess_shutdown_below_minimum']) ? 'checked' : '' ?>>
                    Tell workers to shut down after current task when SOC is below minimum
                </label>
                <label>
                    Farm computers available for Wake-on-LAN
                    <textarea name="machines" rows="6" placeholder="render-01,AA:BB:CC:DD:EE:01,5,1&#10;render-02,AA:BB:CC:DD:EE:02,8,1"><?= reflection_h(reflection_machine_list_text($machines)) ?></textarea>
                    <small>One per line: <code>pc_id,mac,soc_margin_percent,wake_enabled</code>. Current SOC allows <?= $allowedActiveWorkers === PHP_INT_MAX ? 'unlimited' : (int) $allowedActiveWorkers ?> active worker(s), with <?= (int) $wakeTargetCount ?> wake target(s) in budget.</small>
                </label>
                <button type="submit">Save options</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="form_action" value="wake_farm">
                <button type="submit">Queue Wake-on-LAN task</button>
            </form>
        </section>

        <section class="panel submit-panel">
            <h2>Create jobs</h2>
            <form method="post" enctype="multipart/form-data" id="job-form">
                <label>
                    Submit mode
                    <select name="form_action" id="submit-mode">
                        <option value="single">Single job</option>
                        <option value="bulk">Bulk import</option>
                    </select>
                    <small>Pick single for one source, or bulk to paste/upload a generated file list.</small>
                </label>
                <label>
                    Task
                    <select name="module" required>
                        <?php foreach ($config['allowed_tasks'] as $taskName => $description): ?>
                            <option value="<?= reflection_h($taskName) ?>"><?= reflection_h($taskName) ?> — <?= reflection_h($description) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="mode-fields mode-single">
                    <label>
                        Source path
                        <input name="single_source" placeholder="ftp://farm.local/incoming/source.dat">
                        <small>Required for normal work. Use an FTP/HTTP/SFTP URL or any worker-readable path; the master only passes it through.</small>
                    </label>
                    <label>
                        Delivery path
                        <input name="single_delivery" placeholder="ftp://farm.local/outputs/result.txt">
                        <small>Optional worker-readable delivery URI/path; the master does not create or move files.</small>
                    </label>
                </div>
                <div class="mode-fields mode-bulk" hidden>
                    <label>
                        Source list
                        <textarea name="source_list" rows="8" placeholder="ftp://farm.local/incoming/img001.png&#10;ftp://farm.local/incoming/img002.png"></textarea>
                        <small>Paste newline paths, a JSON array of paths, or upload a list file generated by <code>tools/reflection-file-list.sh</code>.</small>
                    </label>
                    <label>
                        Upload list file
                        <input type="file" name="source_file" accept=".txt,.list,.json,text/plain,application/json">
                    </label>
                    <label>
                        Delivery template
                        <input name="bulk_delivery" placeholder="ftp://farm.local/outputs/{basename}">
                        <small>Optional. Supports <code>{source}</code>, <code>{basename}</code>, <code>{name}</code>, and <code>{ext}</code>. Use any worker-readable delivery URI/path; the master only passes it through.</small>
                    </label>
                </div>
                <label class="check-row">
                    <input type="checkbox" name="overwrite_allowed" value="1">
                    Allow worker to overwrite existing delivery output
                </label>
                <button type="submit" id="submit-button">Queue job</button>
            </form>
        </section>

        <section class="panel stats-panel">
            <h2>Queue status</h2>
            <div class="stats-grid">
                <?php foreach (['queued', 'running', 'success', 'failed', 'stale'] as $status): ?>
                    <div class="stat">
                        <span><?= reflection_h($status) ?></span>
                        <strong><?= (int) ($statusCounts[$status] ?? 0) ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="stat energy">
                    <span>ESS SOC</span>
                    <strong><?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</strong>
                </div>
                <div class="stat energy">
                    <span>active budget</span>
                    <strong><?= $allowedActiveWorkers === PHP_INT_MAX ? '∞' : (int) $allowedActiveWorkers ?></strong>
                </div>
                <div class="stat energy">
                    <span>wake targets</span>
                    <strong><?= (int) $wakeTargetCount ?></strong>
                </div>
            </div>
            <p class="api-note">Point workers at <code><?= reflection_h($apiPath) ?></code>. Use <a href="json_tool.php">JSON Tool</a> to simulate a worker.</p>
        </section>
    </main>

    <section class="panel">
        <h2>Jobs</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Task</th>
                        <th>Status</th>
                        <th>Worker</th>
                        <th>Source → Delivery</th>
                        <th>Timing</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($jobs === []): ?>
                    <tr><td colspan="7" class="empty">No jobs yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><code><?= reflection_h($job['task_id']) ?></code></td>
                        <td><?= reflection_h($job['module']) ?></td>
                        <td><span class="badge <?= reflection_h($job['status']) ?>"><?= reflection_h($job['status']) ?></span></td>
                        <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                        <td><code><?= reflection_h($job['source'] ?? '—') ?></code><br><code><?= reflection_h($job['delivery'] ?? '—') ?></code></td>
                        <td>Created <?= reflection_h($job['created_at'] ?? '—') ?><br>Started <?= reflection_h($job['started_at'] ?? '—') ?><br>Finished <?= reflection_h($job['finished_at'] ?? '—') ?></td>
                        <td><?= reflection_h($job['error'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Event log</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Job</th>
                        <th>Worker</th>
                        <th>Source → Delivery</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($events === []): ?>
                    <tr><td colspan="6" class="empty">No log entries yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= reflection_h($event['timestamp'] ?? '—') ?></td>
                        <td><?= reflection_h($event['event'] ?? '—') ?></td>
                        <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
                        <td><?= reflection_h($event['worker'] ?? '—') ?></td>
                        <td><code><?= reflection_h($event['source'] ?? '—') ?></code><br><code><?= reflection_h($event['delivery'] ?? '—') ?></code></td>
                        <td><?= reflection_h($event['error'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Asset / URI history</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Path or URI</th>
                        <th>Last touched</th>
                        <th>Recent actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($fileHistory === []): ?>
                    <tr><td colspan="3" class="empty">No asset/URI history yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($fileHistory as $path => $touches): ?>
                    <?php $recentTouches = array_slice(array_reverse($touches), 0, 4); ?>
                    <tr>
                        <td><code><?= reflection_h($path) ?></code></td>
                        <td><?= reflection_h($recentTouches[0]['timestamp'] ?? '—') ?></td>
                        <td>
                            <?php foreach ($recentTouches as $touch): ?>
                                <div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> for <code><?= reflection_h($touch['task_id'] ?? '—') ?></code> <?= reflection_h($touch['status'] ?? '') ?></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Farm computers</h2>
        <div class="stats-grid computer-summary">
            <?php foreach (['running', 'idle', 'stale', 'configured'] as $state): ?>
                <div class="stat">
                    <span><?= reflection_h($state) ?></span>
                    <strong><?= (int) ($workerStateCounts[$state] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="computer-grid">
            <?php if ($workerCards === []): ?>
                <p class="empty">No configured computers or worker check-ins yet.</p>
            <?php endif; ?>
            <?php foreach ($workerCards as $card): ?>
                <article class="computer-card <?= reflection_h($card['state'] ?? 'unknown') ?>">
                    <div class="computer-card-head">
                        <strong><?= reflection_h($card['pc_id'] ?? 'unknown') ?></strong>
                        <span class="badge <?= reflection_h($card['state'] ?? 'unknown') ?>"><?= reflection_h($card['state'] ?? 'unknown') ?></span>
                    </div>
                    <dl>
                        <div><dt>Current job</dt><dd><?= reflection_h($card['current_job'] ?? '—') ?></dd></div>
                        <div><dt>Last check-in</dt><dd><?= reflection_h($card['last_check_in'] ?? '—') ?></dd></div>
                        <div><dt>Version</dt><dd><code><?= reflection_h($card['version'] ?? '—') ?></code></dd></div>
                        <div><dt>Wake</dt><dd><?= !empty($card['wake_enabled']) ? 'enabled' : 'disabled' ?><?= !empty($card['mac']) ? ' · ' . reflection_h($card['mac']) : '' ?></dd></div>
                        <div><dt>SOC margin</dt><dd><?= (int) ($card['soc_margin_percent'] ?? 0) ?>%</dd></div>
                    </dl>
                </article>
            <?php endforeach; ?>
        </div>
        <h3>Worker check-in table</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Version</th>
                        <th>Current job</th>
                        <th>Last check-in</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($workers === []): ?>
                    <tr><td colspan="4" class="empty">No worker check-ins yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($workers as $worker): ?>
                    <tr>
                        <td><?= reflection_h($worker['pc_id'] ?? '—') ?></td>
                        <td><code><?= reflection_h($worker['version'] ?? '—') ?></code></td>
                        <td><?= reflection_h($worker['current_job'] ?? '—') ?></td>
                        <td><?= reflection_h($worker['last_check_in'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script>
        (function () {
            var mode = document.getElementById('submit-mode');
            var button = document.getElementById('submit-button');
            var groups = document.querySelectorAll('.mode-fields');
            function syncMode() {
                var selected = mode.value;
                groups.forEach(function (group) {
                    var active = group.classList.contains('mode-' + selected);
                    group.hidden = !active;
                    group.querySelectorAll('input, textarea, select').forEach(function (field) {
                        field.disabled = !active;
                    });
                });
                button.textContent = selected === 'bulk' ? 'Import jobs' : 'Queue job';
            }
            mode.addEventListener('change', syncMode);
            syncMode();
        }());
    </script>
</body>
</html>
