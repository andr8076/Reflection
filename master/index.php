<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';

$config = reflection_master_config();
$store = new FarmStore($config['storage_path']);
$message = null;
$error = null;

function reflection_h(mixed $value): string
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
    if (str_starts_with($normalized, '/') || str_contains($normalized, '..')) {
        return 'Paths must be relative and may not contain .. segments.';
    }

    foreach ($roots as $root) {
        if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
            return null;
        }
    }

    return 'Path must start with one of: ' . implode(', ', $roots) . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $module = trim((string) ($_POST['module'] ?? ''));
    $source = trim((string) ($_POST['source'] ?? ''));
    $delivery = trim((string) ($_POST['delivery'] ?? ''));
    $overwriteAllowed = isset($_POST['overwrite_allowed']);
    $controlTasks = ['noop', 'status', 'reload_tasks', 'shutdown'];
    $isControlTask = in_array($module, $controlTasks, true);

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

$staleCount = $store->requeueStaleJobs((int) $config['stale_after_seconds']);
$data = $store->read();
$jobs = array_reverse($data['jobs']);
$workers = $data['workers'];
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
            <h2>Submit job</h2>
            <form method="post">
                <label>
                    Task
                    <select name="module" required>
                        <?php foreach ($config['allowed_tasks'] as $taskName => $description): ?>
                            <option value="<?= reflection_h($taskName) ?>"><?= reflection_h($taskName) ?> — <?= reflection_h($description) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Source path
                    <input name="source" placeholder="incoming/source.dat">
                    <small>Required for normal work. Allowed roots: <?= reflection_h(implode(', ', $config['allowed_source_roots'])) ?>.</small>
                </label>
                <label>
                    Delivery path
                    <input name="delivery" placeholder="outputs/result.txt">
                    <small>Allowed roots: <?= reflection_h(implode(', ', $config['allowed_delivery_roots'])) ?>.</small>
                </label>
                <label class="check-row">
                    <input type="checkbox" name="overwrite_allowed" value="1">
                    Allow worker to overwrite existing delivery output
                </label>
                <button type="submit">Queue job</button>
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
            <p class="api-note">Point workers at <code><?= reflection_h($apiPath) ?></code>.</p>
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
</body>
</html>
