<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/StorageStore.php';

function reflection_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
function reflection_api_master_commit(array $config): string
{
    $commit = trim((string) ($config['required_version'] ?? ''));
    if ($commit === '' || strtolower($commit) === 'unknown') {
        return '';
    }

    return $commit;
}

function reflection_api_versions_match(string $workerVersion, string $masterCommit): bool
{
    $workerVersion = trim($workerVersion);
    $masterCommit = trim($masterCommit);
    if ($workerVersion === '' || $masterCommit === '') {
        return false;
    }

    if ($workerVersion === $masterCommit) {
        return true;
    }

    $shortest = min(strlen($workerVersion), strlen($masterCommit));
    if ($shortest >= 7) {
        return substr($workerVersion, 0, $shortest) === substr($masterCommit, 0, $shortest);
    }

    return false;
}

function reflection_api_with_version_metadata(array $response, array $config, array $settings, string $policy = 'ignore', string $reason = ''): array
{
    $masterCommit = reflection_api_master_commit($config);
    if ($masterCommit !== '') {
        $response['master_commit'] = $masterCommit;
        // required_version is kept for older dashboard/tests/worker code.
        $response['required_version'] = $masterCommit;
    }

    $response['version_enforced'] = !empty($settings['enforce_version']) && $masterCommit !== '';
    $response['version_policy'] = $policy;
    if ($reason !== '') {
        $response['version_reason'] = $reason;
    }

    return $response;
}

function reflection_handle_farm_api(array $payload, FarmStore $store, array $config): array
{
    $action = (string) ($payload['action'] ?? '');
    $pcId = trim((string) ($payload['pc_id'] ?? ''));
    $version = trim((string) ($payload['version'] ?? ''));

    if ($action === '' || $pcId === '') {
        return ['status' => 'error', 'error' => 'Missing action or pc_id.'];
    }

    $capabilities = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
    $store->recordWorkerCheckIn($pcId, $version, $capabilities);

    $settings = $store->effectiveSettings();
    $masterCommit = reflection_api_master_commit($config);
    $versionMismatch = !empty($settings['enforce_version'])
        && $masterCommit !== ''
        && !reflection_api_versions_match($version, $masterCommit);

    if ($action === 'system_check') {
        return reflection_api_with_version_metadata(
            [
                'status' => 'ready',
                'server_time' => gmdate(DATE_ATOM),
                'job_lease_seconds' => max(30, (int) ($settings['job_lease_seconds'] ?? 180)),
            ],
            $config,
            $settings,
            'ok'
        );
    }

    if ($action === 'confirm_shutdown') {
        return reflection_api_confirm_shutdown($payload, $store, $config, $pcId);
    }

    if ($action === 'register_quarantine') {
        return reflection_api_register_quarantine($payload, $store, $config, $pcId);
    }

    if ($versionMismatch && $action === 'request_task') {
        return reflection_api_request_self_update_for_version_mismatch($store, $config, $pcId, $masterCommit);
    }

    if ($versionMismatch && !reflection_api_allows_mismatched_version_action($payload, $store)) {
        return reflection_api_with_version_metadata(
            ['status' => 'version_mismatch', 'update_available' => true, 'update_allowed' => true],
            $config,
            $settings,
            'update_now',
            'worker_commit_mismatch'
        );
    }

    switch ($action) {
        case 'request_task':
            return reflection_api_request_task($store, $config, $pcId);
        case 'confirm_taken':
            return reflection_api_confirm_taken($payload, $store, $config, $pcId);
        case 'heartbeat_task':
            return reflection_api_heartbeat_task($payload, $store, $config, $pcId);
        case 'task_stage':
            return reflection_api_task_stage($payload, $store, $pcId);
        case 'report_done':
            return reflection_api_report_done($payload, $store, $config, $pcId);
        default:
            return reflection_api_with_version_metadata(
                ['status' => 'error', 'error' => 'Unknown action.'],
                $config,
                $settings,
                'ignore'
            );
    }
}

