<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$store = reflection_farm_store($config);
$message = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['blocked_action'] ?? '');
    $taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['task_id'] ?? '')) ?: '';
    try {
        if ($taskId === '') {
            throw new RuntimeException('Missing task id.');
        }
        if ($action === 'retry') {
            $job = $store->retryBlockedJob($taskId);
            $message = $job ? 'Queued retry job ' . ($job['task_id'] ?? '') . '.' : 'Blocked job was not retried.';
        } elseif ($action === 'ignore') {
            $message = $store->markJobIgnored($taskId) ? 'Blocked job marked ignored.' : 'Job was not changed.';
        } elseif ($action === 'unblock') {
            $message = $store->unblockJob($taskId) ? 'Blocked job moved to failed/unblocked. Automation may queue it again.' : 'Job was not changed.';
        } elseif ($action === 'delete') {
            $message = $store->deleteJob($taskId) ? 'Job deleted from live store.' : 'Job was not deleted. Running jobs cannot be deleted.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$blockedJobs = $store->blockedJobs(300);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Blocked jobs · Reflection Farm Master</title>
    <?= reflection_stylesheet_links() ?>
</head>
<body class="automation-page bg-light text-dark container-fluid px-3 px-md-4 py-4">
    <header class="hero row g-3 align-items-stretch mb-4 compact-hero">
        <div class="hero-main col-12 col-lg bg-white border rounded-4 shadow-sm p-4">
            <p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Reflection farm master</p>
            <h1>Blocked jobs</h1>
            <p class="lede text-secondary fs-5 mb-3">Review jobs that were stopped by crash-loop protection so a bad file does not keep killing farm PCs.</p>
            <div class="hero-pills d-flex flex-wrap gap-2"><span>Blocked <code><?= count($blockedJobs) ?></code></span><span>Manual review</span></div>
            <nav class="top-nav nav nav-pills flex-wrap gap-2 mt-3">
                <a class="nav-link" href="index.php">Dashboard</a>
                <a class="nav-link" href="automation.php">Automation</a>
                <a class="nav-link" href="storage_servers.php">Storage servers</a>
                <a class="nav-link active" href="blocked_jobs.php">Blocked jobs</a>
                <a class="nav-link" href="system_checks.php">System checks</a><a class="nav-link" href="logs.php">Logs</a><a class="nav-link" href="settings.php">Settings</a>
            </nav>
        </div>
        <aside class="version-card col-12 col-lg-4 bg-white border rounded-4 shadow-sm p-4 d-flex flex-column gap-2"><span>Best practice</span><strong>do not auto-retry poison files</strong><small>Retry only after inspecting the file/task or changing the worker code.</small></aside>
    </header>

    <?php if ($message !== null): ?><div class="alert success text-bg-success"><?= reflection_h($message) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert error alert-danger text-bg-danger"><?= reflection_h($error) ?></div><?php endif; ?>

    <main class="panel bg-white border rounded-4 shadow-sm p-4 mb-4">
        <div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3"><div><p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Crash-loop protection</p><h2>Blocked work items</h2></div><a class="ghost-button btn btn-outline-primary small-button btn-sm" href="index.php?job_status=blocked">View table filter</a></div>
        <div class="table-wrap table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Task</th><th>Source</th><th>Crash pattern</th><th>Error</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($blockedJobs === []): ?>
                    <tr><td colspan="5" class="empty text-secondary text-center py-4">No blocked jobs right now.</td></tr>
                <?php endif; ?>
                <?php foreach ($blockedJobs as $job): ?>
                    <tr>
                        <td><strong><code><?= reflection_h($job['task_id'] ?? '') ?></code></strong><br><small><?= reflection_h($job['module'] ?? '') ?> · <?= reflection_h(reflection_relative_time($job['blocked_at'] ?? $job['finished_at'] ?? null)) ?></small></td>
                        <td class="path-cell text-break"><code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '', 90)) ?></code></td>
                        <td><small><?= (int) ($job['crash_pattern_count'] ?? 0) ?> lost attempt(s)<br><?= reflection_h(implode(', ', array_slice($job['crash_pattern_workers'] ?? [], 0, 4))) ?></small></td>
                        <td><?= reflection_h(reflection_short_value($job['error'] ?? $job['blocked_reason'] ?? '', 120)) ?></td>
                        <td>
                            <div class="button-row d-flex flex-wrap gap-2 table-actions align-items-center">
                                <form method="post"><input type="hidden" name="blocked_action" value="retry"><input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>"><button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Retry once</button></form>
                                <form method="post"><input type="hidden" name="blocked_action" value="unblock"><input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>"><button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Unblock</button></form>
                                <form method="post"><input type="hidden" name="blocked_action" value="ignore"><input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>"><button class="ghost-button btn btn-outline-primary small-button btn-sm" type="submit">Ignore</button></form>
                                <form method="post" data-confirm="Delete this job from the live store?"><input type="hidden" name="blocked_action" value="delete"><input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>"><button class="danger-button btn btn-outline-danger small-button btn-sm" type="submit">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <footer><p><a href="index.php">Back to dashboard</a></p></footer>
    <script src="<?= reflection_h(reflection_asset_url('common.js')) ?>"></script>
<?= reflection_script_links() ?></body>
</html>
