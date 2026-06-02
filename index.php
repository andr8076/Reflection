<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/StorageStore.php';
require_once __DIR__ . '/ui_helpers.php';

reflection_send_security_headers();

$config = reflection_master_config();
$store = reflection_farm_store($config);
$dataDirectory = dirname((string) $config['storage_path']);
$storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
$storageServers = $storageStore->enabledServers(true);
$storageServerIds = array_map(static function (array $server): string {
    return (string) ($server['id'] ?? '');
}, $storageServers);
$message = null;
$error = null;

// Get git commit hash
$gitCommit = null;
$gitHeadFile = __DIR__ . '/.git/HEAD';
if (file_exists($gitHeadFile)) {
    $headContent = trim(file_get_contents($gitHeadFile));
    if (strpos($headContent, 'ref: ') === 0) {
        $refPath = __DIR__ . '/.git/' . substr($headContent, 5);
        if (file_exists($refPath)) {
            $gitCommit = substr(trim(file_get_contents($refPath)), 0, 7);
        }
    } else {
        $gitCommit = substr($headContent, 0, 7);
    }
}

function reflection_storage_server_label(array $server): string
{
    $root = trim((string) ($server['root'] ?? ''));
    $suffix = $root !== '' ? ' · root ' . $root : '';
    return sprintf('%s — %s://%s:%d%s',
        (string) ($server['name'] ?? 'Unnamed server'),
        (string) ($server['scheme'] ?? 'ftp'),
        (string) ($server['host'] ?? ''),
        (int) ($server['port'] ?? 21),
        $suffix
    );
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

function reflection_worker_cards(array $workers, array $machines, int $staleAfterSeconds = 900): array
{
    $cards = [];
    $staleAfterSeconds = max(1, $staleAfterSeconds);
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
            'shutdown_layer' => max(0, (int) ($machine['shutdown_layer'] ?? 0)),
            'version' => '—',
            'current_job' => null,
            'last_check_in' => null,
            'idle_no_job_checkins' => 0,
            'state' => 'configured',
        ];
    }

    foreach ($workers as $worker) {
        $pcId = (string) ($worker['pc_id'] ?? 'unknown');
        $lastCheckIn = (string) ($worker['last_check_in'] ?? '');
        $lastSeen = $lastCheckIn !== '' ? strtotime($lastCheckIn) : false;
        $state = !empty($worker['current_job']) ? 'running' : 'idle';
        if ($lastSeen === false || (time() - $lastSeen) > $staleAfterSeconds) {
            $state = 'stale';
        }

        $cards[$pcId] = array_merge($cards[$pcId] ?? [
            'pc_id' => $pcId,
            'mac' => '',
            'soc_margin_percent' => 5,
            'wake_enabled' => false,
                'shutdown_layer' => 0,
        ], [
            'version' => $worker['version'] ?? '—',
            'current_job' => $worker['current_job'] ?? null,
            'last_check_in' => $worker['last_check_in'] ?? null,
            'idle_no_job_checkins' => max(0, (int) ($worker['idle_no_job_checkins'] ?? 0)),
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

function reflection_url_with(array $overrides): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    $queryString = http_build_query($query);
    return $queryString === '' ? '?' : '?' . $queryString;
}

function reflection_append_message(?string $message, string $addition): string
{
    $addition = trim($addition);
    if ($addition === '') {
        return (string) ($message ?? '');
    }

    $current = trim((string) ($message ?? ''));
    return $current === '' ? $addition : $current . ' ' . $addition;
}

function reflection_manual_wake_result(FarmStore $store, int $staleAfterSeconds): array
{
    $targets = $store->wakeTargetsForCurrentSoc(true, $staleAfterSeconds);
    if ($targets === []) {
        return [
            'message' => null,
            'error' => 'No Wake-on-LAN targets are currently eligible. Check configured machines, MAC addresses, SOC margins, and current online workers.',
        ];
    }

    $wakeResult = $store->dispatchWakeTargets($targets, 'manual');
    $sent = (int) ($wakeResult['sent'] ?? 0);
    $queued = (int) ($wakeResult['queued'] ?? 0);
    if ($queued > 0) {
        $message = 'Queued a Wake-on-LAN relay task for ' . $queued . ' computer' . ($queued === 1 ? '' : 's') . '. The next worker that checks in will send the packets.';
    } else {
        $message = 'Sent Wake-on-LAN packets to ' . $sent . ' computer' . ($sent === 1 ? '' : 's') . '.';
    }
    if (!empty($wakeResult['relay_pending'])) {
        $message = 'A Wake-on-LAN relay task is already queued or running.';
    }

    $failed = (int) ($wakeResult['failed'] ?? 0);
    return [
        'message' => $message,
        'error' => $failed > 0 ? $failed . ' Wake-on-LAN attempt(s) failed. Check recent events for details.' : null,
    ];
}

function reflection_auto_wake_notice(FarmStore $store, int $staleAfterSeconds, string $reason): ?string
{
    $store->refreshEssSocFromConfiguredEndpoint();
    $plan = $store->autoWakeForQueuedJobs($staleAfterSeconds, $reason);
    if (empty($plan['enabled'])) {
        return null;
    }

    $sent = (int) ($plan['wake_result']['sent'] ?? 0);
    $queued = (int) ($plan['wake_result']['queued'] ?? 0);
    $failed = (int) ($plan['wake_result']['failed'] ?? 0);
    $needed = (int) ($plan['needed'] ?? 0);
    $ready = (int) ($plan['ready_targets'] ?? 0);
    if ($queued > 0) {
        return 'Demand wake queued a worker relay task for ' . $queued . ' computer' . ($queued === 1 ? '' : 's') . '.';
    }
    if (!empty($plan['wake_result']['relay_pending'])) {
        return 'Demand wake is waiting for an already queued/running Wake-on-LAN relay task.';
    }
    if ($sent > 0) {
        $notice = 'Demand wake sent to ' . $sent . ' computer' . ($sent === 1 ? '' : 's') . ' for ' . (int) ($plan['queued_work'] ?? 0) . ' queued job' . ((int) ($plan['queued_work'] ?? 0) === 1 ? '' : 's') . '.';
        if ($failed > 0) {
            $notice .= ' ' . $failed . ' wake attempt' . ($failed === 1 ? '' : 's') . ' failed.';
        }
        return $notice;
    }

    if ($needed > 0 && $ready === 0) {
        return 'Demand wake wanted ' . $needed . ' more worker' . ($needed === 1 ? '' : 's') . ', but no eligible Wake-on-LAN target is ready right now.';
    }

    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? 'single');
    $isAjax = (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
    $module = trim((string) ($_POST['module'] ?? ''));
    $delivery = trim((string) ($_POST[$formAction === 'bulk' ? 'bulk_delivery' : 'single_delivery'] ?? ''));
    $overwriteAllowed = isset($_POST['overwrite_allowed']);
    $controlTasks = ['noop', 'status', 'reload_tasks', 'shutdown', 'update_worker', 'wake_farm'];
    $isControlTask = in_array($module, $controlTasks, true);
    $transferServerId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['transfer_server_id'] ?? '')) ?: '';
    $transferExtra = (!$isControlTask && $transferServerId !== '') ? ['transfer_server_id' => $transferServerId] : [];

    if ($formAction === 'job_action') {
        $jobAction = (string) ($_POST['job_action'] ?? '');
        $taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['task_id'] ?? '')) ?: '';
        try {
            if ($taskId === '') {
                throw new RuntimeException('Missing task id.');
            }

            if ($jobAction === 'delete') {
                $message = $store->deleteJob($taskId)
                    ? 'Job deleted from the live store.'
                    : 'Job was not deleted. Running jobs must be held before deletion.';
            } elseif ($jobAction === 'hold') {
                $message = $store->holdJob($taskId)
                    ? 'Job placed on hold. Any assigned worker will relinquish it at its next heartbeat.'
                    : 'Job was not held. Only queued or running jobs can be held.';
            } elseif ($jobAction === 'release') {
                $message = $store->releaseHeldJob($taskId)
                    ? 'Job released back to the queue.'
                    : 'Job was not released. Only held jobs can be released.';
            } elseif ($jobAction === 'move_earlier') {
                $message = $store->moveQueuedJob($taskId, 'earlier')
                    ? 'Job moved sooner in the queue.'
                    : 'Job was not moved. Only queued jobs can be reordered.';
            } elseif ($jobAction === 'move_later') {
                $message = $store->moveQueuedJob($taskId, 'later')
                    ? 'Job moved later in the queue.'
                    : 'Job was not moved. Only queued jobs can be reordered.';
            } else {
                throw new RuntimeException('Unknown job action.');
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $error === null,
                'message' => $message,
                'error' => $error,
            ]);
            exit;
        }
    } elseif ($formAction === 'worker_action') {
        $workerAction = (string) ($_POST['worker_action'] ?? '');
        $pcId = trim((string) ($_POST['pc_id'] ?? ''));
        try {
            if ($pcId === '' || preg_match('/[\x00-\x1F\x7F]/', $pcId) === 1) {
                throw new RuntimeException('Missing or invalid computer id.');
            }

            if ($workerAction === 'remove_stale') {
                $staleAfterSeconds = max(1, (int) ($config['stale_after_seconds'] ?? 900));
                $message = $store->removeWorker($pcId, true, $staleAfterSeconds)
                    ? 'Stale worker check-in removed.'
                    : 'Worker check-in was not removed. It may already be active again.';
            } else {
                throw new RuntimeException('Unknown worker action.');
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($formAction === 'settings') {
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
        ]);
        $store->updateMachines(reflection_parse_machine_list((string) ($_POST['machines'] ?? '')));
        $maintenance = reflection_run_store_maintenance($store, $settings);
        $message = 'Saved options. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
    } elseif ($formAction === 'maintenance') {
        $maintenance = reflection_run_store_maintenance($store, $store->effectiveSettings());
        $message = 'Maintenance complete. Archived ' . $maintenance['archived_jobs'] . ' old completed job(s), trimmed ' . $maintenance['trimmed_events'] . ' event(s), compacted ' . $maintenance['trimmed_file_history'] . ' file-history item(s), and trimmed ' . $maintenance['trimmed_job_archive'] . ' archived job line(s).';
    } elseif ($formAction === 'wake_farm' || $module === 'wake_farm') {
        $wake = reflection_manual_wake_result($store, (int) ($config['stale_after_seconds'] ?? 900));
        $message = $wake['message'];
        $error = $wake['error'];
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
        if ($error === null && !$isControlTask && $transferServerId !== '' && !in_array($transferServerId, $storageServerIds, true)) {
            $error = 'Choose an available storage server.';
        }

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

                $store->createJob($module, $source, $deliveryPath, $overwriteAllowed, $transferExtra);
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
            if ($queued > 0 && !$isControlTask) {
                $notice = reflection_auto_wake_notice($store, (int) ($config['stale_after_seconds'] ?? 900), 'queue_bulk');
                if ($notice !== null) {
                    $message = reflection_append_message($message, $notice);
                }
            }
        }
    } else {
        $source = trim((string) ($_POST['single_source'] ?? ''));
        $error = reflection_validate_task($module, $config)
            ?? ((!$isControlTask && $transferServerId !== '' && !in_array($transferServerId, $storageServerIds, true)) ? 'Choose an available storage server.' : null)
            ?? reflection_path_allowed($source !== '' ? $source : null, !$isControlTask)
            ?? reflection_path_allowed($delivery !== '' ? $delivery : null, false);

        if ($error === null) {
            $job = $store->createJob(
                $module,
                $source !== '' ? $source : null,
                $delivery !== '' ? $delivery : null,
                $overwriteAllowed,
                $transferExtra
            );
            $message = 'Queued ' . $job['task_id'] . ' for ' . $job['module'] . '.';
            if (!$isControlTask) {
                $notice = reflection_auto_wake_notice($store, (int) ($config['stale_after_seconds'] ?? 900), 'queue_single');
                if ($notice !== null) {
                    $message = reflection_append_message($message, $notice);
                }
            }
        }
    }
}