function reflection_worker_transfer_auth(array $config): ?array
{
    $auth = reflection_transfer_auth_config(array_replace_recursive(
        reflection_default_farm_settings(),
        $config,
    ));
    $username = (string) ($auth['username'] ?? '');
    $password = (string) ($auth['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    $scheme = strtolower((string) ($auth['scheme'] ?? 'ftp'));
    if (!in_array($scheme, ['ftp', 'ftps'], true)) {
        $scheme = 'ftp';
    }

    return [
        'scheme' => $scheme,
        'host' => (string) ($auth['host'] ?? ''),
        'port' => (int) ($auth['port'] ?? ($scheme === 'ftps' ? 990 : 21)),
        'username' => $username,
        'password' => $password,
    ];
}


function reflection_clean_transfer_server_payload(?array $server): ?array
{
    if (!is_array($server)) {
        return null;
    }

    $host = trim((string) ($server['host'] ?? ''));
    if ($host === '') {
        return null;
    }

    $scheme = strtolower((string) ($server['scheme'] ?? 'ftp'));
    if (!in_array($scheme, ['ftp', 'ftps', 'sftp'], true)) {
        $scheme = 'ftp';
    }

    return [
        'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($server['id'] ?? '')) ?: '',
        'scheme' => $scheme,
        'host' => $host,
        'port' => (int) ($server['port'] ?? ($scheme === 'sftp' ? 22 : ($scheme === 'ftps' ? 990 : 21))),
        'root' => (string) ($server['root'] ?? ''),
    ];
}

function reflection_worker_transfer_server(array $config): ?array
{
    $server = is_array($config['transfer_server'] ?? null)
        ? $config['transfer_server']
        : reflection_transfer_server_config(array_replace_recursive(
            reflection_default_farm_settings(),
            $config,
        ));

    return reflection_clean_transfer_server_payload($server);
}

function reflection_worker_transfer_server_for_job(array $job, array $config): ?array
{
    if (is_array($job['transfer_server'] ?? null)) {
        return reflection_clean_transfer_server_payload($job['transfer_server']);
    }

    $serverId = trim((string) ($job['transfer_server_id'] ?? ''));
    $storagePath = (string) ($config['storage_path'] ?? '');
    if ($storagePath !== '') {
        try {
            $storageStore = new StorageStore(dirname($storagePath), reflection_worker_transfer_server($config));
            $server = $storageStore->workerServerPayload($serverId !== '' ? $serverId : null);
            if ($server !== null) {
                return $server;
            }
        } catch (Throwable $exception) {
            // Fall through to the legacy/default transfer server.
        }
    }

    return reflection_worker_transfer_server($config);
}

function reflection_is_control_task(string $module): bool
{
    return in_array($module, ['noop', 'status', 'reload_tasks', 'shutdown', 'update_worker', 'wake_farm', 'storage_test', 'purge_quarantine'], true);
}

function reflection_api_allows_mismatched_version_action(array $payload, FarmStore $store): bool
{
    $action = (string) ($payload['action'] ?? '');
    if (!in_array($action, ['confirm_taken', 'heartbeat_task', 'task_stage', 'report_done'], true)) {
        return false;
    }

    $taskId = trim((string) ($payload['task_id'] ?? ''));
    if ($taskId === '') {
        return false;
    }

    return $store->jobModule($taskId) === 'update_worker';
}

function reflection_api_confirm_shutdown(array $payload, FarmStore $store, array $config, string $pcId): array
{
    $settings = $store->effectiveSettings();
    $reason = trim((string) ($payload['reason'] ?? 'shutdown_confirmed'));
    if ($reason === '') {
        $reason = 'shutdown_confirmed';
    }

    $store->markWorkerExpectedOffline($pcId, $reason);

    return reflection_api_with_version_metadata(
        [
            'status' => 'shutdown_confirmed',
            'reason' => $reason,
        ],
        $config,
        $settings,
        'ok'
    );
}

function reflection_api_register_quarantine(array $payload, FarmStore $store, array $config, string $pcId): array
{
    $settings = $store->effectiveSettings();
    $location = $payload['quarantine'] ?? null;
    if (!is_array($location)) {
        return reflection_api_with_version_metadata(
            ['status' => 'error', 'error' => 'Missing quarantine location.'],
            $config,
            $settings,
            'ignore'
        );
    }

    $record = $store->recordQuarantineLocation($pcId, $location);
    if ($record === null) {
        return reflection_api_with_version_metadata(
            ['status' => 'error', 'error' => 'Invalid quarantine location.'],
            $config,
            $settings,
            'ignore'
        );
    }

    return reflection_api_with_version_metadata(
        [
            'status' => 'quarantine_registered',
            'quarantine_id' => (string) ($record['id'] ?? ''),
        ],
        $config,
        $settings,
        'ok'
    );
}

function reflection_api_shutdown_layer_payload(FarmStore $store, string $pcId, array $config): array
{
    $staleAfterSeconds = (int) ($config['stale_after_seconds'] ?? 900);
    return $store->shutdownLayerStatus($pcId, $staleAfterSeconds);
}

function reflection_api_shutdown_allowed(FarmStore $store, string $pcId, array $config): bool
{
    $layer = reflection_api_shutdown_layer_payload($store, $pcId, $config);
    return !empty($layer['allowed']);
}

function reflection_api_task_payload(array $job, array $config, array $settings, int $allowedWorkers, ?FarmStore $store = null, string $pcId = ''): array
{
    $shutdownLayer = $store !== null && $pcId !== '' ? reflection_api_shutdown_layer_payload($store, $pcId, $config) : ['allowed' => true];

    $task = [
        'task_id' => $job['task_id'],
        'module' => $job['module'],
        'source' => $job['source'],
        'delivery' => $job['delivery'],
        'overwrite_allowed' => (bool) $job['overwrite_allowed'],
        'shutdown_debug_mode' => !empty($settings['shutdown_debug_mode']),
        'shutdown_layer' => $shutdownLayer,
        'quarantine_keep_days' => max(1, (int) ($settings['quarantine_keep_days'] ?? 14)),
        'quarantine_max_gb' => max(0.0, (float) ($settings['quarantine_max_gb'] ?? 100)),
        'worker_temp_max_age_hours' => max(1, (int) ($settings['worker_temp_max_age_hours'] ?? 24)),
        'lease_token' => (string) ($job['lease_token'] ?? ''),
        'lease_expires_at' => (string) ($job['lease_expires_at'] ?? ''),
        'job_lease_seconds' => max(30, (int) ($settings['job_lease_seconds'] ?? 180)),
    ];

    $transferServer = reflection_worker_transfer_server_for_job($job, $config);
    if ($transferServer !== null && (!reflection_is_control_task((string) $job['module']) || in_array((string) $job['module'], ['storage_test', 'purge_quarantine'], true))) {
        $task['transfer_server'] = $transferServer;
        $task['path_mode'] = reflection_is_control_task((string) $job['module']) ? 'control' : 'transfer';
    }

    if (!empty($config['send_transfer_credentials'])) {
        $transferAuth = reflection_worker_transfer_auth($config);
        if ($transferAuth !== null) {
            $task['transfer_auth'] = $transferAuth;
        }
    }

    if (is_array($job['worker_command_filter'] ?? null)) {
        $task['worker_command_filter'] = $job['worker_command_filter'];
    }

    return $task;
}

function reflection_api_request_self_update_for_version_mismatch(FarmStore $store, array $config, string $pcId, string $masterCommit): array
{
    $settings = $store->effectiveSettings();
    $store->resetWorkerNoJobCheckIns($pcId);

    return reflection_api_with_version_metadata(
        [
            'status' => 'version_mismatch',
            'update_allowed' => true,
            'update_available' => true,
            'target_version' => $masterCommit,
        ],
        $config,
        $settings,
        'update_now',
        'worker_commit_mismatch'
    );
}

function reflection_api_request_task(FarmStore $store, array $config, string $pcId): array
{
    $staleAfterSeconds = (int) ($config['stale_after_seconds'] ?? 900);
    $settings = $store->effectiveSettings();
    $leaseSeconds = max(30, min(3600, (int) ($settings['job_lease_seconds'] ?? 180)));
    $allowedWorkers = $store->allowedActiveWorkers();

    // Re-deliver an unconfirmed claim before applying new-work admission
    // policy. This covers a lost request_task response without allocating a
    // second job. A confirmed lease remains exclusive until completion or
    // expiry; asking for more work never abandons it.
    $existingClaim = $store->claimNextQueuedJobForWorker(
        $pcId,
        $staleAfterSeconds,
        $leaseSeconds,
        false,
        $allowedWorkers
    );
    if (is_array($existingClaim['job'] ?? null)) {
        $store->resetWorkerNoJobCheckIns($pcId);
        return reflection_api_with_version_metadata(
            [
                'status' => 'task_available',
                'claim_replayed' => true,
                'task' => reflection_api_task_payload($existingClaim['job'], $config, $settings, $allowedWorkers, $store, $pcId),
            ],
            $config,
            $settings,
            'ok'
        );
    }
    if (!empty($existingClaim['busy'])) {
        return reflection_api_with_version_metadata(
            [
                'status' => 'no_jobs',
                'reason' => 'worker_already_has_active_lease',
                'shutdown_after_task' => false,
                'idle_no_job_checkins' => 0,
                'idle_shutdown_after_no_job_checks' => max(0, (int) ($settings['idle_shutdown_after_no_job_checks'] ?? 0)),
            ],
            $config,
            $settings,
            'ok'
        );
    }

    $masterCommit = reflection_api_master_commit($config);
    if (!$store->workerFitsCurrentSoc($pcId)) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'ess_soc_below_worker_minimum', !empty($settings['ess_shutdown_below_minimum']), $config);
    }

    if ($allowedWorkers <= 0) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'ess_soc_below_minimum', !empty($settings['ess_shutdown_below_minimum']), $config);
    }

    $layerAdmission = $store->normalWorkLayerAdmissionStatus(
        $pcId,
        $staleAfterSeconds,
        $masterCommit,
        !empty($settings['enforce_version']) && $masterCommit !== ''
    );
    if (empty($layerAdmission['allowed'])) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'lower_shutdown_layer_idle', false, $config, [
            'work_layer_priority' => $layerAdmission,
        ]);
    }

    $claim = $store->claimNextQueuedJobForWorker(
        $pcId,
        $staleAfterSeconds,
        $leaseSeconds,
        true,
        $allowedWorkers
    );
    $job = is_array($claim['job'] ?? null) ? $claim['job'] : null;
    if ($job === null) {
        $reason = !empty($claim['capacity_limited'])
            ? 'ess_worker_limit'
            : ((is_array($claim['rejections'] ?? null) && $claim['rejections'] !== []) ? 'no_eligible_jobs' : 'queue_empty');
        return reflection_api_no_jobs_response($store, $pcId, $settings, $reason, false, $config, [
            'work_layer_priority' => $layerAdmission,
            'assignment_rejections' => is_array($claim['rejections'] ?? null) ? $claim['rejections'] : [],
        ]);
    }

    $store->resetWorkerNoJobCheckIns($pcId);

    return reflection_api_with_version_metadata(
        [
            'status' => 'task_available',
            'claim_replayed' => !empty($claim['replayed']),
            'task' => reflection_api_task_payload($job, $config, $settings, $allowedWorkers, $store, $pcId),
        ],
        $config,
        $settings,
        'ok'
    );
}


