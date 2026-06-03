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
$automationRules = [];
try { $automationRules = (new AutomationStore($dataDirectory, is_array($config['task_specs'] ?? null) ? $config['task_specs'] : []))->rules(); } catch (Throwable $exception) { $automationRules = []; }
$blockedCount = (int) (($store->jobPage(1, 10, 'blocked')['total'] ?? 0));
$archive = $store->archiveInfo();
$checks = [];
$checks[] = reflection_check_row('Data directory writable', is_dir($dataDirectory) && is_writable($dataDirectory), $dataDirectory, 'The web server user must be able to write here.');
$checks[] = reflection_check_row('Farm store readable', is_file((string) $config['storage_path']) || is_writable($dataDirectory), (string) $config['storage_path']);
$checks[] = reflection_check_row('Storage servers configured', count($servers) > 0, count($servers) . ' enabled server(s)', 'Add FTP/SFTP endpoints under Storage servers.');
$checks[] = reflection_check_row('Online/recent workers', count($workers) > 0, count($workers) . ' worker record(s)', 'Start a worker if this is empty.');
$checks[] = reflection_check_row('Automation rules', count($automationRules) > 0, count($automationRules) . ' rule(s)', 'Rules are optional but needed for automatic file discovery.');
$checks[] = reflection_check_row('ESS status', ($settings['ess_soc_status'] ?? '') === 'online' || trim((string) ($settings['ess_soc_url'] ?? '')) === '', (string) ($settings['ess_soc_status'] ?? 'manual') . ' · SOC ' . (int) ($settings['ess_soc_percent'] ?? 100) . '%', (string) ($settings['ess_soc_error'] ?? ''));
$checks[] = reflection_check_row('Blocked-job queue', $blockedCount === 0, $blockedCount . ' blocked job(s)', 'Review poison files under Blocked jobs.');
$checks[] = reflection_check_row('Job archive size', true, reflection_format_bytes((int) ($archive['size_bytes'] ?? 0)) . ' · ' . (int) ($archive['jobs'] ?? 0) . ' line(s)');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>System checks · Reflection Farm Master</title><link rel="stylesheet" href="styles.css"></head>
<body class="automation-page">
<header class="hero compact-hero"><div class="hero-main"><p class="eyebrow">Reflection farm master</p><h1>System checks</h1><p class="lede">Quick checks for the master, storage, ESS, automation, and worker readiness.</p><nav class="top-nav"><a href="index.php">Dashboard</a><a href="automation.php">Automation</a><a href="storage_servers.php">Storage servers</a><a href="blocked_jobs.php">Blocked jobs</a><a class="active" href="system_checks.php">System checks</a><a href="logs.php">Logs</a><a href="settings.php">Settings</a></nav></div><aside class="version-card"><span>Use this before</span><strong>large automation runs</strong><small>It catches missing storage, dead ESS parsing, blocked jobs, and stale worker state.</small></aside></header>
<?php if ($message !== null): ?><div class="alert success"><?= reflection_h($message) ?></div><?php endif; ?><?php if ($error !== null): ?><div class="alert error"><?= reflection_h($error) ?></div><?php endif; ?>
<main class="automation-layout">
<section class="panel automation-editor"><div class="panel-head"><div><p class="eyebrow">Overview</p><h2>Configuration checks</h2></div></div><div class="template-preview-grid">
<?php foreach ($checks as $check): ?><article class="template-preview-card <?= $check['ok'] ? 'ok-card' : 'warning-card' ?>"><span><?= reflection_h($check['name']) ?></span><strong><?= $check['ok'] ? 'OK' : 'Needs attention' ?></strong><code><?= reflection_h($check['detail']) ?></code><?php if ($check['hint'] !== ''): ?><small><?= reflection_h($check['hint']) ?></small><?php endif; ?></article><?php endforeach; ?>
</div></section>
<aside class="panel automation-sidebar"><div class="panel-head"><div><p class="eyebrow">Live tests</p><h2>Actions</h2></div></div>
<form method="post" class="form-block boxed-form-block"><input type="hidden" name="check_action" value="refresh_ess"><h3>ESS SOC parser</h3><p class="api-note">Fetch the configured ESS endpoint now and update the read-only live SOC status.</p><button type="submit" class="ghost-button">Check ESS now</button></form>
<form method="post" class="form-block boxed-form-block"><input type="hidden" name="check_action" value="queue_storage_test"><h3>Worker storage test</h3><p class="api-note">Queues a control job. The worker uses its own FTP/SFTP login and verifies create, MD5 readback, rename, and delete.</p><select name="server_id"><?php foreach ($servers as $server): ?><option value="<?= reflection_h($server['id'] ?? '') ?>"><?= reflection_h(($server['name'] ?? 'Server') . ' — ' . ($server['scheme'] ?? 'ftp') . '://' . ($server['host'] ?? '')) ?></option><?php endforeach; ?></select><button type="submit" class="ghost-button" <?= $servers === [] ? 'disabled' : '' ?>>Queue storage test</button></form>
<div class="form-block boxed-form-block"><h3>Recent workers</h3><?php if ($workers === []): ?><p class="empty">No worker has checked in yet.</p><?php endif; ?><?php foreach (array_slice($workers, 0, 6) as $worker): ?><div class="template-preview-card"><span><?= reflection_h($worker['pc_id'] ?? 'worker') ?></span><code><?= reflection_h($worker['version'] ?? 'unknown') ?></code><small>Last seen <?= reflection_h(reflection_relative_time($worker['last_check_in'] ?? null)) ?></small><?php $caps = is_array($worker['capabilities'] ?? null) ? $worker['capabilities'] : []; ?><small><?= !empty($caps['ffmpeg']) ? 'ffmpeg' : 'no ffmpeg' ?> · temp free <?= reflection_format_bytes((int) ($caps['free_temp_bytes'] ?? 0)) ?></small></div><?php endforeach; ?></div>
</aside>
</main><footer><p><a href="index.php">Back to dashboard</a></p></footer></body></html>
