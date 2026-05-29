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

function reflection_path_allowed(?string $path, array $roots, bool $required): ?string
{
    if ($path === null || $path === '') {
        return $required ? 'Path is required for this task.' : null;
    }

    $normalized = str_replace('\\', '/', $path);
    if (reflection_string_starts_with($normalized, '/') || reflection_string_contains($normalized, '..')) {
        return 'Paths must be relative and may not contain .. segments.';
    }

    foreach ($roots as $root) {
        if ($normalized === $root || reflection_string_starts_with($normalized, $root . '/')) {
            return null;
        }
    }

    return 'Path must start with one of: ' . implode(', ', $roots) . '.';
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

    return ltrim(str_replace('\\', '/', $path), './');
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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'single');
    $module = trim((string) ($_POST['module'] ?? ''));
    $delivery = trim((string) ($_POST[$formAction === 'bulk' ? 'bulk_delivery' : 'single_delivery'] ?? ''));
    $overwriteAllowed = isset($_POST['overwrite_allowed']);
    $controlTasks = ['noop', 'status', 'reload_tasks', 'shutdown'];
    $isControlTask = in_array($module, $controlTasks, true);

    if ($formAction === 'bulk') {
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
                $pathError = reflection_path_allowed($source, $config['allowed_source_roots'], !$isControlTask)
                    ?? reflection_path_allowed($deliveryPath, $config['allowed_delivery_roots'], false);

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
            ?? reflection_path_allowed($source !== '' ? $source : null, $config['allowed_source_roots'], !$isControlTask)
            ?? reflection_path_allowed($delivery !== '' ? $delivery : null, $config['allowed_delivery_roots'], false);

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

$staleCount = $store->requeueStaleJobs((int) $config['stale_after_seconds']);
$data = $store->read();
$jobs = array_reverse($data['jobs']);
$workers = $data['workers'];
$events = $store->readRecentEvents(25);
$fileHistory = array_slice($store->readFileHistory(), 0, 50, true);
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
                        <input name="single_source" placeholder="incoming/source.dat">
                        <small>Required for normal work. Allowed roots: <?= reflection_h(implode(', ', $config['allowed_source_roots'])) ?>.</small>
                    </label>
                    <label>
                        Delivery path
                        <input name="single_delivery" placeholder="outputs/result.txt">
                        <small>Allowed roots: <?= reflection_h(implode(', ', $config['allowed_delivery_roots'])) ?>.</small>
                    </label>
                </div>
                <div class="mode-fields mode-bulk" hidden>
                    <label>
                        Source list
                        <textarea name="source_list" rows="8" placeholder="incoming/img001.png&#10;incoming/img002.png"></textarea>
                        <small>Paste newline paths, a JSON array of paths, or upload a list file generated by <code>tools/reflection-file-list.sh</code>.</small>
                    </label>
                    <label>
                        Upload list file
                        <input type="file" name="source_file" accept=".txt,.list,.json,text/plain,application/json">
                    </label>
                    <label>
                        Delivery template
                        <input name="bulk_delivery" placeholder="outputs/{basename}">
                        <small>Optional. Supports <code>{source}</code>, <code>{basename}</code>, <code>{name}</code>, and <code>{ext}</code>. Allowed roots: <?= reflection_h(implode(', ', $config['allowed_delivery_roots'])) ?>.</small>
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
        <h2>File history</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Last touched</th>
                        <th>Recent actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($fileHistory === []): ?>
                    <tr><td colspan="3" class="empty">No file history yet.</td></tr>
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
        <h2>Workers</h2>
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