$store->refreshEssSocFromConfiguredEndpoint();
$staleCount = $store->requeueStaleJobs((int) $config['stale_after_seconds']);
$settings = $store->effectiveSettings();
$essSocIgnored = reflection_ess_soc_is_ignored($settings);
$automaticMaintenance = reflection_run_store_maintenance($store, $settings);
$data = $store->read();
$workers = $data['workers'];
$events = $store->readRecentEvents(5);
$fileHistory = array_slice($store->readFileHistory(), 0, 5, true);
$machines = $store->machines();
$allowedActiveWorkers = $store->allowedActiveWorkers();
$wakeTargets = $store->wakeTargetsForCurrentSoc(true, (int) ($config['stale_after_seconds'] ?? 900));
$wakeTargetCount = count($wakeTargets);
$wakeEnabledMachineCount = 0;
foreach ($machines as $machine) {
    if (!empty($machine['wake_enabled']) && trim((string) ($machine['mac'] ?? '')) !== '') {
        $wakeEnabledMachineCount++;
    }
}
$demandWakePlan = $store->demandWakePlan((int) ($config['stale_after_seconds'] ?? 900));
$wakeButtonDisabled = $wakeTargetCount === 0;
$workerLimitDisplay = $essSocIgnored
    ? 'paused'
    : ($allowedActiveWorkers === PHP_INT_MAX ? 'off' : (string) (int) $allowedActiveWorkers);