function reflection_api_no_jobs_response(FarmStore $store, string $pcId, array $settings, string $reason, bool $forceShutdown = false, array $config = [], array $extra = []): array
{
    $shutdownLimit = max(0, (int) ($settings['idle_shutdown_after_no_job_checks'] ?? 0));
    $idleCheckIns = $store->recordWorkerNoJobCheckIn($pcId, $shutdownLimit);
    $limitReached = $shutdownLimit > 0 && $idleCheckIns >= $shutdownLimit;

    $requestedShutdown = $forceShutdown || $limitReached;
    $shutdownLayer = $config !== [] ? reflection_api_shutdown_layer_payload($store, $pcId, $config) : ['allowed' => true];
    $shutdownAfterTask = $requestedShutdown && !empty($shutdownLayer['allowed']);
    $finalReason = $limitReached ? 'idle_no_job_check_limit' : $reason;
    if ($requestedShutdown && !$shutdownAfterTask) {
        $finalReason = 'shutdown_layer_waiting';
    }

    $response = [
        'status' => 'no_jobs',
        'shutdown_after_task' => $shutdownAfterTask,
        'reason' => $finalReason,
        'idle_no_job_checkins' => $idleCheckIns,
        'idle_shutdown_after_no_job_checks' => $shutdownLimit,
        'shutdown_debug_mode' => !empty($settings['shutdown_debug_mode']),
        'shutdown_layer' => $shutdownLayer,
    ];
    foreach ($extra as $key => $value) {
        if (is_string($key) && preg_match('/^[a-zA-Z0-9_]+$/', $key) === 1) {
            $response[$key] = $value;
        }
    }

    return reflection_api_with_version_metadata(
        $response,
        $config,
        $settings,
        'ok'
    );
}

