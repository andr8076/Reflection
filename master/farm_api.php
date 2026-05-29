<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';

function reflection_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function reflection_handle_farm_api(array $payload, FarmStore $store, array $config): array
{
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
            return reflection_api_request_task($store);
        case 'confirm_taken':
            return reflection_api_confirm_taken($payload, $store, $pcId);
        case 'report_done':
            return reflection_api_report_done($payload, $store, $pcId);
        default:
            return ['status' => 'error', 'error' => 'Unknown action.'];
    }
}

function reflection_api_request_task(FarmStore $store): array
{
    $allowedWorkers = $store->allowedActiveWorkers();
    $settings = $store->effectiveSettings();
    if ($allowedWorkers <= 0) {
        return [
            'status' => 'no_jobs',
            'shutdown_after_task' => !empty($settings['ess_shutdown_below_minimum']),
            'reason' => 'ess_soc_below_minimum',
        ];
    }

    if ($allowedWorkers !== PHP_INT_MAX && $store->runningWorkerCount() >= $allowedWorkers) {
        return ['status' => 'no_jobs', 'reason' => 'ess_worker_limit'];
    }

    $job = $store->nextQueuedJob();
    if ($job === null) {
        return ['status' => 'no_jobs'];
    }

    return [
        'status' => 'task_available',
        'task' => [
            'task_id' => $job['task_id'],
            'module' => $job['module'],
            'source' => $job['source'],
            'delivery' => $job['delivery'],
            'overwrite_allowed' => (bool) $job['overwrite_allowed'],
            'shutdown_after_task' => !empty($settings['ess_shutdown_below_minimum']) && $allowedWorkers <= 1,
        ],
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

if (!defined('REFLECTION_TESTING')) {
    $config = reflection_master_config();
    $store = new FarmStore($config['storage_path']);
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        reflection_json_response(['status' => 'error', 'error' => 'Request body must be JSON.'], 400);
        return;
    }

    reflection_json_response(reflection_handle_farm_api($payload, $store, $config));
}
