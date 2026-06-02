<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/ui_helpers.php';

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
                'idle_shutdown_after_no_job_checks' => (int) ($_POST['idle_shutdown_after_no_job_checks'] ?? 0),
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
            ]);
            $store->updateMachines(reflection_parse_machine_list((string) ($_POST['machines'] ?? '')));
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
$archiveInfo = $store->archiveInfo();
$dataDirectory = dirname((string) $config['storage_path']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings · Reflection Farm Master</title>
    <link rel="stylesheet" href="styles.css">
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
                            Minimum SOC %
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
                            <input type="checkbox" name="ess_shutdown_below_minimum" value="1" <?= !empty($settings['ess_shutdown_below_minimum']) ? 'checked' : '' ?>>
                            Tell workers to shut down after current task when SOC is below minimum
                        </label>
                        <label class="check-row">
                            <input type="checkbox" name="auto_wake_for_queued_jobs" value="1" <?= !empty($settings['auto_wake_for_queued_jobs']) ? 'checked' : '' ?>>
                            Automatically wake enough eligible machines for queued work
                        </label>
                    </div>
                    <div class="ess-status-box">
                        <strong>ESS status: <?= reflection_h(reflection_ess_status_label($settings)) ?></strong>
                        <span>Current SOC: <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</span>
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
                    </div>
                </div>

                <div class="settings-section-box">
                    <h3>Farm computers and Wake-on-LAN</h3>
                    <label>
                        Machine list
                        <textarea name="machines" rows="8" placeholder="render-01,AA:BB:CC:DD:EE:01,5,1&#10;render-02,AA:BB:CC:DD:EE:02,8,1"><?= reflection_h(reflection_machine_list_text($machines)) ?></textarea>
                        <small>One per line: <code>pc_id,mac,soc_margin_percent,wake_enabled</code>. SOC margin is checked per computer against current ESS headroom: SOC minus minimum SOC.</small>
                    </label>
                </div>

                <button type="submit">Save settings</button>
            </form>
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
</body>
</html>
