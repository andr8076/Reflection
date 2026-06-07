<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$store = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$automationStore = null;
try {
    $automationStore = new AutomationStore($dataDirectory, is_array($config['task_specs'] ?? null) ? $config['task_specs'] : []);
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

function reflection_clean_task_ids($raw): array
{
    $values = is_array($raw) ? $raw : [$raw];
    $ids = [];
    foreach ($values as $value) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value) ?: '';
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function reflection_plural(int $count, string $word): string
{
    return $count . ' ' . $word . ($count === 1 ? '' : 's');
}

function reflection_job_status_counts(array $jobs): array
{
    $counts = [];
    foreach ($jobs as $job) {
        $status = (string) ($job['status'] ?? 'unknown');
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
}

function reflection_job_matches_status(array $job, string $statusFilter): bool
{
    $status = (string) ($job['status'] ?? 'unknown');
    if ($statusFilter === 'all') {
        return true;
    }
    if ($statusFilter === 'active') {
        return in_array($status, ['queued', 'running', 'held'], true);
    }
    if ($statusFilter === 'finished') {
        return !in_array($status, ['queued', 'running', 'held'], true);
    }
    return $status === $statusFilter;
}

function reflection_filter_jobs(array $jobs, string $statusFilter, string $query): array
{
    $query = trim($query);
    return array_values(array_filter($jobs, static function (array $job) use ($statusFilter, $query): bool {
        if (!reflection_job_matches_status($job, $statusFilter)) {
            return false;
        }
        if ($query === '') {
            return true;
        }
        return stripos(json_encode($job, JSON_UNESCAPED_SLASHES) ?: '', $query) !== false;
    }));
}

function reflection_paginate(array $rows, int $page, int $perPage): array
{
    $perPage = max(10, min(1000, $perPage));
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return [
        'rows' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'pages' => $pages,
    ];
}

function reflection_logs_url(array $overrides = []): string
{
    $params = $_GET;
    unset($params['_cache']);
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return $query === '' ? 'logs.php' : 'logs.php?' . $query;
}

function reflection_sort_deleted_jobs(array $jobs): array
{
    usort($jobs, static function (array $a, array $b): int {
        $timeA = (string) ($a['deleted_at'] ?? $a['finished_at'] ?? $a['created_at'] ?? '');
        $timeB = (string) ($b['deleted_at'] ?? $b['finished_at'] ?? $b['created_at'] ?? '');
        return strcmp($timeB, $timeA);
    });
    return $jobs;
}

$message = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? '');
    if ($formAction === 'logs_bulk_job_action') {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $taskIds = reflection_clean_task_ids($_POST['task_ids'] ?? []);
        try {
            if ($bulkAction === '') {
                throw new RuntimeException('Choose an action first.');
            }
            if ($bulkAction !== 'empty_bin' && $taskIds === []) {
                throw new RuntimeException('Select at least one job.');
            }

            $changed = 0;
            $skipped = 0;
            if ($bulkAction === 'hold') {
                foreach ($taskIds as $taskId) {
                    $store->holdJob($taskId) ? $changed++ : $skipped++;
                }
                $message = 'Held ' . reflection_plural($changed, 'job') . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . '.' : '.');
            } elseif ($bulkAction === 'release') {
                foreach ($taskIds as $taskId) {
                    $store->releaseHeldJob($taskId) ? $changed++ : $skipped++;
                }
                $message = 'Released ' . reflection_plural($changed, 'job') . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . '.' : '.');
            } elseif ($bulkAction === 'retry') {
                foreach ($taskIds as $taskId) {
                    $store->retryJob($taskId) !== null ? $changed++ : $skipped++;
                }
                $message = 'Queued retries for ' . reflection_plural($changed, 'job') . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . '.' : '.');
            } elseif ($bulkAction === 'delete') {
                foreach ($taskIds as $taskId) {
                    $store->deleteJob($taskId) ? $changed++ : $skipped++;
                }
                $message = 'Moved ' . reflection_plural($changed, 'job') . ' to the bin' . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . ' (running jobs must be held first).' : '.');
            } elseif ($bulkAction === 'restore') {
                foreach ($taskIds as $taskId) {
                    $store->restoreDeletedJob($taskId) ? $changed++ : $skipped++;
                }
                $message = 'Restored ' . reflection_plural($changed, 'job') . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . '.' : '.');
            } elseif ($bulkAction === 'purge') {
                foreach ($taskIds as $taskId) {
                    $store->purgeDeletedJob($taskId) ? $changed++ : $skipped++;
                }
                $message = 'Deleted forever: ' . reflection_plural($changed, 'job') . ($skipped > 0 ? '; skipped ' . reflection_plural($skipped, 'job') . '.' : '.');
            } elseif ($bulkAction === 'empty_bin') {
                $removed = $store->emptyDeletedJobs();
                $message = 'Emptied the bin; deleted forever: ' . reflection_plural($removed, 'job') . '.';
            } else {
                throw new RuntimeException('Unknown bulk action.');
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$validLogs = ['jobs', 'events', 'automation', 'files', 'archive', 'bin'];
$logType = (string) ($_GET['log'] ?? 'jobs');
if (!in_array($logType, $validLogs, true)) {
    $logType = 'jobs';
}
$limit = (int) ($_GET['limit'] ?? 100);
$limit = max(5, min(1000, $limit));
$query = trim((string) ($_GET['q'] ?? ''));
$validJobFilters = ['all', 'active', 'queued', 'running', 'held', 'success', 'failed', 'stale', 'blocked', 'ignored', 'finished'];
$jobStatus = (string) ($_GET['job_status'] ?? 'all');
if (!in_array($jobStatus, $validJobFilters, true)) {
    $jobStatus = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$eventPath = reflection_log_path($dataDirectory, 'farm_events.log');
$automationPath = reflection_log_path($dataDirectory, 'automation_runs.jsonl');
$archivePath = reflection_log_path($dataDirectory, 'farm_job_archive.jsonl');

$data = $store->read();
$liveJobs = array_reverse(array_values($data['jobs'] ?? []));
$deletedJobs = reflection_sort_deleted_jobs(array_values($data['deleted_jobs'] ?? []));
$statusCounts = reflection_job_status_counts($liveJobs);
$deletedStatusCounts = reflection_job_status_counts($deletedJobs);
$activeCount = (int) ($statusCounts['queued'] ?? 0) + (int) ($statusCounts['running'] ?? 0) + (int) ($statusCounts['held'] ?? 0);
$completedInStore = (int) ($statusCounts['success'] ?? 0) + (int) ($statusCounts['failed'] ?? 0) + (int) ($statusCounts['stale'] ?? 0) + (int) ($statusCounts['blocked'] ?? 0) + (int) ($statusCounts['ignored'] ?? 0);
$deletedActiveCount = (int) ($deletedStatusCounts['queued'] ?? 0) + (int) ($deletedStatusCounts['running'] ?? 0) + (int) ($deletedStatusCounts['held'] ?? 0);
$deletedFinishedCount = max(0, count($deletedJobs) - $deletedActiveCount);

$filteredJobs = reflection_filter_jobs($liveJobs, $jobStatus, $query);
$jobPageData = reflection_paginate($filteredJobs, $page, $limit);
$jobs = $jobPageData['rows'];

$filteredDeletedJobs = reflection_filter_jobs($deletedJobs, $jobStatus, $query);
$deletedPageData = reflection_paginate($filteredDeletedJobs, $page, $limit);
$binJobs = $deletedPageData['rows'];

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

if ($query !== '' && !in_array($logType, ['jobs', 'bin'], true)) {
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
    'jobs' => [
        'title' => 'Jobs',
        'description' => 'Live queue store with the same status filters as the dashboard, plus bulk actions.',
        'path' => (string) $config['storage_path'],
        'count' => count($liveJobs),
    ],
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
        'description' => 'Completed jobs moved out of the live dashboard store by maintenance.',
        'path' => $archivePath,
        'count' => reflection_log_count($archivePath),
    ],
    'bin' => [
        'title' => 'Bin',
        'description' => 'Jobs you delete are moved here first, so they can be restored or permanently removed later.',
        'path' => (string) $config['storage_path'],
        'count' => count($deletedJobs),
    ],
];
$currentMeta = $logMeta[$logType];

$tabCounts = [];
foreach ($validJobFilters as $filter) {
    if ($filter === 'all') {
        $tabCounts[$filter] = count($liveJobs);
    } elseif ($filter === 'active') {
        $tabCounts[$filter] = $activeCount;
    } elseif ($filter === 'finished') {
        $tabCounts[$filter] = $completedInStore;
    } else {
        $tabCounts[$filter] = (int) ($statusCounts[$filter] ?? 0);
    }
}
$binTabCounts = [];
foreach ($validJobFilters as $filter) {
    if ($filter === 'all') {
        $binTabCounts[$filter] = count($deletedJobs);
    } elseif ($filter === 'active') {
        $binTabCounts[$filter] = $deletedActiveCount;
    } elseif ($filter === 'finished') {
        $binTabCounts[$filter] = $deletedFinishedCount;
    } else {
        $binTabCounts[$filter] = (int) ($deletedStatusCounts[$filter] ?? 0);
    }
}
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
            <p class="lede">Stable operational history, live queue review, bulk job cleanup, and a recoverable bin.</p>
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
        <aside class="version-card" id="logs-version-card">
            <span><?= reflection_h($currentMeta['title']) ?></span>
            <strong><?= (int) $currentMeta['count'] ?> item<?= (int) $currentMeta['count'] === 1 ? '' : 's' ?></strong>
            <small><?= reflection_h($currentMeta['path']) ?></small>
        </aside>
    </header>

    <main class="logs-layout">
        <section class="panel" id="logs-viewer-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Viewer</p>
                    <h2><?= reflection_h($currentMeta['title']) ?></h2>
                    <p class="api-note"><?= reflection_h($currentMeta['description']) ?></p>
                </div>
            </div>

            <?php if ($message !== null): ?><div class="notice success-notice"><?= reflection_h($message) ?></div><?php endif; ?>
            <?php if ($error !== null): ?><div class="notice error-notice"><?= reflection_h($error) ?></div><?php endif; ?>

            <nav class="status-tabs log-tabs" aria-label="Log type filters">
                <?php foreach ($logMeta as $type => $meta): ?>
                    <a class="<?= $logType === $type ? 'active' : '' ?>" href="<?= reflection_h(reflection_logs_url(['log' => $type, 'page' => 1])) ?>"><?= reflection_h($meta['title']) ?> <span><?= (int) $meta['count'] ?></span></a>
                <?php endforeach; ?>
            </nav>

            <form method="get" class="log-filter-form">
                <input type="hidden" name="log" value="<?= reflection_h($logType) ?>">
                <?php if (in_array($logType, ['jobs', 'bin'], true)): ?>
                    <label>
                        Status
                        <select name="job_status">
                            <?php foreach ($validJobFilters as $filter): ?>
                                <option value="<?= reflection_h($filter) ?>" <?= $jobStatus === $filter ? 'selected' : '' ?>><?= reflection_h($filter) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
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

            <?php if ($logType === 'jobs'): ?>
                <nav class="status-tabs log-tabs" aria-label="Job status filters">
                    <?php foreach ($validJobFilters as $filter): ?>
                        <a class="<?= $jobStatus === $filter ? 'active' : '' ?>" href="<?= reflection_h(reflection_logs_url(['log' => 'jobs', 'job_status' => $filter, 'page' => 1])) ?>"><?= reflection_h($filter) ?> <span><?= (int) ($tabCounts[$filter] ?? 0) ?></span></a>
                    <?php endforeach; ?>
                </nav>

                <form method="post" id="job-bulk-form" class="bulk-action-bar">
                    <input type="hidden" name="form_action" value="logs_bulk_job_action">
                    <label class="select-all-label"><input type="checkbox" data-select-all data-target-form="job-bulk-form"> Select all shown</label>
                    <span class="selection-count" data-selection-count="job-bulk-form">0 selected</span>
                    <button class="ghost-button small-button" type="submit" name="bulk_action" value="hold">Hold selected</button>
                    <button class="ghost-button small-button" type="submit" name="bulk_action" value="release">Release selected</button>
                    <button class="ghost-button small-button" type="submit" name="bulk_action" value="retry" data-confirm="Queue retries for the selected jobs?">Retry selected</button>
                    <button class="danger-button small-button" type="submit" name="bulk_action" value="delete" data-confirm="Move selected jobs to the bin? Running jobs will be skipped.">Move selected to bin</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr><th>Select</th><th>Job</th><th>Task</th><th>Status</th><th>Worker</th><th>Source / delivery</th><th>Timing</th><th>Error</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($jobs === []): ?><tr><td colspan="9" class="empty">No jobs match this filter.</td></tr><?php endif; ?>
                        <?php foreach ($jobs as $job): ?>
                            <?php $jobStatusValue = (string) ($job['status'] ?? 'unknown'); $taskId = (string) ($job['task_id'] ?? ''); ?>
                            <tr>
                                <td><input form="job-bulk-form" type="checkbox" name="task_ids[]" value="<?= reflection_h($taskId) ?>" data-row-select></td>
                                <td><code><?= reflection_h($taskId !== '' ? $taskId : '—') ?></code></td>
                                <td><?= reflection_h($job['module'] ?? '—') ?></td>
                                <td><span class="badge <?= reflection_h(reflection_status_class($jobStatusValue)) ?>"><?= reflection_h($jobStatusValue) ?></span></td>
                                <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                                <td class="path-cell"><code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '—', 80)) ?></code><br><code title="<?= reflection_h($job['delivery'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['delivery'] ?? '—', 80)) ?></code></td>
                                <td><span title="<?= reflection_h($job['created_at'] ?? '') ?>">Created <?= reflection_h(reflection_relative_time($job['created_at'] ?? null)) ?></span><br><span title="<?= reflection_h($job['started_at'] ?? '') ?>">Started <?= reflection_h(reflection_relative_time($job['started_at'] ?? null)) ?></span><br><span title="<?= reflection_h($job['finished_at'] ?? '') ?>">Finished <?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></span></td>
                                <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 120)) ?></td>
                                <td>
                                    <div class="button-row table-actions">
                                        <?php if (in_array($jobStatusValue, ['queued', 'running'], true)): ?>
                                            <form method="post"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="ghost-button small-button" type="submit" name="bulk_action" value="hold">Hold</button></form>
                                        <?php elseif ($jobStatusValue === 'held'): ?>
                                            <form method="post"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="ghost-button small-button" type="submit" name="bulk_action" value="release">Release</button></form>
                                        <?php endif; ?>
                                        <?php if (in_array($jobStatusValue, ['failed', 'stale', 'blocked'], true)): ?>
                                            <form method="post" data-confirm="Queue a fresh retry of this job?"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="ghost-button small-button" type="submit" name="bulk_action" value="retry">Retry</button></form>
                                        <?php endif; ?>
                                        <?php if ($jobStatusValue !== 'running'): ?>
                                            <form method="post" data-confirm="Move this job to the bin?"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="danger-button small-button" type="submit" name="bulk_action" value="delete">Delete</button></form>
                                        <?php else: ?>
                                            <span class="api-note">Hold before deleting.</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><a class="<?= (int) $jobPageData['page'] <= 1 ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_logs_url(['page' => max(1, (int) $jobPageData['page'] - 1)])) ?>">Previous</a><span>Page <?= (int) $jobPageData['page'] ?> of <?= (int) $jobPageData['pages'] ?> · showing <?= count($jobs) ?> of <?= (int) $jobPageData['total'] ?></span><a class="<?= (int) $jobPageData['page'] >= (int) $jobPageData['pages'] ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_logs_url(['page' => min((int) $jobPageData['pages'], (int) $jobPageData['page'] + 1)])) ?>">Next</a></div>

            <?php elseif ($logType === 'events'): ?>
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
            <?php elseif ($logType === 'archive'): ?>
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
            <?php else: ?>
                <nav class="status-tabs log-tabs" aria-label="Bin status filters">
                    <?php foreach ($validJobFilters as $filter): ?>
                        <a class="<?= $jobStatus === $filter ? 'active' : '' ?>" href="<?= reflection_h(reflection_logs_url(['log' => 'bin', 'job_status' => $filter, 'page' => 1])) ?>"><?= reflection_h($filter) ?> <span><?= (int) ($binTabCounts[$filter] ?? 0) ?></span></a>
                    <?php endforeach; ?>
                </nav>

                <form method="post" id="bin-bulk-form" class="bulk-action-bar">
                    <input type="hidden" name="form_action" value="logs_bulk_job_action">
                    <label class="select-all-label"><input type="checkbox" data-select-all data-target-form="bin-bulk-form"> Select all shown</label>
                    <span class="selection-count" data-selection-count="bin-bulk-form">0 selected</span>
                    <button class="ghost-button small-button" type="submit" name="bulk_action" value="restore">Restore selected</button>
                    <button class="danger-button small-button" type="submit" name="bulk_action" value="purge" data-confirm="Delete selected jobs forever?">Delete forever</button>
                    <button class="danger-button small-button" type="submit" name="bulk_action" value="empty_bin" data-confirm="Empty the entire bin forever?">Empty bin</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Select</th><th>Job</th><th>Task</th><th>Status</th><th>Worker</th><th>Source / delivery</th><th>Deleted</th><th>Error</th><th>Actions</th><th>Raw</th></tr></thead>
                        <tbody>
                        <?php if ($binJobs === []): ?><tr><td colspan="10" class="empty">The bin is empty for this filter.</td></tr><?php endif; ?>
                        <?php foreach ($binJobs as $job): ?>
                            <?php $taskId = (string) ($job['task_id'] ?? ''); ?>
                            <tr>
                                <td><input form="bin-bulk-form" type="checkbox" name="task_ids[]" value="<?= reflection_h($taskId) ?>" data-row-select></td>
                                <td><code><?= reflection_h($taskId !== '' ? $taskId : '—') ?></code></td>
                                <td><?= reflection_h($job['module'] ?? '—') ?></td>
                                <td><span class="badge <?= reflection_h(reflection_status_class($job['status'] ?? 'unknown')) ?>"><?= reflection_h($job['status'] ?? 'unknown') ?></span></td>
                                <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                                <td class="path-cell"><code><?= reflection_h(reflection_short_value($job['source'] ?? '—', 80)) ?></code><br><code><?= reflection_h(reflection_short_value($job['delivery'] ?? '—', 80)) ?></code></td>
                                <td title="<?= reflection_h($job['deleted_at'] ?? '') ?>"><?= reflection_h(reflection_relative_time($job['deleted_at'] ?? null)) ?></td>
                                <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 120)) ?></td>
                                <td><div class="button-row table-actions"><form method="post"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="ghost-button small-button" type="submit" name="bulk_action" value="restore">Restore</button></form><form method="post" data-confirm="Delete this job forever?"><input type="hidden" name="form_action" value="logs_bulk_job_action"><input type="hidden" name="task_ids[]" value="<?= reflection_h($taskId) ?>"><button class="danger-button small-button" type="submit" name="bulk_action" value="purge">Delete forever</button></form></div></td>
                                <td><details><summary>JSON</summary><pre><?= reflection_h(json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre></details></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination"><a class="<?= (int) $deletedPageData['page'] <= 1 ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_logs_url(['page' => max(1, (int) $deletedPageData['page'] - 1)])) ?>">Previous</a><span>Page <?= (int) $deletedPageData['page'] ?> of <?= (int) $deletedPageData['pages'] ?> · showing <?= count($binJobs) ?> of <?= (int) $deletedPageData['total'] ?></span><a class="<?= (int) $deletedPageData['page'] >= (int) $deletedPageData['pages'] ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_logs_url(['page' => min((int) $deletedPageData['pages'], (int) $deletedPageData['page'] + 1)])) ?>">Next</a></div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <p>Dashboard log panels show only the last 5 items. Use this page for detailed review and cleanup.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
    <script src="common.js"></script>
    <script src="logs.js"></script>
</body>
</html>
