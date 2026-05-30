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
$store->updateSettings(['ess_soc_url' => '']);
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
assertSameValue(true, array_key_exists('wake_farm', $defaultConfig['allowed_tasks']), 'Wake-on-LAN should be an allowed master task.');
assertSameValue(true, array_key_exists('h265_encode', $defaultConfig['allowed_tasks']), 'H.265 encoder should be an allowed master task.');

assertSameValue('default', $defaultConfig['farm_id'], 'Default farm id should come from farm settings.');
assertSameValue(
    false,
    array_key_exists('default_login', $defaultConfig),
    'Master website login should not be configured.'
);
assertSameValue('', $defaultConfig['api_token'], 'Default API token should be blank until configured.');
assertSameValue('', $defaultConfig['transfer_auth']['username'], 'Default FTP username should be blank until configured.');
assertSameValue('', $defaultConfig['transfer_auth']['password'], 'Default FTP password should be blank until configured.');

$localSettingsPath = __DIR__ . '/../master/farm_settings.local.php';
file_put_contents($localSettingsPath, "<?php
return ['api_token' => 'local-token'];
");
assertSameValue('local-token', reflection_load_farm_settings()['api_token'], 'Local untracked settings should override farm_settings.php.');
unlink($localSettingsPath);

$customStorePath = sys_get_temp_dir() . '/reflection_custom_defaults_' . bin2hex(random_bytes(6)) . '.json';
$customConfig = reflection_master_config([
    'farm_id' => 'paint-farm',
    'farm_name' => 'Paint Farm',
    'api_token' => 'paint-token',
    'transfer_auth' => [
        'scheme' => 'ftps',
        'host' => 'ftp.example.test',
        'port' => 990,
        'username' => 'paint-user',
        'password' => 'paint-pass',
    ],
    'storage_path' => $customStorePath,
    'required_version' => 'paint-version',
    'runtime_defaults' => ['ess_soc_url' => 'http://example.test/soc'],
    'allowed_tasks' => ['noop' => 'Connectivity check.'],
    'stale_after_seconds' => 30,
]);
assertSameValue('paint-farm', $customConfig['farm_id'], 'Custom farm id should be loaded from farm settings.');
assertSameValue('Paint Farm', $customConfig['farm_name'], 'Custom farm name should be loaded from farm settings.');
assertSameValue('paint-token', $customConfig['api_token'], 'Custom API token should be loaded from farm settings.');
assertSameValue('paint-user', $customConfig['transfer_auth']['username'], 'Custom FTP username should be loaded from farm settings.');
assertSameValue('paint-pass', $customConfig['transfer_auth']['password'], 'Custom FTP password should be loaded from farm settings.');
assertSameValue('ftp.example.test', $customConfig['transfer_auth']['host'], 'Custom FTP host should be loaded from farm settings.');
assertSameValue('paint-version', $customConfig['required_version'], 'Custom required version should be loaded from farm settings.');
assertSameValue('http://example.test/soc', $customConfig['runtime_defaults']['ess_soc_url'], 'Runtime defaults should be loaded from farm settings.');
assertSameValue(30, $customConfig['stale_after_seconds'], 'Stale timeout should be loaded from farm settings.');
$customDefaultsStore = new FarmStore($customStorePath, $customConfig['runtime_defaults']);
assertSameValue(
    'http://example.test/soc',
    $customDefaultsStore->effectiveSettings()['ess_soc_url'],
    'FarmStore should seed defaults from the farm config file.'
);
@unlink($customStorePath);

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

$socStorePath = sys_get_temp_dir() . '/reflection_soc_store_' . bin2hex(random_bytes(6)) . '.json';
$socEndpointPath = sys_get_temp_dir() . '/reflection_soc_endpoint_' . bin2hex(random_bytes(6)) . '.txt';
file_put_contents($socEndpointPath, '0.974381625411616');
$socStore = new FarmStore($socStorePath);
assertSameValue(
    'http://192.168.1.245:8076',
    $socStore->effectiveSettings()['ess_soc_url'],
    'Default ESS SOC URL should point at the local ESS endpoint.'
);
$socStore->updateSettings(['ess_soc_url' => $socEndpointPath]);
assertSameValue(97, $socStore->refreshEssSocFromConfiguredEndpoint(), 'Plain fractional SOC endpoint should convert to percent.');
assertSameValue(97, $socStore->effectiveSettings()['ess_soc_percent'], 'Parsed SOC should be stored as percent.');
unlink($socStorePath);
unlink($socEndpointPath);

$tokenStorePath = sys_get_temp_dir() . '/reflection_token_store_' . bin2hex(random_bytes(6)) . '.json';
$tokenStore = new FarmStore($tokenStorePath);
$tokenStore->updateSettings(['ess_soc_url' => '']);
$tokenConfig = ['api_token' => 'expected-token', 'required_version' => 'token-version'];
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'token-version',
    'pc_id' => 'node-token',
], $tokenStore, $tokenConfig);
assertSameValue('unauthorized', $response['status'], 'Configured API tokens should reject missing tokens.');
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'token-version',
    'pc_id' => 'node-token',
    'api_token' => 'expected-token',
], $tokenStore, $tokenConfig);
assertSameValue('no_jobs', $response['status'], 'Configured API tokens should accept matching tokens.');
unlink($tokenStorePath);
@unlink($tokenStorePath . '.lock');

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
assertSameValue(false, array_key_exists('transfer_auth', $response['task']), 'Workers should not receive blank transfer credentials.');
assertSameValue(
    'files-user',
    reflection_worker_transfer_auth([
        'transfer_auth' => [
            'scheme' => 'ftps',
            'host' => 'files.example.test',
            'port' => 990,
            'username' => 'files-user',
            'password' => 'files-pass',
        ],
    ])['username'],
    'Configured transfer credentials should still be available to workers.'
);

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
    'pc_id' => 'node-02',
    'task_id' => 'job_1001',
    'status' => 'success',
    'error' => '',
], $store, $config);
assertSameValue('not_available', $response['status'], 'Workers should not be able to finish jobs owned by another worker.');

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

