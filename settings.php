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

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['settings_action'] ?? 'save');
        if ($action === 'refresh_ess') {
            $soc = $store->refreshEssSocFromConfiguredEndpoint();
            $message = $soc === null ? 'ESS check finished but did not read a valid SOC value.' : 'ESS check read SOC ' . $soc . '%.';
        } elseif ($action === 'maintenance') {
            $maintenance = reflection_run_store_maintenance($store, $store->effectiveSettings());
            $message = 'Maintenance complete. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
        } elseif ($action === 'purge_quarantine') {
            $quarantineId = trim((string) ($_POST['quarantine_id'] ?? ''));
            $location = $store->quarantineLocation($quarantineId);
            if ($location === null) {
                throw new RuntimeException('Unknown quarantine folder.');
            }
            $sourcePayload = [
                'id' => (string) ($location['id'] ?? $quarantineId),
                'uri' => (string) ($location['uri'] ?? ''),
                'path' => (string) ($location['path'] ?? ''),
            ];
            $sourceJson = json_encode($sourcePayload, JSON_UNESCAPED_SLASHES);
            if ($sourceJson === false) {
                throw new RuntimeException('Could not encode quarantine purge job.');
            }
            if ($store->hasOpenJob('purge_quarantine', $sourceJson)) {
                $message = 'A quarantine delete job is already queued or running for that folder.';
            } else {
                $jobExtra = [
                    'quarantine_location_id' => (string) ($location['id'] ?? $quarantineId),
                    'task_contract' => [
                        'source' => 'tracked_quarantine_folder',
                        'delivery' => 'none',
                        'output' => 'manual_cleanup',
                    ],
                ];
                if (!empty($location['server_id'])) {
                    $jobExtra['transfer_server_id'] = (string) $location['server_id'];
                }
                $job = $store->createJob('purge_quarantine', $sourceJson, '', false, $jobExtra);
                $store->markQuarantinePurgeQueued((string) ($location['id'] ?? $quarantineId), (string) ($job['task_id'] ?? ''));
                $message = 'Queued quarantine delete job ' . (string) ($job['task_id'] ?? '') . ' for ' . (string) ($location['uri'] ?? $location['path'] ?? 'the selected folder') . '.';
            }
        } else {
            $settings = $store->updateSettings([
                'enforce_version' => isset($_POST['enforce_version']),
                'failure_strategy' => (string) ($_POST['failure_strategy'] ?? 'mark_failed'),
                'max_retries' => (int) ($_POST['max_retries'] ?? 0),
                'stale_job_strategy' => (string) ($_POST['stale_job_strategy'] ?? 'requeue_to_end'),
                'stale_max_retries' => (int) ($_POST['stale_max_retries'] ?? 1),
                'crash_loop_protection_enabled' => isset($_POST['crash_loop_protection_enabled']),
                'crash_loop_lost_attempts' => (int) ($_POST['crash_loop_lost_attempts'] ?? 2),
                'crash_loop_distinct_workers' => (int) ($_POST['crash_loop_distinct_workers'] ?? 1),
                'ess_soc_url' => trim((string) ($_POST['ess_soc_url'] ?? '')),
                'ess_min_soc_percent' => (int) ($_POST['ess_min_soc_percent'] ?? 20),
                'ess_shutdown_below_minimum' => isset($_POST['ess_shutdown_below_minimum']),
                'ess_ignore_when_unavailable' => isset($_POST['ess_ignore_when_unavailable']),
                'ess_charging_override_enabled' => isset($_POST['ess_charging_override_enabled']),
                'idle_shutdown_after_no_job_checks' => (int) ($_POST['idle_shutdown_after_no_job_checks'] ?? 0),
                'prefer_lower_shutdown_layers_for_work' => isset($_POST['prefer_lower_shutdown_layers_for_work']),
                'shutdown_debug_mode' => isset($_POST['shutdown_debug_mode']),
                'auto_wake_for_queued_jobs' => isset($_POST['auto_wake_for_queued_jobs']),
                'automation_run_due_on_worker_checkin' => isset($_POST['automation_run_due_on_worker_checkin']),
                'automation_checkin_cooldown_seconds' => (int) ($_POST['automation_checkin_cooldown_seconds'] ?? 60),
                'wake_dispatch_mode' => (string) ($_POST['wake_dispatch_mode'] ?? 'worker_relay'),
                'auto_wake_cooldown_seconds' => (int) ($_POST['auto_wake_cooldown_seconds'] ?? 300),
                'auto_wake_max_targets_per_run' => (int) ($_POST['auto_wake_max_targets_per_run'] ?? 20),
                'wake_broadcast_address' => trim((string) ($_POST['wake_broadcast_address'] ?? '255.255.255.255')),
                'wake_udp_port' => (int) ($_POST['wake_udp_port'] ?? 9),
                'job_history_keep_completed' => (int) ($_POST['job_history_keep_completed'] ?? 500),
                'event_log_keep_lines' => (int) ($_POST['event_log_keep_lines'] ?? 1000),
                'file_history_keep_paths' => (int) ($_POST['file_history_keep_paths'] ?? 500),
                'file_history_keep_entries_per_path' => (int) ($_POST['file_history_keep_entries_per_path'] ?? 10),
                'job_archive_keep_lines' => (int) ($_POST['job_archive_keep_lines'] ?? 5000),
                'worker_temp_max_age_hours' => (int) ($_POST['worker_temp_max_age_hours'] ?? 24),
                'quarantine_keep_days' => (int) ($_POST['quarantine_keep_days'] ?? 14),
                'quarantine_max_gb' => (float) ($_POST['quarantine_max_gb'] ?? 100),
            ]);
            $postedMachines = reflection_parse_machine_form($_POST);
            $store->updateMachines($postedMachines ?? reflection_parse_machine_list((string) ($_POST['machines'] ?? '')));
            $maintenance = reflection_run_store_maintenance($store, $settings);
            $message = 'Saved settings. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$store->refreshEssSocFromConfiguredEndpoint();
$settings = $store->effectiveSettings();
$machines = $store->machines();
$machineRows = $machines === [] ? [['pc_id' => '', 'mac' => '', 'min_soc_percent' => '', 'wake_enabled' => true, 'shutdown_layer' => 0]] : array_values($machines);
$archiveInfo = $store->archiveInfo();
$quarantineLocations = $store->quarantineLocations();
$dataDirectory = dirname((string) $config['storage_path']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings · Reflection Farm Master</title>
    <?= reflection_stylesheet_links() ?>
</head>
<body class="automation-page">
    <header class="hero compact-hero">
        <div class="hero-main">
            <p class="eyebrow">Reflection farm master</p>
            <h1>Settings</h1>
            <p class="lede">Farm policy, ESS, Wake-on-LAN, retention, crash-loop protection, and configured farm machines.</p>
            <nav class="top-nav">
                <a href="index.php">Dashboard</a>
                <a href="automation.php">Automation</a>
                <a href="storage_servers.php">Storage servers</a>
                <a href="blocked_jobs.php">Blocked jobs</a>
                <a href="system_checks.php">System checks</a>
                <a href="logs.php">Logs</a>
                <a class="active" href="settings.php">Settings</a>
            </nav>
        </div>
        <aside class="version-card">
            <span>Data directory</span>
            <strong><?= reflection_h($dataDirectory) ?></strong>
            <small>Archive: <?= (int) ($archiveInfo['jobs'] ?? 0) ?> line(s)</small>
        </aside>
    </header>

    <?php if ($message !== null): ?><div class="alert success"><?= reflection_h($message) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert error"><?= reflection_h($error) ?></div><?php endif; ?>

    <main class="settings-layout">
        <section class="panel settings-page-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Master</p>
                    <h2>Farm settings</h2>
                </div>
                <form method="post" class="inline-form">
                    <input type="hidden" name="settings_action" value="maintenance">
                    <button type="submit" class="ghost-button small-button">Run maintenance now</button>
                </form>
            </div>
            <form method="post" class="settings-form">
                <input type="hidden" name="settings_action" value="save">
                <div class="settings-section-box">
                    <h3>Worker and job policy</h3>
                    <div class="settings-grid">
                        <label class="check-row">
                            <input type="checkbox" name="enforce_version" value="1" <?= !empty($settings['enforce_version']) ? 'checked' : '' ?>>
                            Enforce worker version
                        </label>
                        <p class="api-note">When enabled, the master advertises its Git commit. Workers that see a different commit self-update to that exact commit before accepting work. If the master cannot advertise a commit, workers ignore the version check.</p>
                        <label>
                            Failed task behavior
                            <select name="failure_strategy">
                                <option value="mark_failed" <?= ($settings['failure_strategy'] ?? '') === 'mark_failed' ? 'selected' : '' ?>>Mark failed and stop retrying</option>
                                <option value="retry_to_end" <?= ($settings['failure_strategy'] ?? '') === 'retry_to_end' ? 'selected' : '' ?>>Retry by pushing a copy to the end of the queue</option>
                            </select>
                        </label>
                        <label>
                            Max task-failure retries
                            <input type="number" name="max_retries" min="0" value="<?= (int) ($settings['max_retries'] ?? 0) ?>">
                        </label>
                        <label>
                            Lost/crashed job behavior
                            <select name="stale_job_strategy">
                                <option value="requeue_to_end" <?= ($settings['stale_job_strategy'] ?? 'requeue_to_end') === 'requeue_to_end' ? 'selected' : '' ?>>Requeue after heartbeat timeout</option>
                                <option value="mark_stale" <?= ($settings['stale_job_strategy'] ?? '') === 'mark_stale' ? 'selected' : '' ?>>Mark stale only</option>
                            </select>
                            <small>Running jobs are considered lost if no worker heartbeat arrives before the stale timeout.</small>
                        </label>
                        <label>
                            Max lost-job retries
                            <input type="number" name="stale_max_retries" min="0" value="<?= (int) ($settings['stale_max_retries'] ?? 1) ?>">
                        </label>
                        <label>
                            Idle no-job polls before shutdown
                            <input type="number" name="idle_shutdown_after_no_job_checks" min="0" value="<?= (int) ($settings['idle_shutdown_after_no_job_checks'] ?? 0) ?>">
                            <small>Counts consecutive no-job polls. Changing this value restarts the idle counter so old polls cannot trigger an instant shutdown.</small>
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="shutdown_debug_mode" value="1" <?= !empty($settings['shutdown_debug_mode']) ? 'checked' : '' ?>>
                            Shutdown debug mode
                            <small>When enabled, shutdown requests only stop the farm agent. The computer stays on, stops checking in, and becomes offline/stale from the master view.</small>
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="prefer_lower_shutdown_layers_for_work" value="1" <?= !empty($settings['prefer_lower_shutdown_layers_for_work']) ? 'checked' : '' ?>>
                            Prefer lower shutdown layers for normal work
                            <small>Jobs are not reserved for a computer or a layer. Higher-layer workers simply wait when an eligible idle lower-layer worker is online. Control tasks such as shutdown are not blocked by this work preference.</small>
                        </label>
                    </div>
                </div>

                <div class="settings-section-box">
                    <h3>Crash-loop protection</h3>
                    <div class="settings-grid">
                        <label class="check-row">
                            <input type="checkbox" name="crash_loop_protection_enabled" value="1" <?= !empty($settings['crash_loop_protection_enabled']) ? 'checked' : '' ?>>
                            Block repeated crash-loop jobs
                            <small>If the same module/source keeps becoming lost, stop requeueing it automatically.</small>
                        </label>
                        <label>
                            Lost attempts before block
                            <input type="number" name="crash_loop_lost_attempts" min="1" value="<?= (int) ($settings['crash_loop_lost_attempts'] ?? 2) ?>">
                        </label>
                        <label>
                            Distinct workers before block
                            <input type="number" name="crash_loop_distinct_workers" min="1" value="<?= (int) ($settings['crash_loop_distinct_workers'] ?? 1) ?>">
                            <small>Use 2 if you only want to block after different computers fail on the same work item.</small>
                        </label>
                    </div>
                </div>

                <div class="settings-section-box">
                    <h3>ESS and Wake-on-LAN</h3>
                    <div class="settings-grid">
                        <label>
                            Global fallback minimum ESS SOC %
                            <input type="number" name="ess_min_soc_percent" min="0" max="100" value="<?= (int) ($settings['ess_min_soc_percent'] ?? 20) ?>">
                        </label>
                        <label>
                            ESS SOC URL
                            <input name="ess_soc_url" value="<?= reflection_h($settings['ess_soc_url'] ?? '') ?>" placeholder="http://192.168.1.245:8076">
                            <small>Current SOC is read automatically and shown below. This is the endpoint, not a manual SOC value.</small>
                        </label>
                        <label>
                            Demand wake cooldown seconds
                            <input type="number" name="auto_wake_cooldown_seconds" min="0" value="<?= (int) ($settings['auto_wake_cooldown_seconds'] ?? 300) ?>">
                        </label>
                        <label>
                            Max demand wakes per run
                            <input type="number" name="auto_wake_max_targets_per_run" min="0" value="<?= (int) ($settings['auto_wake_max_targets_per_run'] ?? 20) ?>">
                        </label>
                        <label>
                            WOL broadcast address
                            <input name="wake_broadcast_address" value="<?= reflection_h($settings['wake_broadcast_address'] ?? '255.255.255.255') ?>" placeholder="255.255.255.255">
                            <small>Use 255.255.255.255 for same-LAN wake, or a directed broadcast like 192.168.1.255. Do not use a subnet mask like 255.255.255.0.</small>
                        </label>
                        <label>
                            WOL UDP port
                            <input type="number" name="wake_udp_port" min="1" max="65535" value="<?= (int) ($settings['wake_udp_port'] ?? 9) ?>">
                        </label>
                        <label>
                            Wake-on-LAN delivery method
                            <select name="wake_dispatch_mode">
                                <?php $wakeMode = (string) ($settings['wake_dispatch_mode'] ?? 'worker_relay'); ?>
                                <option value="worker_relay" <?= $wakeMode === 'worker_relay' ? 'selected' : '' ?>>Worker relay task</option>
                                <option value="direct" <?= $wakeMode === 'direct' ? 'selected' : '' ?>>Direct from master PHP</option>
                                <option value="direct_then_worker_relay" <?= $wakeMode === 'direct_then_worker_relay' ? 'selected' : '' ?>>Try direct, then worker relay</option>
                            </select>
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="ess_ignore_when_unavailable" value="1" <?= !empty($settings['ess_ignore_when_unavailable']) ? 'checked' : '' ?>>
                            Ignore SOC limits when the ESS endpoint is offline or unreadable
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="ess_charging_override_enabled" value="1" <?= !empty($settings['ess_charging_override_enabled']) ? 'checked' : '' ?>>
                            Ignore minimum SOC while ESS reports charging
                            <small>Requires the ESS JSON endpoint to return a supported charging value, for example <code>{"soc":0.39,"charging":true}</code>.</small>
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="ess_shutdown_below_minimum" value="1" <?= !empty($settings['ess_shutdown_below_minimum']) ? 'checked' : '' ?>>
                            Tell workers to power off after current task when SOC is below minimum
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="auto_wake_for_queued_jobs" value="1" <?= !empty($settings['auto_wake_for_queued_jobs']) ? 'checked' : '' ?>>
                            Automatically wake enough eligible machines for queued work
                        </label>
                    </div>
                    <div class="ess-status-box">
                        <strong>ESS status: <?= reflection_h(reflection_ess_status_label($settings)) ?></strong>
                        <span>Current SOC: <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</span>
                        <span>Charging: <?= reflection_h(reflection_ess_charging_label($settings)) ?><?= reflection_ess_charging_override_active($settings) ? ' · SOC limits bypassed' : '' ?></span>
                        <span>Last check: <?= reflection_h(reflection_relative_time($settings['ess_soc_last_checked_at'] ?? null)) ?></span>
                        <span>Last valid SOC: <?= reflection_h(reflection_relative_time($settings['ess_soc_last_success_at'] ?? null)) ?></span>
                        <?php if (!empty($settings['ess_soc_error'])): ?>
                            <span class="error-text"><?= reflection_h($settings['ess_soc_error']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="settings-section-box">
                    <h3>Automation trigger policy</h3>
                    <div class="settings-grid">
                        <label class="check-row">
                            <input type="checkbox" name="automation_run_due_on_worker_checkin" value="1" <?= !empty($settings['automation_run_due_on_worker_checkin']) ? 'checked' : '' ?>>
                            Run due automation scans when a worker checks in
                        </label>
                        <label>
                            Automation check-in cooldown seconds
                            <input type="number" name="automation_checkin_cooldown_seconds" min="0" max="3600" value="<?= (int) ($settings['automation_checkin_cooldown_seconds'] ?? 60) ?>">
                            <small>Prevents several farm PCs that boot together from all starting automation scans.</small>
                        </label>
                    </div>
                </div>

                <div class="settings-section-box">
                    <h3>Retention and cleanup</h3>
                    <div class="settings-grid">
                        <label>
                            Completed jobs to keep in live store
                            <input type="number" name="job_history_keep_completed" min="0" value="<?= (int) ($settings['job_history_keep_completed'] ?? 500) ?>">
                        </label>
                        <label>
                            Event log lines to keep
                            <input type="number" name="event_log_keep_lines" min="0" value="<?= (int) ($settings['event_log_keep_lines'] ?? 1000) ?>">
                        </label>
                        <label>
                            File-history paths to keep
                            <input type="number" name="file_history_keep_paths" min="0" value="<?= (int) ($settings['file_history_keep_paths'] ?? 500) ?>">
                        </label>
                        <label>
                            Entries per file-history path
                            <input type="number" name="file_history_keep_entries_per_path" min="0" value="<?= (int) ($settings['file_history_keep_entries_per_path'] ?? 10) ?>">
                        </label>
                        <label>
                            Archived job lines to keep
                            <input type="number" name="job_archive_keep_lines" min="0" value="<?= (int) ($settings['job_archive_keep_lines'] ?? 5000) ?>">
                        </label>
                        <label>
                            Worker temp cleanup age hours
                            <input type="number" name="worker_temp_max_age_hours" min="1" value="<?= (int) ($settings['worker_temp_max_age_hours'] ?? 24) ?>">
                        </label>
                        <label>
                            Remote quarantine keep days
                            <input type="number" name="quarantine_keep_days" min="1" value="<?= (int) ($settings['quarantine_keep_days'] ?? 14) ?>">
                        </label>
                        <label>
                            Remote quarantine max GB per folder
                            <input type="number" name="quarantine_max_gb" min="0" step="0.1" value="<?= htmlspecialchars((string) ($settings['quarantine_max_gb'] ?? 100)) ?>">
                            <small>Old overwrite backups are deleted oldest-first when a <code>.reflection_quarantine</code> folder exceeds this size. Use 0 to disable the size cap.</small>
                        </label>
                    </div>
                </div>

                <div class="settings-section-box">
                    <div class="panel-head inline-panel-head">
                        <div>
                            <h3>Farm computers and Wake-on-LAN</h3>
                            <p class="api-note">Add each farm computer once. Per-computer minimum SOC is the ESS percentage that machine may run down to. Leave it blank to use the global fallback above.</p>
                        </div>
                        <button type="button" class="ghost-button small-button" data-add-machine-row>Add computer</button>
                    </div>
                    <div class="table-wrap machine-editor" data-machine-editor>
                        <table class="machine-editor-table">
                            <thead>
                                <tr>
                                    <th>Computer ID</th>
                                    <th>MAC address</th>
                                    <th>Minimum ESS SOC %</th>
                                    <th>Wake</th>
                                    <th>Shutdown layer</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="machine-editor-body">
                            <?php foreach ($machineRows as $machineIndex => $machine): ?>
                                <?php $machineMinSoc = $machine['min_soc_percent'] ?? ($machine['soc_margin_percent'] ?? ''); ?>
                                <tr>
                                    <td><input name="machine_pc_id[<?= (int) $machineIndex ?>]" value="<?= reflection_h($machine['pc_id'] ?? '') ?>" placeholder="farm1"></td>
                                    <td><input name="machine_mac[<?= (int) $machineIndex ?>]" value="<?= reflection_h($machine['mac'] ?? '') ?>" placeholder="AA:BB:CC:DD:EE:01"></td>
                                    <td><input type="number" name="machine_min_soc_percent[<?= (int) $machineIndex ?>]" min="0" max="100" value="<?= reflection_h($machineMinSoc) ?>" placeholder="fallback"></td>
                                    <td class="center-cell"><input type="checkbox" name="machine_wake_enabled[<?= (int) $machineIndex ?>]" value="1" <?= !isset($machine['wake_enabled']) || !empty($machine['wake_enabled']) ? 'checked' : '' ?>></td>
                                    <td><input type="number" name="machine_shutdown_layer[<?= (int) $machineIndex ?>]" min="0" value="<?= (int) ($machine['shutdown_layer'] ?? 0) ?>"></td>
                                    <td><button type="button" class="ghost-button small-button" data-remove-machine-row>Remove</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <small>Higher shutdown layers power off first. Normal jobs are offered to lower layers first, but no job is reserved for a specific computer or layer. Wake-on-LAN is also phased by layer, so the master wakes the lowest eligible offline layer first before moving upward. Wake can be disabled for a computer while still keeping its SOC and layer policy.</small>
                    <template id="machine-row-template">
                        <tr>
                            <td><input name="machine_pc_id[__INDEX__]" placeholder="farm1"></td>
                            <td><input name="machine_mac[__INDEX__]" placeholder="AA:BB:CC:DD:EE:01"></td>
                            <td><input type="number" name="machine_min_soc_percent[__INDEX__]" min="0" max="100" placeholder="fallback"></td>
                            <td class="center-cell"><input type="checkbox" name="machine_wake_enabled[__INDEX__]" value="1" checked></td>
                            <td><input type="number" name="machine_shutdown_layer[__INDEX__]" min="0" value="0"></td>
                            <td><button type="button" class="ghost-button small-button" data-remove-machine-row>Remove</button></td>
                        </tr>
                    </template>
                    <textarea name="machines" class="legacy-machine-list" aria-hidden="true"><?= reflection_h(reflection_machine_list_text($machines)) ?></textarea>
                </div>

                <button type="submit">Save settings</button>
            </form>
                <div class="settings-section-box">
                    <div class="panel-head inline-panel-head">
                        <div>
                            <h3>Tracked remote overwrite quarantine folders</h3>
                            <p class="api-note">Workers report <code>.reflection_quarantine</code> folders when they safely replace an existing remote output. Manual delete queues a worker control job that empties that tracked folder.</p>
                        </div>
                    </div>
                    <?php if ($quarantineLocations === []): ?>
                        <p class="empty">No remote quarantine folders have been reported yet.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="compact-table quarantine-table">
                                <thead>
                                    <tr>
                                        <th>Folder</th>
                                        <th>Last seen</th>
                                        <th>Known contents</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($quarantineLocations as $location): ?>
                                    <tr>
                                        <td>
                                            <strong><?= reflection_h(($location['scheme'] ?? 'ftp') . '://' . ($location['host'] ?? '') . ':' . (string) ($location['port'] ?? '') ) ?></strong>
                                            <code><?= reflection_h($location['path'] ?? '') ?></code>
                                            <small>Last worker: <?= reflection_h($location['last_worker'] ?? 'unknown') ?><?= !empty($location['last_task_id']) ? ' · task ' . reflection_h($location['last_task_id']) : '' ?></small>
                                        </td>
                                        <td><?= reflection_h(reflection_relative_time($location['last_seen_at'] ?? null)) ?></td>
                                        <td><?= (int) ($location['file_count'] ?? 0) ?> file(s)<br><small><?= reflection_h(reflection_format_bytes((int) ($location['size_bytes'] ?? 0))) ?></small></td>
                                        <td>
                                            <?= reflection_h((string) ($location['purge_status'] ?? 'tracked')) ?>
                                            <?php if (!empty($location['last_purge_job'])): ?><br><small>Job <?= reflection_h($location['last_purge_job']) ?></small><?php endif; ?>
                                            <?php if (!empty($location['last_purged_at'])): ?><br><small><?= reflection_h(reflection_relative_time($location['last_purged_at'])) ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Queue a worker job to delete all files in this quarantine folder?');">
                                                <input type="hidden" name="settings_action" value="purge_quarantine">
                                                <input type="hidden" name="quarantine_id" value="<?= reflection_h($location['id'] ?? '') ?>">
                                                <button type="submit" class="danger-button small-button">Delete contents</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

        </section>

        <aside class="panel automation-sidebar">
            <div class="panel-head"><div><p class="eyebrow">Live checks</p><h2>Actions</h2></div></div>
            <form method="post" class="form-block boxed-form-block">
                <input type="hidden" name="settings_action" value="refresh_ess">
                <h3>ESS SOC parser</h3>
                <p class="api-note">Fetch the configured ESS endpoint now and update the read-only SOC status.</p>
                <button type="submit" class="ghost-button">Check ESS now</button>
            </form>
            <div class="form-block boxed-form-block">
                <h3>Related pages</h3>
                <p><a class="ghost-button" href="logs.php">Open Logs</a></p>
                <p><a class="ghost-button" href="system_checks.php">Open System checks</a></p>
                <p><a class="ghost-button" href="storage_servers.php">Open Storage servers</a></p>
            </div>
        </aside>
    </main>

    <footer>
        <p>Protect this dashboard with your web server, VPN, or reverse-proxy auth.</p>
        <p><a href="index.php">Back to dashboard</a></p>
    </footer>
    <script src="<?= reflection_h(reflection_asset_url('settings.js')) ?>"></script>
</body>
</html>
