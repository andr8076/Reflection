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

function reflection_request_api_token(array $payload): string
{
    $headerToken = (string) ($_SERVER['HTTP_X_REFLECTION_API_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return $headerToken;
    }

    return (string) ($payload['api_token'] ?? '');
}

function reflection_api_token_is_valid(array $payload, array $config): bool
{
    $requiredToken = (string) ($config['api_token'] ?? '');
    if ($requiredToken === '') {
        return true;
    }

    return hash_equals($requiredToken, reflection_request_api_token($payload));
}

function reflection_handle_farm_api(array $payload, FarmStore $store, array $config): array
{
    if (!reflection_api_token_is_valid($payload, $config)) {
        return ['status' => 'unauthorized', 'error' => 'Invalid or missing API token.'];
    }

    $action = (string) ($payload['action'] ?? '');
    $pcId = trim((string) ($payload['pc_id'] ?? ''));
    $version = trim((string) ($payload['version'] ?? ''));

    if ($action === '' || $pcId === '') {
        return ['status' => 'error', 'error' => 'Missing action or pc_id.'];
    }

    $store->recordWorkerCheckIn($pcId, $version);
    $store->refreshEssSocFromConfiguredEndpoint();

    $settings = $store->effectiveSettings();
    $requiredVersion = $config['required_version'] ?? null;
    if (!empty($settings['enforce_version']) && is_string($requiredVersion) && $requiredVersion !== '' && $version !== $requiredVersion) {
        return ['status' => 'version_mismatch', 'required_version' => $requiredVersion];
    }

    switch ($action) {
        case 'request_task':
            return reflection_api_request_task($store, $config, $pcId);
        case 'confirm_taken':
            return reflection_api_confirm_taken($payload, $store, $pcId);
        case 'report_done':
            return reflection_api_report_done($payload, $store, $pcId);
        default:
            return ['status' => 'error', 'error' => 'Unknown action.'];
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
    return in_array($module, ['noop', 'status', 'reload_tasks', 'shutdown', 'wake_farm'], true);
}

function reflection_api_request_task(FarmStore $store, array $config, string $pcId): array
{
    $allowedWorkers = $store->allowedActiveWorkers();
    $settings = $store->effectiveSettings();
    if ($allowedWorkers <= 0) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'ess_soc_below_minimum', !empty($settings['ess_shutdown_below_minimum']));
    }

    if ($allowedWorkers !== PHP_INT_MAX && $store->runningWorkerCount() >= $allowedWorkers) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'ess_worker_limit');
    }

    $job = $store->nextQueuedJob();
    if ($job === null) {
        return reflection_api_no_jobs_response($store, $pcId, $settings, 'queue_empty');
    }

    $store->resetWorkerNoJobCheckIns($pcId);

    $task = [
        'task_id' => $job['task_id'],
        'module' => $job['module'],
        'source' => $job['source'],
        'delivery' => $job['delivery'],
        'overwrite_allowed' => (bool) $job['overwrite_allowed'],
        'shutdown_after_task' => !empty($settings['ess_shutdown_below_minimum']) && $allowedWorkers <= 1,
    ];

    $transferServer = reflection_worker_transfer_server_for_job($job, $config);
    if ($transferServer !== null && !reflection_is_control_task((string) $job['module'])) {
        $task['transfer_server'] = $transferServer;
        $task['path_mode'] = 'transfer';
    }

    if (!empty($config['send_transfer_credentials'])) {
        $transferAuth = reflection_worker_transfer_auth($config);
        if ($transferAuth !== null) {
            $task['transfer_auth'] = $transferAuth;
        }
    }

    return [
        'status' => 'task_available',
        'task' => $task,
    ];
}


function reflection_api_no_jobs_response(FarmStore $store, string $pcId, array $settings, string $reason, bool $forceShutdown = false): array
{
    $idleCheckIns = $store->recordWorkerNoJobCheckIn($pcId);
    $shutdownLimit = max(0, (int) ($settings['idle_shutdown_after_no_job_checks'] ?? 0));
    $limitReached = $shutdownLimit > 0 && $idleCheckIns >= $shutdownLimit;

    return [
        'status' => 'no_jobs',
        'shutdown_after_task' => $forceShutdown || $limitReached,
        'reason' => $limitReached ? 'idle_no_job_check_limit' : $reason,
        'idle_no_job_checkins' => $idleCheckIns,
        'idle_shutdown_after_no_job_checks' => $shutdownLimit,
    ];
}

function reflection_api_confirm_taken(array $payload, FarmStore $store, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    if ($taskId === '') {
        return ['status' => 'error', 'error' => 'Missing task_id.'];
    }

    if (!$store->markJobRunning($taskId, $pcId)) {
        return ['status' => 'not_available'];
    }

    return ['status' => 'acknowledged'];
}

function reflection_api_report_done(array $payload, FarmStore $store, string $pcId): array
{
    $taskId = trim((string) ($payload['task_id'] ?? ''));
    $status = (string) ($payload['status'] ?? 'failed');
    $error = (string) ($payload['error'] ?? '');

    if ($taskId === '') {
        return ['status' => 'error', 'error' => 'Missing task_id.'];
    }

    if (!in_array($status, ['success', 'failed'], true)) {
        return ['status' => 'error', 'error' => 'Invalid completion status.'];
    }

    if (!$store->finishJob($taskId, $pcId, $status, $error)) {
        return ['status' => 'not_available'];
    }

    $settings = $store->effectiveSettings();
    $allowedWorkers = $store->allowedActiveWorkers();
    return [
        'status' => 'confirmed_by_server',
        'shutdown_after_task' => !empty($settings['ess_shutdown_below_minimum']) && $allowedWorkers <= 0,
    ];
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