$workerLimitHelp = $essSocIgnored
    ? 'ESS unavailable'
    : ($allowedActiveWorkers === PHP_INT_MAX ? 'no SOC cap' : 'workers whose SOC margin fits');
$workerStaleAfterSeconds = max(1, (int) ($config['stale_after_seconds'] ?? 900));
$workerCards = reflection_worker_cards($workers, $machines, $workerStaleAfterSeconds);
$workerStateCounts = reflection_count_worker_states($workerCards);
$archiveInfo = $store->archiveInfo();
$validJobFilters = ['all', 'active', 'queued', 'running', 'held', 'success', 'failed', 'stale', 'blocked', 'ignored', 'finished'];
$jobStatus = (string) ($_GET['job_status'] ?? 'all');
if (!in_array($jobStatus, $validJobFilters, true)) {
    $jobStatus = 'all';
}
$jobPageData = $store->jobPage(
    (int) ($_GET['job_page'] ?? 1),
    (int) ($_GET['job_per_page'] ?? 50),
    $jobStatus,
);
$jobs = $jobPageData['jobs'];
$statusCounts = $jobPageData['status_counts'];
$activeJobsPage = $store->jobPage(1, 200, 'active');
$activeJobsAll = $activeJobsPage['jobs'];
$activeJobsPreviewLimit = 5;
$activeJobsPreview = array_slice($activeJobsAll, 0, $activeJobsPreviewLimit);
$activeJobsMore = array_slice($activeJobsAll, $activeJobsPreviewLimit);
$activeJobsShownLimit = count($activeJobsAll);
$completedInStore = (int) ($statusCounts['success'] ?? 0) + (int) ($statusCounts['failed'] ?? 0) + (int) ($statusCounts['stale'] ?? 0) + (int) ($statusCounts['blocked'] ?? 0) + (int) ($statusCounts['ignored'] ?? 0);
$activeCount = (int) ($statusCounts['queued'] ?? 0) + (int) ($statusCounts['running'] ?? 0) + (int) ($statusCounts['held'] ?? 0);
$maintenanceChanged = array_sum($automaticMaintenance) > 0;

