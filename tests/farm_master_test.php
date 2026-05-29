<?php

declare(strict_types=1);

define('REFLECTION_TESTING', true);

require_once __DIR__ . '/../master/farm_api.php';

$storePath = sys_get_temp_dir() . '/reflection_farm_store_' . bin2hex(random_bytes(6)) . '.json';
$store = new FarmStore($storePath);
$config = [
    'required_version' => 'test-version',
];

function assertSameValue(mixed $expected, mixed $actual, string $message): void
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
echo "farm master tests passed\n";
