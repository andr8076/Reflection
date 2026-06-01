<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/ui_helpers.php';

$config = reflection_master_config();
$store = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$automationStore = null;
try {
    $automationStore = new AutomationStore($dataDirectory);
} catch (Throwable $exception) {
    $automationStore = null;
}

function reflection_tail_lines(string $path, int $limit): array
{
    if ($limit <= 0 || !is_file($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    return array_slice($lines, -$limit);
}

function reflection_read_jsonl_tail(string $path, int $limit): array
{
    $rows = [];
    foreach (reflection_tail_lines($path, $limit) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return array_reverse($rows);
}

function reflection_log_count(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $count = 0;
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return 0;
    }
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $count += substr_count($chunk, "\n");
    }
    fclose($handle);
    return $count;
}

function reflection_log_path(string $dataDirectory, string $name): string
{
    return $dataDirectory . DIRECTORY_SEPARATOR . $name;
}

$validLogs = ['events', 'automation', 'files', 'archive'];
$logType = (string) ($_GET['log'] ?? 'events');
if (!in_array($logType, $validLogs, true)) {
    $logType = 'events';
}
$limit = (int) ($_GET['limit'] ?? 100);
$limit = max(5, min(1000, $limit));
$query = trim((string) ($_GET['q'] ?? ''));

$eventPath = reflection_log_path($dataDirectory, 'farm_events.log');
$automationPath = reflection_log_path($dataDirectory, 'automation_runs.jsonl');
$archivePath = reflection_log_path($dataDirectory, 'farm_job_archive.jsonl');
$fileHistory = [];
if ($logType === 'files') {
    $fileHistory = $store->readFileHistory();
}

$events = $logType === 'events' ? reflection_read_jsonl_tail($eventPath, $limit) : [];
$automationRuns = [];
if ($logType === 'automation') {
    if ($automationStore !== null) {
        $automationRuns = $automationStore->recentRuns($limit);
    } else {
        $automationRuns = reflection_read_jsonl_tail($automationPath, $limit);
    }
}
$archiveJobs = $logType === 'archive' ? reflection_read_jsonl_tail($archivePath, $limit) : [];

if ($query !== '') {
    $filter = static function (array $row) use ($query): bool {
        return stripos(json_encode($row, JSON_UNESCAPED_SLASHES) ?: '', $query) !== false;
    };
    $events = array_values(array_filter($events, $filter));
    $automationRuns = array_values(array_filter($automationRuns, $filter));
    $archiveJobs = array_values(array_filter($archiveJobs, $filter));
    if ($fileHistory !== []) {
        $fileHistory = array_filter($fileHistory, static function (array $touches, string $path) use ($query): bool {
            return stripos($path, $query) !== false || stripos(json_encode($touches, JSON_UNESCAPED_SLASHES) ?: '', $query) !== false;
        }, ARRAY_FILTER_USE_BOTH);
    }
}

$fileHistory = array_slice($fileHistory, 0, $limit, true);
$logMeta = [
    'events' => [
        'title' => 'Event log',
        'description' => 'Master events, job transitions, Wake-on-LAN results, worker errors, and system events.',
        'path' => $eventPath,
        'count' => reflection_log_count($eventPath),
    ],
    'automation' => [
        'title' => 'Automation runs',
        'description' => 'Rule scans, matches, skipped files, queued jobs, and command-filter results.',
        'path' => $automationPath,
        'count' => reflection_log_count($automationPath),
    ],
    'files' => [
        'title' => 'File / URI history',
        'description' => 'Recent source and delivery paths touched by jobs.',
        'path' => reflection_log_path($dataDirectory, 'farm_file_history.json'),
        'count' => count($store->readFileHistory()),
    ],
    'archive' => [
        'title' => 'Archived jobs',
        'description' => 'Completed jobs moved out of the live dashboard store.',
        'path' => $archivePath,
        'count' => reflection_log_count($archivePath),
    ],
];
$currentMeta = $logMeta[$logType];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logs · Reflection Farm Master</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="automation-page">
    <header class="hero compact-hero">
        <div class="hero-main">
            <p class="eyebrow">Reflection farm master</p>
            <h1>Logs</h1>
            <p class="lede">Full operational history for events, automation scans, file/URI touches, and archived jobs.</p>
            <nav class="top-nav">
                <a href="index.php">Dashboard</a>
                <a href="automation.php">Automation</a>
                <a href="storage_servers.php">Storage servers</a>
                <a href="blocked_jobs.php">Blocked jobs</a>
                <a href="system_checks.php">System checks</a>
                <a class="active" href="logs.php">Logs</a>
                <a href="settings.php">Settings</a>
            </nav>
        </div>
        <aside class="version-card">
            <span><?= reflection_h($currentMeta['title']) ?></span>
            <strong><?= (int) $currentMeta['count'] ?> item<?= (int) $currentMeta['count'] === 1 ? '' : 's' ?></strong>
            <small><?= reflection_h($currentMeta['path']) ?></small>
        </aside>
    </header>

    <main class="logs-layout">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Viewer</p>
                    <h2><?= reflection_h($currentMeta['title']) ?></h2>
                    <p class="api-note"><?= reflection_h($currentMeta['description']) ?></p>
                </div>
            </div>
            <nav class="status-tabs log-tabs" aria-label="Log type filters">
                <?php foreach ($logMeta as $type => $meta): ?>
                    <a class="<?= $logType === $type ? 'active' : '' ?>" href="?log=<?= reflection_h($type) ?>&amp;limit=<?= (int) $limit ?>"><?= reflection_h($meta['title']) ?> <span><?= (int) $meta['count'] ?></span></a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="log-filter-form">
                <input type="hidden" name="log" value="<?= reflection_h($logType) ?>">
                <label>
                    Search loaded rows
                    <input name="q" value="<?= reflection_h($query) ?>" placeholder="task id, worker, path, event...">
                </label>
                <label>
                    Rows to load
                    <select name="limit">
                        <?php foreach ([25, 50, 100, 250, 500, 1000] as $option): ?>
                            <option value="<?= $option ?>" <?= $limit === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="ghost-button">Apply</button>
            </form>

            <?php if ($logType === 'events'): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Time</th><th>Event</th><th>Job</th><th>Module</th><th>Worker</th><th>Source / delivery</th><th>Error</th><th>Raw</th></tr></thead>
                        <tbody>
                        <?php if ($events === []): ?><tr><td colspan="8" class="empty">No event rows loaded.</td></tr><?php endif; ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td title="<?= reflection_h($event['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($event['timestamp'] ?? null)) ?></td>
                                <td><?= reflection_h($event['event'] ?? '—') ?></td>
                                <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
                                <td><?= reflection_h($event['module'] ?? '—') ?></td>
                                <td><?= reflection_h($event['worker'] ?? '—') ?></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_value($event['source'] ?? '—', 70)) ?></code><br><code><?= reflection_h(reflection_short_value($event['delivery'] ?? '—', 70)) ?></code></td>
                                <td><?= reflection_h(reflection_short_value($event['error'] ?? '', 120)) ?></td>
                                <td><details><summary>JSON</summary><pre><?= reflection_h(json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($logType === 'automation'): ?>
                <div class="log-card-grid">
                    <?php if ($automationRuns === []): ?><p class="empty">No automation run rows loaded.</p><?php endif; ?>
                    <?php foreach ($automationRuns as $run): ?>
                        <article class="log-card">
                            <div class="log-card-head">
                                <div><strong><?= reflection_h($run['rule_name'] ?? $run['rule_id'] ?? 'Automation run') ?></strong><small><?= reflection_h(reflection_relative_time($run['started_at'] ?? null)) ?> → <?= reflection_h(reflection_relative_time($run['finished_at'] ?? null)) ?></small></div>
                                <span class="badge <?= !empty($run['dry_run']) ? 'queued' : 'success' ?>"><?= !empty($run['dry_run']) ? 'dry run' : 'run' ?></span>
                            </div>
                            <div class="template-preview-grid small-preview-grid">
                                <div class="template-preview-card"><span>Scanned</span><strong><?= (int) ($run['scanned'] ?? 0) ?></strong></div>
                                <div class="template-preview-card"><span>Matched</span><strong><?= (int) ($run['matched'] ?? 0) ?></strong></div>
                                <div class="template-preview-card"><span>Queued</span><strong><?= (int) ($run['queued'] ?? 0) ?></strong></div>
                                <div class="template-preview-card"><span>Errors</span><strong><?= (int) ($run['errors'] ?? 0) ?></strong></div>
                            </div>
                            <?php $rows = is_array($run['rows'] ?? null) ? $run['rows'] : []; ?>
                            <?php if ($rows !== []): ?>
                                <details><summary>Rows <?= count($rows) ?></summary><div class="table-wrap compact-table"><table><thead><tr><th>Status</th><th>Path</th><th>Reason</th><th>Job</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= reflection_h($row['status'] ?? '—') ?></td><td class="path-cell"><code><?= reflection_h(reflection_short_value($row['path'] ?? '', 90)) ?></code></td><td><?= reflection_h(reflection_short_value($row['reason'] ?? '', 120)) ?></td><td><code><?= reflection_h($row['task_id'] ?? '') ?></code></td></tr><?php endforeach; ?></tbody></table></div></details>
                            <?php endif; ?>
                            <details><summary>Raw JSON</summary><pre><?= reflection_h(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($logType === 'files'): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Path or URI</th><th>Last touched</th><th>Recent actions</th><th>Raw</th></tr></thead>
                        <tbody>
                        <?php if ($fileHistory === []): ?><tr><td colspan="4" class="empty">No file or URI history loaded.</td></tr><?php endif; ?>
                        <?php foreach ($fileHistory as $path => $touches): ?>
                            <?php $recentTouches = array_slice(array_reverse($touches), 0, 8); ?>
                            <tr>
                                <td class="path-cell"><code title="<?= reflection_h($path) ?>"><?= reflection_h(reflection_short_value($path, 110)) ?></code></td>
                                <td title="<?= reflection_h($recentTouches[0]['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($recentTouches[0]['timestamp'] ?? null)) ?></td>
                                <td><?php foreach ($recentTouches as $touch): ?><div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> · <code><?= reflection_h($touch['task_id'] ?? '—') ?></code> · <?= reflection_h(reflection_relative_time($touch['timestamp'] ?? null)) ?></div><?php endforeach; ?></td>
                                <td><details><summary>JSON</summary><pre><?= reflection_h(json_encode($touches, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Job</th><th>Task</th><th>Status</th><th>Worker</th><th>Source / delivery</th><th>Finished</th><th>Error</th><th>Raw</th></tr></thead>
                        <tbody>
                        <?php if ($archiveJobs === []): ?><tr><td colspan="8" class="empty">No archived jobs loaded.</td></tr><?php endif; ?>
                        <?php foreach ($archiveJobs as $job): ?>
                            <tr>
                                <td><code><?= reflection_h($job['task_id'] ?? '—') ?></code></td>
                                <td><?= reflection_h($job['module'] ?? '—') ?></td>
                                <td><span class="badge <?= reflection_h(reflection_status_class($job['status'] ?? 'unknown')) ?>"><?= reflection_h($job['status'] ?? 'unknown') ?></span></td>
                                <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_value($job['source'] ?? '—', 80)) ?></code><br><code><?= reflection_h(reflection_short_value($job['delivery'] ?? '—', 80)) ?></code></td>
                                <td title="<?= reflection_h($job['finished_at'] ?? '') ?>"><?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></td>
                                <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 120)) ?></td>
                                <td><details><summary>JSON</summary><pre><?= reflection_h(json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <p>Dashboard log panels show only the last 5 items. Use this page for detailed review.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
</body>
</html>