$store->updateSettings([
    'enforce_version' => false,
    'failure_strategy' => 'retry_to_end',
    'max_retries' => 1,
    'ess_soc_percent' => 12,
    'ess_min_soc_percent' => 20,
]);
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'wrong-but-allowed',
    'pc_id' => 'node-03',
], $store, $config);
assertSameValue('no_jobs', $response['status'], 'SOC below minimum should withhold new work.');
assertSameValue(true, $response['shutdown_after_task'], 'SOC below minimum should ask idle workers to shut down.');

$store->updateSettings([
    'ess_soc_percent' => 100,
    'ess_min_soc_percent' => 20,
    'idle_shutdown_after_no_job_checks' => 2,
]);
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'node-idle',
], $store, $config);
assertSameValue('no_jobs', $response['status'], 'Idle workers should receive no_jobs while the queue is empty.');
assertSameValue(false, $response['shutdown_after_task'], 'Idle workers should keep polling before the no-job limit is reached.');
assertSameValue(1, $response['idle_no_job_checkins'], 'No-job check-ins should be counted per worker.');

$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'node-idle',
], $store, $config);
assertSameValue('no_jobs', $response['status'], 'Idle workers should still receive no_jobs at the no-job limit.');
assertSameValue(true, $response['shutdown_after_task'], 'Idle workers should be told to stop at the configured no-job limit.');
assertSameValue('idle_no_job_check_limit', $response['reason'], 'No-job limit shutdowns should explain the reason.');
assertSameValue(2, $response['idle_shutdown_after_no_job_checks'], 'No-job limit responses should publish the configured limit.');

$retryJob = $store->createJob('dummy_task', 'incoming/retry.dat', 'outputs/retry.txt', false);
assertSameValue(true, $store->markJobRunning($retryJob['task_id'], 'node-04'), 'Retry test job should lock.');
assertSameValue(true, $store->finishJob($retryJob['task_id'], 'node-04', 'failed', 'simulated'), 'Retry test job should finish as failed.');
$data = $store->read();
assertSameValue('queued', $data['jobs'][2]['status'], 'Failed jobs should be retried to the end of the queue.');
assertSameValue(1, $data['jobs'][2]['attempt'], 'Retried jobs should increment attempt count.');

$staleJob = $store->createJob('dummy_task', 'incoming/stale.dat', 'outputs/stale.txt', false);
assertSameValue(true, $store->markJobRunning($staleJob['task_id'], 'node-stale'), 'Stale test job should lock.');
$data = $store->read();
foreach ($data['jobs'] as &$jobForStaleTest) {
    if (($jobForStaleTest['task_id'] ?? '') === $staleJob['task_id']) {
        $jobForStaleTest['started_at'] = gmdate(DATE_ATOM, time() - 3600);
    }
}
unset($jobForStaleTest);
file_put_contents($storePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
assertSameValue(1, $store->requeueStaleJobs(60), 'Stale running jobs should be detected.');
$data = $store->read();
assertSameValue('stale', $data['jobs'][3]['status'], 'Stale jobs should be marked stale.');
assertSameValue(null, $data['workers']['node-stale']['current_job'], 'Stale jobs should clear the worker current_job field.');

unlink($storePath);
@unlink($eventLogPath);
@unlink($fileHistoryPath);
echo "farm master tests passed\n";