// Handle AJAX dashboard refresh
if ((strtolower((string) ($_GET['ajax'] ?? '')) === '1' || strtolower((string) ($_POST['ajax'] ?? '')) === '1')) {
    header('Content-Type: application/json');
    
    // Render overview metrics section
    ob_start();
    ?>
    <article class="metric primary">
        <span>Active jobs</span>
        <strong><?= $activeCount ?></strong>
        <small><?= (int) ($statusCounts['queued'] ?? 0) ?> queued · <?= (int) ($statusCounts['running'] ?? 0) ?> running · <?= (int) ($statusCounts['held'] ?? 0) ?> held</small>
    </article>
    <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
        <span>ESS SOC</span>
        <?php if ($essSocIgnored): ?>
            <strong>ignored</strong>
            <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · last good <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</small>
        <?php else: ?>
            <strong><?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</strong>
            <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · minimum <?= (int) ($settings['ess_min_soc_percent'] ?? 20) ?>%</small>
        <?php endif; ?>
    </article>
    <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
        <span>SOC worker limit</span>
        <strong><?= reflection_h($workerLimitDisplay) ?></strong>
        <small><?= reflection_h($workerLimitHelp) ?> · <?= (int) $wakeTargetCount ?>/<?= (int) $wakeEnabledMachineCount ?> eligible offline WOL</small>
    </article>
    <article class="metric">
        <span>Completed kept</span>
        <strong><?= $completedInStore ?></strong>
        <small><?= (int) $archiveInfo['jobs'] ?> archived · <?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></small>
    </article>
    <article class="metric">
        <span>Workers</span>
        <strong><?= count($workerCards) ?></strong>
        <small><?= (int) ($workerStateCounts['running'] ?? 0) ?> running · <?= (int) ($workerStateCounts['idle'] ?? 0) ?> idle</small>
    </article>
    <?php
    $metricsHtml = ob_get_clean();
    
    // Render workers section
    ob_start();
    ?>
    <div class="computer-grid">
        <?php foreach ($workerCards as $card): ?>
            <article class="computer-card <?= reflection_h($card['state'] ?? 'unknown') ?>">
                <div class="computer-card-head">
                    <strong><?= reflection_h($card['display_name'] ?? '—') ?></strong>
                    <?php if ($card['state'] === 'idle'): ?>
                        <span class="badge idle">Idle <?= reflection_h(reflection_relative_time($card['last_check_in'] ?? null)) ?></span>
                    <?php elseif ($card['state'] === 'running'): ?>
                        <span class="badge running">Running <?= reflection_h(reflection_relative_time($card['last_check_in'] ?? null)) ?></span>
                    <?php else: ?>
                        <span class="badge stale">Stale <?= reflection_h(reflection_relative_time($card['last_check_in'] ?? null)) ?></span>
                    <?php endif; ?>
                </div>
                <dl class="detail-list">
                    <div>
                        <dt>OS</dt>
                        <dd><?= reflection_h($card['os'] ?? '—') ?></dd>
                    </div>
                    <div>
                        <dt>Task version</dt>
                        <dd><?= reflection_h($card['version'] ?? '—') ?></dd>
                    </div>
                    <div>
                        <dt>IPs</dt>
                        <dd><code><?= reflection_h($card['ips'] ?? '—') ?></code></dd>
                    </div>
                    <?php if (!empty($card['network_bcast'])): ?>
                        <div>
                            <dt>Broadcast</dt>
                            <dd><code><?= reflection_h($card['network_bcast']) ?></code></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    $workersHtml = ob_get_clean();
    
    // Render jobs table
    ob_start();
    ?>
    <tbody>
    <?php if ($jobs === []): ?>
        <tr><td colspan="8" class="empty">No jobs match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($jobs as $job): ?>
        <?php $jobStatusValue = (string) ($job['status'] ?? 'unknown'); ?>
        <tr>
            <td><code><?= reflection_h($job['task_id'] ?? '—') ?></code></td>
            <td><?= reflection_h($job['module'] ?? '—') ?></td>
            <td><span class="badge <?= reflection_h(reflection_status_class($jobStatusValue)) ?>"><?= reflection_h($jobStatusValue) ?></span></td>
            <td><?= reflection_h($job['worker'] ?? '—') ?></td>
            <td class="path-cell"><code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '—')) ?></code><br><code title="<?= reflection_h($job['delivery'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['delivery'] ?? '—')) ?></code></td>
            <td>
                <span title="<?= reflection_h($job['created_at'] ?? '') ?>">Created <?= reflection_h(reflection_relative_time($job['created_at'] ?? null)) ?></span><br>
                <span title="<?= reflection_h($job['started_at'] ?? '') ?>">Started <?= reflection_h(reflection_relative_time($job['started_at'] ?? null)) ?></span><br>
                <span title="<?= reflection_h($job['finished_at'] ?? '') ?>">Finished <?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></span>
            </td>
            <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 140)) ?></td>
            <td>
                <div class="button-row table-actions">
                    <?php if ($jobStatusValue === 'queued'): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="move_earlier">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button small-button" type="submit">Sooner</button>
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="move_later">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button small-button" type="submit">Later</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($jobStatusValue, ['queued', 'running'], true)): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="hold">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button small-button" type="submit">Hold</button>
                        </form>
                    <?php elseif ($jobStatusValue === 'held'): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="release">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="ghost-button small-button" type="submit">Release</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($jobStatusValue !== 'running'): ?>
                        <form method="post" style="display: inline;" data-confirm="Delete this job from the live store?">
                            <input type="hidden" name="form_action" value="job_action">
                            <input type="hidden" name="job_action" value="delete">
                            <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                            <button class="danger-button small-button" type="submit">Delete</button>
                        </form>
                    <?php else: ?>
                        <span class="api-note">Hold a running job before deleting it.</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php
    $jobsHtml = ob_get_clean();
    
    // Render events
    ob_start();
    ?>
    <?php if ($events === []): ?>
        <tr><td colspan="5" class="empty">No log entries yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $event): ?>
        <tr>
            <td title="<?= reflection_h($event['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($event['timestamp'] ?? null)) ?></td>
            <td><?= reflection_h($event['event'] ?? '—') ?></td>
            <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
            <td><?= reflection_h($event['worker'] ?? '—') ?></td>
            <td><?= reflection_h(reflection_short_value($event['error'] ?? '', 90)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php
    $eventsHtml = ob_get_clean();
    
    // Render file history
    ob_start();
    ?>
    <?php if ($fileHistory === []): ?>
        <tr><td colspan="3" class="empty">No file or URI history yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($fileHistory as $path => $touches): ?>
        <?php $recentTouches = array_slice(array_reverse($touches), 0, 3); ?>
        <tr>
            <td class="path-cell"><code title="<?= reflection_h($path) ?>"><?= reflection_h(reflection_short_value($path, 80)) ?></code></td>
            <td title="<?= reflection_h($recentTouches[0]['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($recentTouches[0]['timestamp'] ?? null)) ?></td>
            <td>
                <?php foreach ($recentTouches as $touch): ?>
                    <div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> · <code><?= reflection_h($touch['task_id'] ?? '—') ?></code></div>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php
    $filesHtml = ob_get_clean();
    
    echo json_encode([
        'metrics' => $metricsHtml,
        'workers' => $workersHtml,
        'jobs' => $jobsHtml,
        'events' => $eventsHtml,
        'files' => $filesHtml,
        'timestamp' => time(),
    ]);
    exit;
}
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
    <header class="hero">
        <div class="hero-main">
            <p class="eyebrow">Reflection farm master</p>
            <h1><?= reflection_h($config['farm_name'] ?? 'Farm Master') ?></h1>
            <p class="lede">Queue cluster work, watch active machines, and keep the master store small enough to stay quick.</p>
            <div class="hero-pills">
                <span>Farm <code><?= reflection_h($config['farm_id'] ?? 'default') ?></code></span>
            </div>
            <nav class="top-nav">
                <a class="active" href="index.php">Dashboard</a>
                <a href="automation.php">Automation</a>
                <a href="storage_servers.php">Storage servers</a>
                <a href="blocked_jobs.php">Blocked jobs</a>
                <a href="system_checks.php">System checks</a>
                <a href="logs.php">Logs</a>
                <a href="settings.php">Settings</a>
            </nav>
        </div>
        <aside class="version-card active-work-card">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Now</p>
                    <h2>Active work</h2>
                </div>
                <a class="text-link" href="<?= reflection_h(reflection_url_with(['job_status' => 'active', 'job_page' => 1])) ?>">View active</a>
            </div>
            <?php if ($activeJobsPreview === []): ?>
                <p class="empty">No queued or running jobs.</p>
            <?php endif; ?>
            <div class="mini-list active-work-preview-list">
                <?php foreach ($activeJobsPreview as $job): ?>
                    <article class="mini-row">
                        <span class="badge <?= reflection_h(reflection_status_class($job['status'] ?? 'unknown')) ?>"><?= reflection_h($job['status'] ?? 'unknown') ?></span>
                        <div>
                            <strong><code><?= reflection_h($job['task_id'] ?? '—') ?></code> · <?= reflection_h($job['module'] ?? '—') ?></strong>
                            <small><?= reflection_h(reflection_short_value($job['source'] ?? '—', 70)) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($activeJobsMore !== []): ?>
                <details class="active-work-more" id="active-work-more">
                    <summary>Show remaining <?= (int) count($activeJobsMore) ?> active job<?= count($activeJobsMore) === 1 ? '' : 's' ?></summary>
                    <div class="mini-list active-work-expanded-list">
                        <?php foreach ($activeJobsMore as $job): ?>
                            <article class="mini-row">
                                <span class="badge <?= reflection_h(reflection_status_class($job['status'] ?? 'unknown')) ?>"><?= reflection_h($job['status'] ?? 'unknown') ?></span>
                                <div>
                                    <strong><code><?= reflection_h($job['task_id'] ?? '—') ?></code> · <?= reflection_h($job['module'] ?? '—') ?></strong>
                                    <small><?= reflection_h(reflection_short_value($job['source'] ?? '—', 70)) ?></small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($activeCount > $activeJobsShownLimit): ?>
                        <p class="api-note active-work-overflow">Showing the first <?= (int) $activeJobsShownLimit ?> active jobs here. Use <a href="<?= reflection_h(reflection_url_with(['job_status' => 'active', 'job_page' => 1])) ?>">View active</a> for the full table.</p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </aside>
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
    <?php if ($essSocIgnored): ?>
        <div class="alert warning">ESS SOC <?= reflection_h(reflection_ess_status_label($settings)) ?>. SOC-based worker limits are being ignored until the endpoint returns a valid SOC value again. <?= reflection_h($settings['ess_soc_error'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($staleCount > 0): ?>
        <div class="alert warning"><?= reflection_h($staleCount) ?> lost/blocked job(s) were marked for operator review.</div>
    <?php endif; ?>
    <?php if ($maintenanceChanged): ?>
        <div class="alert muted">Automatic maintenance archived <?= (int) $automaticMaintenance['archived_jobs'] ?> old job(s), trimmed <?= (int) $automaticMaintenance['trimmed_events'] ?> event(s), compacted <?= (int) $automaticMaintenance['trimmed_file_history'] ?> file-history item(s), and trimmed <?= (int) $automaticMaintenance['trimmed_job_archive'] ?> archived job line(s).</div>
    <?php endif; ?>

    <section class="overview-grid" id="metrics-section">
        <article class="metric primary">
            <span>Active jobs</span>
            <strong><?= $activeCount ?></strong>
            <small><?= (int) ($statusCounts['queued'] ?? 0) ?> queued · <?= (int) ($statusCounts['running'] ?? 0) ?> running · <?= (int) ($statusCounts['held'] ?? 0) ?> held</small>
        </article>
        <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
            <span>ESS SOC</span>
            <?php if ($essSocIgnored): ?>
                <strong>ignored</strong>
                <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · last good <?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</small>
            <?php else: ?>
                <strong><?= (int) ($settings['ess_soc_percent'] ?? 100) ?>%</strong>
                <small><?= reflection_h(reflection_ess_status_label($settings)) ?> · minimum <?= (int) ($settings['ess_min_soc_percent'] ?? 20) ?>%</small>
            <?php endif; ?>
        </article>
        <article class="metric <?= $essSocIgnored ? 'warning-metric' : '' ?>">
            <span>SOC worker limit</span>
            <strong><?= reflection_h($workerLimitDisplay) ?></strong>
            <small><?= reflection_h($workerLimitHelp) ?> · <?= (int) $wakeTargetCount ?>/<?= (int) $wakeEnabledMachineCount ?> eligible offline WOL</small>
        </article>
        <article class="metric">
            <span>Completed kept</span>
            <strong><?= $completedInStore ?></strong>
            <small><?= (int) $archiveInfo['jobs'] ?> archived · <?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></small>
        </article>
        <article class="metric">
            <span>Workers</span>
            <strong><?= count($workerCards) ?></strong>
            <small><?= (int) ($workerStateCounts['running'] ?? 0) ?> running · <?= (int) ($workerStateCounts['idle'] ?? 0) ?> idle</small>
        </article>
    </section>

    <main class="dashboard-grid">
        <section class="panel queue-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Queue</p>
                    <h2>Create jobs</h2>
                </div>
                <span class="soft-label">Single or bulk</span>
            </div>
            <form method="post" enctype="multipart/form-data" id="job-form">
                <label>
                    Submit mode
                    <select name="form_action" id="submit-mode">
                        <option value="single">Single job</option>
                        <option value="bulk">Bulk import</option>
                    </select>
                    <small>Bulk accepts pasted paths, a JSON array, or a file generated by <code>cluster/tools/reflection-file-list.sh</code>.</small>
                </label>
                <label>
                    Task
                    <select name="module" required>
                        <?php foreach ($config['allowed_tasks'] as $taskName => $description): ?>
                            <option value="<?= reflection_h($taskName) ?>"><?= reflection_h($taskName) ?> — <?= reflection_h($description) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Storage server
                    <select name="transfer_server_id">
                        <option value="">Use first available/default server</option>
                        <?php foreach ($storageServers as $server): ?>
                            <option value="<?= reflection_h($server['id'] ?? '') ?>"><?= reflection_h(reflection_storage_server_label($server)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Choose which FTP/SFTP server plain source/delivery paths belong to. Worker usernames/passwords stay on each worker. <a href="storage_servers.php">Add or edit storage servers</a>.</small>
                </label>
                <div class="mode-fields mode-single">
                    <label>
                        Source path or URI
                        <input name="single_source" placeholder="ftp://farm.local/incoming/source.dat">
                        <small>Use an FTP URL or any path the worker can read. Control tasks can leave this blank.</small>
                    </label>
                    <label>
                        Delivery path or URI
                        <input name="single_delivery" placeholder="ftp://farm.local/outputs/result.txt">
                        <small>Optional. The master passes this value through; workers do the writing.</small>
                    </label>
                </div>
                <div class="mode-fields mode-bulk" hidden>
                    <label>
                        Source list
                        <textarea name="source_list" rows="8" placeholder="ftp://farm.local/incoming/img001.png&#10;ftp://farm.local/incoming/img002.png"></textarea>
                    </label>
                    <label>
                        Upload list file
                        <input type="file" name="source_file" accept=".txt,.list,.json,text/plain,application/json">
                    </label>
                    <label>
                        Delivery template
                        <input name="bulk_delivery" placeholder="ftp://farm.local/outputs/{name}.out">
                        <small>Supports <code>{source}</code>, <code>{basename}</code>, <code>{name}</code>, and <code>{ext}</code>.</small>
                    </label>
                </div>
                <label class="check-row">
                    <input type="checkbox" name="overwrite_allowed" value="1">
                    Allow worker to overwrite existing delivery output
                </label>
                <button type="submit" id="submit-button">Queue job</button>
            </form>
        </section>

        <aside class="side-stack">
            <section class="panel compact-panel power-panel">
                <div class="panel-head power-head">
                    <div>
                        <p class="eyebrow">Power</p>
                        <h2>Wake control</h2>
                    </div>
                    <form method="post" class="bare-form">
                        <input type="hidden" name="form_action" value="wake_farm">
                        <button type="submit" class="secondary-button" <?= $wakeButtonDisabled ? 'disabled' : '' ?>>Wake <?= (int) $wakeTargetCount ?> target<?= $wakeTargetCount === 1 ? '' : 's' ?></button>
                    </form>
                </div>

                <div class="power-summary">
                    <div class="power-summary-item">
                        <span>Wake targets</span>
                        <strong><?= (int) $wakeTargetCount ?> / <?= (int) $wakeEnabledMachineCount ?></strong>
                        <small>eligible offline / configured WOL</small>
                    </div>
                    <div class="power-summary-item">
                        <span>SOC worker limit</span>
                        <strong><?= reflection_h($workerLimitDisplay) ?></strong>
                        <small><?= reflection_h($workerLimitHelp) ?></small>
                    </div>
                    <div class="power-summary-item">
                        <span>Demand wake</span>
                        <strong><?= !empty($demandWakePlan['enabled']) ? (int) ($demandWakePlan['needed'] ?? 0) : 'off' ?></strong>
                        <small><?= (int) ($demandWakePlan['queued_work'] ?? 0) ?> queued · <?= (int) ($demandWakePlan['idle_online_workers'] ?? 0) ?> idle online</small>
                    </div>
                </div>

                <?php if ($wakeEnabledMachineCount === 0): ?>
                    <p class="api-note panel-warning-note">No Wake-on-LAN targets are configured. Add machines in the settings panel to use farm wake control.</p>
                <?php elseif ($essSocIgnored): ?>
                    <p class="api-note panel-warning-note">ESS SOC is <?= reflection_h(reflection_ess_status_label($settings)) ?>. SOC limiting is paused, so every configured wake target is eligible until valid SOC data returns.</p>
                <?php elseif ($wakeTargetCount === 0): ?>
                    <p class="api-note panel-warning-note">No offline wake-enabled machine fits the current SOC margin. Wake control is disabled until SOC rises, the margins are changed, or an eligible machine goes offline.</p>
                <?php elseif ($allowedActiveWorkers === PHP_INT_MAX): ?>
                    <p class="api-note">SOC is not currently capping workers. All configured wake targets are eligible.</p>
                <?php else: ?>
                    <p class="api-note">Current SOC allows <?= (int) $allowedActiveWorkers ?> configured worker<?= $allowedActiveWorkers === 1 ? '' : 's' ?> by margin. <?= (int) $wakeTargetCount ?> of <?= (int) $wakeEnabledMachineCount ?> wake-enabled target<?= $wakeEnabledMachineCount === 1 ? '' : 's' ?> are eligible and offline.</p>
                <?php endif; ?>
                <?php if (empty($demandWakePlan['enabled'])): ?>
                    <p class="api-note">Automatic demand wake is disabled. Jobs can still be queued normally, but machines will only wake when you press the button.</p>
                <?php elseif ((int) ($demandWakePlan['needed'] ?? 0) > 0): ?>
                    <p class="api-note">Demand wake currently wants <?= (int) ($demandWakePlan['needed'] ?? 0) ?> more worker<?= (int) ($demandWakePlan['needed'] ?? 0) === 1 ? '' : 's' ?>. <?= (int) ($demandWakePlan['ready_targets'] ?? 0) ?> eligible target<?= (int) ($demandWakePlan['ready_targets'] ?? 0) === 1 ? '' : 's' ?> are ready after cooldown.</p>
                <?php else: ?>
                    <p class="api-note">Demand wake is satisfied: queued jobs are covered by currently idle online workers or there is no queued work.</p>
                <?php endif; ?>

                <form method="post" class="bare-form maintenance-form">
                    <input type="hidden" name="form_action" value="maintenance">
                    <button type="submit" class="ghost-button">Run maintenance now</button>
                </form>
            </section>

            <section class="panel compact-panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Storage</p>
                        <h2>History limits</h2>
                    </div>
                </div>
                <dl class="detail-list">
                    <div><dt>Completed jobs kept</dt><dd><?= (int) ($settings['job_history_keep_completed'] ?? 500) ?></dd></div>
                    <div><dt>Event lines kept</dt><dd><?= (int) ($settings['event_log_keep_lines'] ?? 1000) ?></dd></div>
                    <div><dt>File-history paths kept</dt><dd><?= (int) ($settings['file_history_keep_paths'] ?? 500) ?></dd></div>
                    <div><dt>Archive file</dt><dd><?= reflection_h(reflection_format_bytes((int) $archiveInfo['size_bytes'])) ?></dd></div>
                </dl>
            </section>
        </aside>
    </main>

    <section class="panel workers-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Cluster</p>
                <h2>Farm computers</h2>
            </div>
            <div class="worker-summary">
                <?php foreach (['running', 'idle', 'stale', 'configured'] as $state): ?>
                    <span><?= reflection_h($state) ?> <strong><?= (int) ($workerStateCounts[$state] ?? 0) ?></strong></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="computer-grid" id="workers-grid">
            <?php if ($workerCards === []): ?>
                <p class="empty">No configured computers or worker check-ins yet.</p>
            <?php endif; ?>
            <?php foreach ($workerCards as $card): ?>
                <article class="computer-card <?= reflection_h(reflection_status_class($card['state'] ?? 'unknown')) ?>">
                    <div class="computer-card-head">
                        <strong><?= reflection_h($card['pc_id'] ?? 'unknown') ?></strong>
                        <span class="badge <?= reflection_h(reflection_status_class($card['state'] ?? 'unknown')) ?>"><?= reflection_h($card['state'] ?? 'unknown') ?></span>
                    </div>
                    <dl>
                        <div><dt>Current job</dt><dd><?= reflection_h($card['current_job'] ?? '—') ?></dd></div>
                        <div><dt>Last check-in</dt><dd title="<?= reflection_h($card['last_check_in'] ?? '') ?>"><?= reflection_h(reflection_relative_time($card['last_check_in'] ?? null)) ?></dd></div>
                        <div><dt>No-job polls</dt><dd><?= (int) ($card['idle_no_job_checkins'] ?? 0) ?></dd></div>
                        <div><dt>Version</dt><dd><code><?= reflection_h($card['version'] ?? '—') ?></code></dd></div>
                        <div><dt>Wake</dt><dd><?= !empty($card['wake_enabled']) ? 'enabled' : 'disabled' ?><?= !empty($card['mac']) ? ' · ' . reflection_h($card['mac']) : '' ?></dd></div>
                        <div><dt>SOC margin</dt><dd><?= (int) ($card['soc_margin_percent'] ?? 0) ?>%</dd></div>
                        <div><dt>Shutdown layer</dt><dd><?= (int) ($card['shutdown_layer'] ?? 0) ?></dd></div>
                    </dl>
                    <?php if (($card['state'] ?? '') === 'stale'): ?>
                        <form method="post" class="button-row computer-actions" data-confirm="Remove this stale worker check-in from the board?">
                            <input type="hidden" name="form_action" value="worker_action">
                            <input type="hidden" name="worker_action" value="remove_stale">
                            <input type="hidden" name="pc_id" value="<?= reflection_h($card['pc_id'] ?? '') ?>">
                            <button type="submit" class="danger-button small-button">Remove stale check-in</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel jobs-panel">
        <div class="panel-head wrap-head">
            <div>
                <p class="eyebrow">Queue store</p>
                <h2>Jobs</h2>
                <p class="api-note">Showing <?= count($jobs) ?> of <?= (int) $jobPageData['total'] ?> job(s). Choose a filter and press Apply filters; the table will not reload while you are just switching filters. Queued jobs can be moved earlier or later in the worker pick-up order.</p>
            </div>
            <form method="get" class="toolbar">
                <label>
                    Status
                    <select name="job_status" id="job-status-select">
                        <?php foreach ($validJobFilters as $filter): ?>
                            <option value="<?= reflection_h($filter) ?>" <?= $jobStatus === $filter ? 'selected' : '' ?>><?= reflection_h($filter) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Per page
                    <select name="job_per_page">
                        <?php foreach ([25, 50, 100, 200] as $size): ?>
                            <option value="<?= $size ?>" <?= (int) $jobPageData['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="secondary-button">Apply filters</button>
            </form>
        </div>
        <nav class="status-tabs" aria-label="Job status filters" data-job-status-tabs>
            <?php foreach (['all', 'active', 'queued', 'running', 'held', 'success', 'failed', 'stale', 'blocked', 'ignored', 'finished'] as $filter): ?>
                <?php
                    if ($filter === 'all') {
                        $tabCount = array_sum($statusCounts);
                    } elseif ($filter === 'active') {
                        $tabCount = $activeCount;
                    } elseif ($filter === 'finished') {
                        $tabCount = $completedInStore;
                    } else {
                        $tabCount = (int) ($statusCounts[$filter] ?? 0);
                    }
                ?>
                <button type="button" class="<?= $jobStatus === $filter ? 'active' : '' ?>" data-job-status-filter="<?= reflection_h($filter) ?>"><?= reflection_h($filter) ?> <span><?= $tabCount ?></span></button>
            <?php endforeach; ?>
        </nav>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="jobs-tbody">
                <?php if ($jobs === []): ?>
                    <tr><td colspan="8" class="empty">No jobs match this filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <?php $jobStatusValue = (string) ($job['status'] ?? 'unknown'); ?>
                    <tr>
                        <td><code><?= reflection_h($job['task_id'] ?? '—') ?></code></td>
                        <td><?= reflection_h($job['module'] ?? '—') ?></td>
                        <td><span class="badge <?= reflection_h(reflection_status_class($jobStatusValue)) ?>"><?= reflection_h($jobStatusValue) ?></span></td>
                        <td><?= reflection_h($job['worker'] ?? '—') ?></td>
                        <td class="path-cell"><code title="<?= reflection_h($job['source'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['source'] ?? '—')) ?></code><br><code title="<?= reflection_h($job['delivery'] ?? '') ?>"><?= reflection_h(reflection_short_value($job['delivery'] ?? '—')) ?></code></td>
                        <td>
                            <span title="<?= reflection_h($job['created_at'] ?? '') ?>">Created <?= reflection_h(reflection_relative_time($job['created_at'] ?? null)) ?></span><br>
                            <span title="<?= reflection_h($job['started_at'] ?? '') ?>">Started <?= reflection_h(reflection_relative_time($job['started_at'] ?? null)) ?></span><br>
                            <span title="<?= reflection_h($job['finished_at'] ?? '') ?>">Finished <?= reflection_h(reflection_relative_time($job['finished_at'] ?? null)) ?></span>
                        </td>
                        <td><?= reflection_h(reflection_short_value($job['error'] ?? '', 140)) ?></td>
                        <td>
                            <div class="button-row table-actions">
                                <?php if ($jobStatusValue === 'queued'): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="move_earlier">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button small-button" type="submit">Sooner</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="move_later">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button small-button" type="submit">Later</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($jobStatusValue, ['queued', 'running'], true)): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="hold">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button small-button" type="submit">Hold</button>
                                    </form>
                                <?php elseif ($jobStatusValue === 'held'): ?>
                                    <form method="post">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="release">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="ghost-button small-button" type="submit">Release</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($jobStatusValue !== 'running'): ?>
                                    <form method="post" data-confirm="Delete this job from the live store?">
                                        <input type="hidden" name="form_action" value="job_action">
                                        <input type="hidden" name="job_action" value="delete">
                                        <input type="hidden" name="task_id" value="<?= reflection_h($job['task_id'] ?? '') ?>">
                                        <button class="danger-button small-button" type="submit">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="api-note">Hold a running job before deleting it.</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <a class="<?= (int) $jobPageData['page'] <= 1 ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => max(1, (int) $jobPageData['page'] - 1)])) ?>">Previous</a>
            <span>Page <?= (int) $jobPageData['page'] ?> of <?= (int) $jobPageData['pages'] ?></span>
            <a class="<?= (int) $jobPageData['page'] >= (int) $jobPageData['pages'] ? 'disabled' : '' ?>" href="<?= reflection_h(reflection_url_with(['job_page' => min((int) $jobPageData['pages'], (int) $jobPageData['page'] + 1)])) ?>">Next</a>
        </div>
    </section>

    <section class="two-column">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Log</p>
                    <h2>Recent events</h2>
                </div>
                <a class="ghost-button small-button" href="logs.php?log=events">View all logs</a>
            </div>
            <div class="table-wrap compact-table">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Job</th>
                            <th>Worker</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody id="events-tbody">
                    <?php if ($events === []): ?>
                        <tr><td colspan="5" class="empty">No log entries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td title="<?= reflection_h($event['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($event['timestamp'] ?? null)) ?></td>
                            <td><?= reflection_h($event['event'] ?? '—') ?></td>
                            <td><code><?= reflection_h($event['task_id'] ?? '—') ?></code></td>
                            <td><?= reflection_h($event['worker'] ?? '—') ?></td>
                            <td><?= reflection_h(reflection_short_value($event['error'] ?? '', 90)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Files</p>
                    <h2>Recent paths / URIs</h2>
                </div>
                <a class="ghost-button small-button" href="logs.php?log=files">View all logs</a>
            </div>
            <div class="table-wrap compact-table">
                <table>
                    <thead>
                        <tr>
                            <th>Path or URI</th>
                            <th>Last touched</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="files-tbody">
                    <?php if ($fileHistory === []): ?>
                        <tr><td colspan="3" class="empty">No file or URI history yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($fileHistory as $path => $touches): ?>
                        <?php $recentTouches = array_slice(array_reverse($touches), 0, 3); ?>
                        <tr>
                            <td class="path-cell"><code title="<?= reflection_h($path) ?>"><?= reflection_h(reflection_short_value($path, 80)) ?></code></td>
                            <td title="<?= reflection_h($recentTouches[0]['timestamp'] ?? '') ?>"><?= reflection_h(reflection_relative_time($recentTouches[0]['timestamp'] ?? null)) ?></td>
                            <td>
                                <?php foreach ($recentTouches as $touch): ?>
                                    <div><strong><?= reflection_h($touch['action'] ?? '—') ?></strong> · <code><?= reflection_h($touch['task_id'] ?? '—') ?></code></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>


    <footer>
        <p>Protect this dashboard with your web server, VPN, or reverse-proxy auth.</p>
        <?php if ($gitCommit): ?>
            <p style="margin: 0; font-size: 0.85rem; opacity: 0.6;"><code><?= reflection_h($gitCommit) ?></code></p>
        <?php endif; ?>
    </footer>

    <script src="common.js"></script>
    <script src="dashboard.js"></script>
</body>
</html>
