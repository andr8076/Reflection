<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/StorageStore.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$store = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
$message = null;
$error = null;

function reflection_check_row(string $name, bool $ok, string $detail, string $hint = ''): array { return compact('name','ok','detail','hint'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string) ($_POST['check_action'] ?? '');
        if ($action === 'queue_storage_test') {
            $serverId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['server_id'] ?? '')) ?: '';
            $server = $storageStore->server($serverId);
            if ($server === null) throw new RuntimeException('Choose a storage server to test.');
            $job = $store->createJob('storage_test', json_encode(['server_id' => $serverId], JSON_UNESCAPED_SLASHES), '', false, ['transfer_server_id' => $serverId]);
            $message = 'Queued storage test job ' . ($job['task_id'] ?? '') . '. An online worker will perform the real credential/read-write test.';
        } elseif ($action === 'refresh_ess') {
            $soc = $store->refreshEssSocFromConfiguredEndpoint();
            $message = $soc === null ? 'ESS check finished but no valid SOC was read.' : 'ESS check read SOC ' . $soc . '%.';
        }
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$data = $store->read();
$settings = $store->effectiveSettings();
$servers = $storageStore->enabledServers(true);
$workers = $data['workers'] ?? [];
$recentWorkerCount = reflection_recent_worker_count(is_array($workers) ? $workers : [], (int) ($config['stale_after_seconds'] ?? 900));
$automationRules = [];
try { $automationRules = (new AutomationStore($dataDirectory, is_array($config['task_specs'] ?? null) ? $config['task_specs'] : []))->rules(); } catch (Throwable $exception) { $automationRules = []; }
$blockedCount = (int) (($store->jobPage(1, 10, 'blocked')['total'] ?? 0));
$archive = $store->archiveInfo();
$checks = [];
$checks[] = reflection_check_row('Data directory writable', is_dir($dataDirectory) && is_writable($dataDirectory), $dataDirectory, 'The web server user must be able to write here.');
$checks[] = reflection_check_row('Farm store readable', is_file((string) $config['storage_path']) || is_writable($dataDirectory), (string) $config['storage_path']);
$checks[] = reflection_check_row('Storage servers configured', count($servers) > 0, count($servers) . ' enabled server(s)', 'Add FTP/SFTP endpoints under Storage servers.');
$checks[] = reflection_check_row('Online/recent workers', $recentWorkerCount > 0, $recentWorkerCount . ' recent of ' . count($workers) . ' worker record(s)', 'Start a worker if this is empty or all workers are stale.');
$checks[] = reflection_check_row('Automation rules', count($automationRules) > 0, count($automationRules) . ' rule(s)', 'Rules are optional but needed for automatic file discovery.');
$checks[] = reflection_check_row('ESS status', ($settings['ess_soc_status'] ?? '') === 'online' || trim((string) ($settings['ess_soc_url'] ?? '')) === '', (string) ($settings['ess_soc_status'] ?? 'manual') . ' · SOC ' . (int) ($settings['ess_soc_percent'] ?? 100) . '%', (string) ($settings['ess_soc_error'] ?? ''));
$checks[] = reflection_check_row('Blocked-job queue', $blockedCount === 0, $blockedCount . ' blocked job(s)', 'Review poison files under Blocked jobs.');
$checks[] = reflection_check_row('Job archive size', true, reflection_format_bytes((int) ($archive['size_bytes'] ?? 0)) . ' · ' . (int) ($archive['jobs'] ?? 0) . ' line(s)');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System checks · Reflection Farm Master</title><?= reflection_stylesheet_links() ?></head>
<body class="automation-page bg-light text-dark container-fluid px-3 px-md-4 py-4">
<header class="hero row g-3 align-items-stretch mb-4 compact-hero"><div class="hero-main col-12 col-lg bg-white border rounded-4 shadow-sm p-4"><p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Reflection farm master</p><h1>System checks</h1><p class="lede text-secondary fs-5 mb-3">Quick checks for the master, storage, ESS, automation, and worker readiness.</p><nav class="top-nav nav nav-pills flex-wrap gap-2 mt-3"><a class="nav-link" href="index.php">Dashboard</a><a class="nav-link" href="automation.php">Automation</a><a class="nav-link" href="storage_servers.php">Storage servers</a><a class="nav-link" href="blocked_jobs.php">Blocked jobs</a><a class="nav-link active" href="system_checks.php">System checks</a><a class="nav-link" href="logs.php">Logs</a><a class="nav-link" href="settings.php">Settings</a></nav></div><aside class="version-card col-12 col-lg-4 bg-white border rounded-4 shadow-sm p-4 d-flex flex-column gap-2"><span>Use this before</span><strong>large automation runs</strong><small>It catches missing storage, dead ESS parsing, blocked jobs, and stale worker state.</small></aside></header>
<?php if ($message !== null): ?><div class="alert success text-bg-success"><?= reflection_h($message) ?></div><?php endif; ?><?php if ($error !== null): ?><div class="alert error alert-danger text-bg-danger"><?= reflection_h($error) ?></div><?php endif; ?>
<main class="automation-layout row g-4 align-items-start">
<section class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 automation-editor col-12 col-xl-8"><div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3"><div><p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Overview</p><h2>Configuration checks</h2></div></div><div class="template-preview-grid row row-cols-1 row-cols-md-2 g-3">
<?php foreach ($checks as $check): ?><article class="template-preview-card <?= $check['ok'] ? 'ok-card' : 'warning-card' ?>"><span><?= reflection_h($check['name']) ?></span><strong><?= $check['ok'] ? 'OK' : 'Needs attention' ?></strong><code><?= reflection_h($check['detail']) ?></code><?php if ($check['hint'] !== ''): ?><small><?= reflection_h($check['hint']) ?></small><?php endif; ?></article><?php endforeach; ?>
</div></section>
<aside class="panel bg-white border rounded-4 shadow-sm p-4 mb-4 automation-sidebar col-12 col-xl-4"><div class="panel-head d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3"><div><p class="eyebrow text-primary text-uppercase fw-bold small mb-1">Live tests</p><h2>Actions</h2></div></div>
<form method="post" class="form-block d-grid gap-2 mb-3 boxed-form-block bg-light border rounded-3 p-3"><input type="hidden" name="check_action" value="refresh_ess"><h3>ESS SOC parser</h3><p class="api-note text-secondary small">Fetch the configured ESS endpoint now and update the read-only live SOC status.</p><button type="submit" class="ghost-button btn btn-outline-primary">Check ESS now</button></form>
<form method="post" class="form-block d-grid gap-2 mb-3 boxed-form-block bg-light border rounded-3 p-3"><input type="hidden" name="check_action" value="queue_storage_test"><h3>Worker storage test</h3><p class="api-note text-secondary small">Queues a control job. The worker uses its own FTP/SFTP login and verifies create, MD5 readback, rename, and delete.</p><select class="form-select" name="server_id"><?php foreach ($servers as $server): ?><option value="<?= reflection_h($server['id'] ?? '') ?>"><?= reflection_h(($server['name'] ?? 'Server') . ' — ' . ($server['scheme'] ?? 'ftp') . '://' . ($server['host'] ?? '')) ?></option><?php endforeach; ?></select><button type="submit" class="ghost-button btn btn-outline-primary" <?= $servers === [] ? 'disabled' : '' ?>>Queue storage test</button></form>
<div class="form-block d-grid gap-2 mb-3 boxed-form-block bg-light border rounded-3 p-3"><h3>Recent workers</h3><?php if ($workers === []): ?><p class="empty text-secondary text-center py-4">No worker has checked in yet.</p><?php endif; ?><?php foreach (array_slice($workers, 0, 6) as $worker): ?><div class="template-preview-card col bg-light border rounded-3 p-3"><span><?= reflection_h($worker['pc_id'] ?? 'worker') ?></span><code><?= reflection_h($worker['version'] ?? 'unknown') ?></code><small>Last seen <?= reflection_h(reflection_relative_time($worker['last_check_in'] ?? null)) ?></small><?php $caps = is_array($worker['capabilities'] ?? null) ? $worker['capabilities'] : []; ?><small><?= !empty($caps['ffmpeg']) ? 'ffmpeg' : 'no ffmpeg' ?> · temp free <?= reflection_format_bytes((int) ($caps['free_temp_bytes'] ?? 0)) ?></small></div><?php endforeach; ?></div>
</aside>
</main><footer><p><a href="index.php">Back to dashboard</a></p></footer><?= reflection_script_links() ?></body></html>