function reflection_api_confirm_taken(array $payload, FarmStore $store, array $config, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));
    if ($taskId === '') {
        return ['status' => 'error', 'error' => 'Missing task_id.'];
    }

    $settings = $store->effectiveSettings();
    $leaseSeconds = max(30, min(3600, (int) ($settings['job_lease_seconds'] ?? 180)));
    if (!$store->markJobRunning($taskId, $pcId, $leaseToken, $leaseSeconds)) {
        return ['status' => 'not_available'];
    }

    return reflection_api_with_version_metadata(
        ['status' => 'acknowledged', 'job_lease_seconds' => $leaseSeconds],
        $config,
        $settings,
        'ok'
    );
}


function reflection_api_heartbeat_task(array $payload, FarmStore $store, array $config, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));
    if ($taskId === '') {
        return ['status' => 'error', 'error' => 'Missing task_id.'];
    }

    $settings = $store->effectiveSettings();
    $leaseSeconds = max(30, min(3600, (int) ($settings['job_lease_seconds'] ?? 180)));
    if ($store->heartbeatJob($taskId, $pcId, $leaseToken, $leaseSeconds)) {
        return reflection_api_with_version_metadata(
            ['status' => 'heartbeat_acknowledged', 'job_lease_seconds' => $leaseSeconds],
            $config,
            $settings,
            'ok'
        );
    }

    if ($store->heldJobBelongsToWorker($taskId, $pcId)) {
        return ['status' => 'task_held', 'instruction' => 'relinquish_task'];
    }

    return ['status' => 'not_available', 'instruction' => 'relinquish_task'];
}

