<?php

declare(strict_types=1);

define('REFLECTION_TESTING', true);

require_once __DIR__ . '/../master/farm_api.php';

$storePath = sys_get_temp_dir() . '/reflection_farm_store_' . bin2hex(random_bytes(6)) . '.json';
$eventLogPath = dirname($storePath) . DIRECTORY_SEPARATOR . 'farm_events.log';
$fileHistoryPath = dirname($storePath) . DIRECTORY_SEPARATOR . 'farm_file_history.json';
@unlink($eventLogPath);
@unlink($fileHistoryPath);
$store = new FarmStore($storePath);
$config = [
    'required_version' => 'test-version',
];

putenv('REFLECTION_MASTER_STORE');
$defaultConfig = reflection_master_config();
assertSameValue(
    realpath(__DIR__ . '/../master') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json',
    $defaultConfig['storage_path'],
    'Default farm store should live beside the deployed farm master files.'
);
assertSameValue(
    true,
    is_dir(dirname($defaultConfig['storage_path'])),
    'Default farm store directory should ship with the deployed farm master files.'
);
assertSameValue(null, $defaultConfig['storage_warning'], 'Writable default farm store should not warn.');

$fallbackDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reflection_farm_fallback_' . bin2hex(random_bytes(6));
$fallbackConfig = reflection_resolve_master_store(
    null,
    '/proc/reflection_farm_store/farm_store.json',
    $fallbackDirectory,
);
assertSameValue(
    $fallbackDirectory . DIRECTORY_SEPARATOR . 'farm_store.json',
    $fallbackConfig['storage_path'],
    'Unwritable default farm store should use the temporary fallback store.'
);
assertSameValue(
    true,
    is_string($fallbackConfig['storage_warning']) && strpos($fallbackConfig['storage_warning'], '/proc/reflection_farm_store') !== false,
    'Fallback farm store should warn about the unwritable default path.'
);
@rmdir($fallbackDirectory);

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$job = $store->createJob('dummy_task', 'incoming/source.dat', 'outputs/result.txt', false);
assertSameValue('job_1001', $job['task_id'], 'Job ids should start at job_1001.');
assertSameValue('job_queued', $store->readRecentEvents(1)[0]['event'], 'Queued jobs should be written to the event log.');
assertSameValue(
    'queued_as_source',
    $store->readFileHistory()['incoming/source.dat'][0]['action'],
    'Queued source files should be written to file history.'
);

$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'old-version',
    'pc_id' => 'node-01',
], $store, $config);
assertSameValue('version_mismatch', $response['status'], 'Wrong worker versions must be rejected.');
assertSameValue('test-version', $response['required_version'], 'Version mismatch should publish the required version.');

$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'node-01',
], $store, $config);
assertSameValue('task_available', $response['status'], 'Queued jobs should be offered to workers.');
assertSameValue('dummy_task', $response['task']['module'], 'API should expose the queued module.');
assertSameValue(false, $response['task']['overwrite_allowed'], 'API should preserve overwrite policy.');

$response = reflection_handle_farm_api([
    'action' => 'confirm_taken',
    'version' => 'test-version',
    'pc_id' => 'node-01',
    'task_id' => 'job_1001',
], $store, $config);
assertSameValue('acknowledged', $response['status'], 'Workers should be able to lock queued jobs.');

$response = reflection_handle_farm_api([
    'action' => 'confirm_taken',
    'version' => 'test-version',
    'pc_id' => 'node-02',
    'task_id' => 'job_1001',
], $store, $config);
assertSameValue('not_available', $response['status'], 'A locked job should not be locked twice.');

$response = reflection_handle_farm_api([
    'action' => 'report_done',
    'version' => 'test-version',
    'pc_id' => 'node-01',
    'task_id' => 'job_1001',
    'status' => 'success',
    'error' => '',
], $store, $config);
assertSameValue('confirmed_by_server', $response['status'], 'Completed jobs should receive cleanup confirmation.');

$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'node-01',
], $store, $config);
assertSameValue('no_jobs', $response['status'], 'Finished jobs should leave the queue empty.');

$data = $store->read();
assertSameValue('success', $data['jobs'][0]['status'], 'Store should retain the final job status.');
assertSameValue(null, $data['workers']['node-01']['current_job'], 'Worker should be idle after report_done.');

unlink($storePath);
@unlink($eventLogPath);
@unlink($fileHistoryPath);
echo "farm master tests passed\n";