function reflection_api_task_stage(array $payload, FarmStore $store, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $stage = trim((string) ($payload['stage'] ?? ''));
    $message = trim((string) ($payload['message'] ?? ''));
    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));

    if ($taskId === '' || $stage === '') {
        return ['status' => 'error', 'error' => 'Missing task_id or stage.'];
    }

    if (!$store->updateJobStage($taskId, $pcId, $stage, $message, $leaseToken)) {
        return ['status' => 'not_available', 'instruction' => 'relinquish_task'];
    }

    return ['status' => 'stage_acknowledged'];
}


function reflection_api_report_done(array $payload, FarmStore $store, array $config, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $status = (string) ($payload['status'] ?? 'failed');
    $error = (string) ($payload['error'] ?? '');
    $leaseToken = trim((string) ($payload['lease_token'] ?? ''));
    $completionId = trim((string) ($payload['completion_id'] ?? ''));

    if ($taskId === '') {
        return ['status' => 'error', 'error' => 'Missing task_id.'];
    }

    if (!in_array($status, ['success', 'failed', 'skipped'], true)) {
        return ['status' => 'error', 'error' => 'Invalid completion status.'];
    }

    if (!$store->finishJob($taskId, $pcId, $status, $error, $leaseToken, $completionId)) {
        return ['status' => 'not_available'];
    }

    $settings = $store->effectiveSettings();
    $shutdownRequested = !empty($settings['ess_shutdown_below_minimum']) && !$store->workerFitsCurrentSoc($pcId);
    $shutdownLayer = reflection_api_shutdown_layer_payload($store, $pcId, $config);
    $shutdownAfterTask = $shutdownRequested && !empty($shutdownLayer['allowed']);

    return reflection_api_with_version_metadata(
        [
            'status' => 'confirmed_by_server',
            'shutdown_after_task' => $shutdownAfterTask,
            'shutdown_debug_mode' => !empty($settings['shutdown_debug_mode']),
            'shutdown_layer' => $shutdownLayer,
            'shutdown_blocked_by_layer' => $shutdownRequested && !$shutdownAfterTask,
        ],
        $config,
        $settings,
        'ok'
    );
}

if (!defined('REFLECTION_TESTING') && !defined('REFLECTION_EMBEDDED_API')) {
    $config = reflection_master_config();
    $store = reflection_farm_store($config);
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        reflection_json_response(['status' => 'error', 'error' => 'Request body must be JSON.'], 400);
        return;
    }

    reflection_json_response(reflection_handle_farm_api($payload, $store, $config));
}
