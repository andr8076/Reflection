<?php

declare(strict_types=1);

define('REFLECTION_TESTING', true);

require_once __DIR__ . '/../farm_api.php';

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
    realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json',
    $defaultConfig['storage_path'],
    'Default farm store should live beside the deployed master website files.'
);
assertSameValue(
    true,
    is_dir(dirname($defaultConfig['storage_path'])),
    'Default farm store directory should ship with the deployed farm master files.'
);
assertSameValue(null, $defaultConfig['storage_warning'], 'Writable default farm store should not warn.');
assertSameValue(true, array_key_exists('wake_farm', $defaultConfig['allowed_tasks']), 'Wake-on-LAN should be an allowed master task.');
assertSameValue(true, array_key_exists('update_worker', $defaultConfig['allowed_tasks']), 'Remote worker update should be an allowed master task.');
assertSameValue(true, array_key_exists('h265_encode', $defaultConfig['allowed_tasks']), 'H.265 encoder should be an allowed master task.');
assertSameValue(true, $defaultConfig['runtime_defaults']['auto_wake_for_queued_jobs'], 'Demand-based Wake-on-LAN should default to enabled.');
assertSameValue(false, $defaultConfig['runtime_defaults']['shutdown_debug_mode'], 'Shutdown debug mode should default to disabled so server shutdown requests power off workers.');

assertSameValue('default', $defaultConfig['farm_id'], 'Default farm id should come from farm settings.');
assertSameValue(
    false,
    array_key_exists('default_login', $defaultConfig),
    'Master website login should not be configured.'
);
assertSameValue(false, array_key_exists('api_token', $defaultConfig), 'Worker API token config should not be present.');
assertSameValue(false, array_key_exists('worker_access_token', $defaultConfig), 'Worker access-token config should not be present.');
assertSameValue('', $defaultConfig['transfer_auth']['username'], 'Default FTP username should be blank until configured.');
assertSameValue('', $defaultConfig['transfer_auth']['password'], 'Default FTP password should be blank until configured.');

$localSettingsPath = __DIR__ . '/../farm_settings.local.php';
file_put_contents($localSettingsPath, "<?php
return ['farm_id' => 'local-farm'];
");
assertSameValue('local-farm', reflection_load_farm_settings()['farm_id'], 'Local untracked settings should override farm_settings.php.');
unlink($localSettingsPath);

$customStorePath = sys_get_temp_dir() . '/reflection_custom_defaults_' . bin2hex(random_bytes(6)) . '.json';
$customConfig = reflection_master_config([
    'farm_id' => 'paint-farm',
    'farm_name' => 'Paint Farm',
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
assertSameValue('paint-user', $customConfig['transfer_auth']['username'], 'Custom FTP username should be loaded from farm settings.');
assertSameValue('paint-pass', $customConfig['transfer_auth']['password'], 'Custom FTP password should be loaded from farm settings.');
assertSameValue('ftp.example.test', $customConfig['transfer_auth']['host'], 'Custom FTP host should be loaded from farm settings.');
assertSameValue('ftp.example.test', $customConfig['transfer_server']['host'], 'Transfer server host should default to the configured FTP host.');
assertSameValue(990, $customConfig['transfer_server']['port'], 'Transfer server port should default to the configured FTP port.');
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
assertSameValue('online', $socStore->effectiveSettings()['ess_soc_status'], 'Valid SOC refresh should mark ESS online.');
file_put_contents($socEndpointPath, 'this is not an SOC value');
assertSameValue(null, $socStore->refreshEssSocFromConfiguredEndpoint(), 'Unreadable SOC output should be rejected.');
assertSameValue('parse_error', $socStore->effectiveSettings()['ess_soc_status'], 'Unreadable SOC output should mark ESS as parse_error.');
assertSameValue(PHP_INT_MAX, $socStore->allowedActiveWorkers(), 'Unreadable ESS SOC should be ignored instead of limiting workers with a stale value.');
file_put_contents($socEndpointPath, '{"battery":{"soc":"12%"}}');
assertSameValue(12, $socStore->refreshEssSocFromConfiguredEndpoint(), 'Nested JSON SOC should parse when the value is valid.');
assertSameValue('online', $socStore->effectiveSettings()['ess_soc_status'], 'Valid SOC should recover ESS from parse_error.');
assertSameValue(0, $socStore->allowedActiveWorkers(), 'Recovered low SOC should resume limiting workers.');
$socStore->updateSettings(['ess_soc_url' => $socEndpointPath . '.missing']);
assertSameValue(null, $socStore->refreshEssSocFromConfiguredEndpoint(), 'Missing SOC endpoint should be treated as offline.');
assertSameValue('offline', $socStore->effectiveSettings()['ess_soc_status'], 'Connection failure should mark ESS as offline.');
assertSameValue(PHP_INT_MAX, $socStore->allowedActiveWorkers(), 'Offline ESS SOC should be ignored until connection returns.');
unlink($socStorePath);
unlink($socEndpointPath);



$wakeStorePath = sys_get_temp_dir() . '/reflection_wake_store_' . bin2hex(random_bytes(6)) . '.json';
$wakeStore = new FarmStore($wakeStorePath);
$wakeStore->updateSettings([
    'ess_soc_url' => '',
    'ess_soc_percent' => 100,
    'ess_min_soc_percent' => 20,
    'auto_wake_for_queued_jobs' => true,
    'auto_wake_cooldown_seconds' => 0,
    'auto_wake_max_targets_per_run' => 10,
]);
$wakeStore->updateMachines([
    ['pc_id' => 'node-wake-1', 'mac' => 'AA:BB:CC:DD:EE:01', 'soc_margin_percent' => 40, 'wake_enabled' => true],
    ['pc_id' => 'node-wake-2', 'mac' => 'AA:BB:CC:DD:EE:02', 'soc_margin_percent' => 40, 'wake_enabled' => true],
    ['pc_id' => 'node-wake-3', 'mac' => 'AA:BB:CC:DD:EE:03', 'soc_margin_percent' => 40, 'wake_enabled' => true],
]);
$wakeStore->recordWorkerCheckIn('node-wake-1', 'test-version');
$wakeStore->createJob('dummy_task', 'incoming/wake-1.dat', null, false);
$wakeStore->createJob('dummy_task', 'incoming/wake-2.dat', null, false);
$wakeStore->createJob('dummy_task', 'incoming/wake-3.dat', null, false);
$wakePlan = $wakeStore->demandWakePlan(900);
assertSameValue(3, $wakePlan['queued_work'], 'Demand wake should count queued non-control jobs.');
assertSameValue(1, $wakePlan['idle_online_workers'], 'Demand wake should treat idle online workers as existing capacity.');
assertSameValue(2, $wakePlan['needed'], 'Demand wake should only request workers for queued jobs not covered by idle online workers.');
assertSameValue(2, count($wakePlan['targets']), 'Demand wake should wake offline machines whose individual SOC margins fit the current headroom.');
assertSameValue('node-wake-2', $wakePlan['targets'][0]['pc_id'], 'Demand wake should choose the cheapest eligible offline machine first.');
assertSameValue('node-wake-3', $wakePlan['targets'][1]['pc_id'], 'Demand wake should not spend the SOC headroom cumulatively across eligible machines.');
$relayResult = $wakeStore->dispatchWakeTargets($wakePlan['targets'], 'manual');
assertSameValue('worker_relay', $relayResult['method'], 'Manual wake should queue a worker relay job by default.');
assertSameValue(2, $relayResult['queued'], 'Manual wake should queue each eligible offline target for the relay worker.');
$relayPayload = json_decode((string) ($relayResult['relay_job']['source'] ?? ''), true);
assertSameValue('AA:BB:CC:DD:EE:02', $relayPayload['targets'][0]['mac'] ?? null, 'Wake relay jobs should include the target MAC address in their source payload.');
assertSameValue('255.255.255.255', $relayPayload['broadcast'] ?? null, 'Wake relay jobs should include the configured broadcast address.');
assertSameValue(9, $relayPayload['port'] ?? null, 'Wake relay jobs should include the configured UDP port.');
@unlink($wakeStorePath);
@unlink($wakeStorePath . '.lock');

$broadcastStorePath = sys_get_temp_dir() . '/reflection_broadcast_store_' . bin2hex(random_bytes(6)) . '.json';
$broadcastStore = new FarmStore($broadcastStorePath);
$broadcastStore->updateSettings([
    'wake_broadcast_address' => '255.255.255.0',
]);
$broadcastRelay = $broadcastStore->dispatchWakeTargets([
    ['pc_id' => 'node-mask', 'mac' => 'AA:BB:CC:DD:EE:04'],
], 'manual');
$broadcastPayload = json_decode((string) ($broadcastRelay['relay_job']['source'] ?? ''), true);
assertSameValue('255.255.255.255', $broadcastPayload['broadcast'] ?? null, 'Subnet masks should be normalized before creating Wake-on-LAN relay jobs.');
@unlink($broadcastStorePath);
@unlink($broadcastStorePath . '.lock');

$marginStorePath = sys_get_temp_dir() . '/reflection_margin_store_' . bin2hex(random_bytes(6)) . '.json';
$marginStore = new FarmStore($marginStorePath);
$marginStore->updateSettings([
    'ess_soc_url' => '',
    'ess_soc_percent' => 51,
    'ess_min_soc_percent' => 20,
]);
$marginStore->updateMachines([
    ['pc_id' => 'farm1', 'mac' => '6e:0b:5a:40:7b:74', 'soc_margin_percent' => 25, 'wake_enabled' => false],
    ['pc_id' => 'farm2', 'mac' => '20:87:56:ba:0c:f1', 'soc_margin_percent' => 25, 'wake_enabled' => true],
    ['pc_id' => 'farm3', 'mac' => '20:87:56:ba:05:01', 'soc_margin_percent' => 25, 'wake_enabled' => true],
    ['pc_id' => 'farm4', 'mac' => '20:87:56:ba:06:47', 'soc_margin_percent' => 25, 'wake_enabled' => true],
]);
$marginStore->recordWorkerCheckIn('farm3', 'test-version');
assertSameValue(4, $marginStore->allowedActiveWorkers(), 'SOC worker limit should count all configured workers whose margin fits, including workers without Wake-on-LAN.');
$marginTargets = $marginStore->wakeTargetsForCurrentSoc(true, 900);
assertSameValue(2, count($marginTargets), 'Manual wake target lookup should exclude online workers and keep offline workers whose margins fit.');
assertSameValue('farm2', $marginTargets[0]['pc_id'], 'First eligible wake target should be farm2.');
assertSameValue('farm4', $marginTargets[1]['pc_id'], 'Second eligible wake target should be farm4.');
@unlink($marginStorePath);
@unlink($marginStorePath . '.lock');

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
assertSameValue('version_mismatch', $response['status'], 'Wrong worker versions must be rejected when no updater job is queued.');
assertSameValue('test-version', $response['required_version'], 'Version mismatch should publish the required version.');

$updateJob = $store->createJob('update_worker', null, null, false);
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'old-version',
    'pc_id' => 'node-updater',
], $store, $config);
assertSameValue('task_available', $response['status'], 'Outdated workers should still be allowed to receive update_worker jobs.');
assertSameValue('update_worker', $response['task']['module'], 'Version-recovery task should be the updater task.');
assertSameValue(true, $response['version_mismatch'], 'Updater recovery responses should mark that the worker is outdated.');
$response = reflection_handle_farm_api([
    'action' => 'confirm_taken',
    'version' => 'old-version',
    'pc_id' => 'node-updater',
    'task_id' => $updateJob['task_id'],
], $store, $config);
assertSameValue('acknowledged', $response['status'], 'Outdated workers should be allowed to start update_worker jobs.');
$response = reflection_handle_farm_api([
    'action' => 'report_done',
    'version' => 'old-version',
    'pc_id' => 'node-updater',
    'task_id' => $updateJob['task_id'],
    'status' => 'success',
    'error' => '',
], $store, $config);
assertSameValue('confirmed_by_server', $response['status'], 'Outdated workers should be allowed to report update_worker success before rebooting.');

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

assertSameValue(
    'files.example.test',
    reflection_worker_transfer_server([
        'transfer_server' => [
            'scheme' => 'sftp',
            'host' => 'files.example.test',
            'port' => 2222,
            'root' => '/shared',
        ],
    ])['host'],
    'Configured transfer server details should be available without credentials.'
);

$serverStorePath = sys_get_temp_dir() . '/reflection_server_store_' . bin2hex(random_bytes(6)) . '.json';
$serverStore = new FarmStore($serverStorePath);
$serverStore->updateSettings(['ess_soc_url' => '']);
$serverStore->createJob('invert_image', '/System/images/in.jpg', '/System/images/out.jpg', false);
$serverResponse = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'server-version',
    'pc_id' => 'node-server',
], $serverStore, [
    'required_version' => 'server-version',
    'transfer_server' => [
        'scheme' => 'ftp',
        'host' => 'nas.example.test',
        'port' => 21,
        'root' => '',
    ],
]);
assertSameValue('task_available', $serverResponse['status'], 'Transfer-server jobs should still be offered to workers.');
assertSameValue('transfer', $serverResponse['task']['path_mode'], 'Transfer-server jobs should tell workers to stage plain paths through transfer.');
assertSameValue('nas.example.test', $serverResponse['task']['transfer_server']['host'], 'API should send server details to workers.');
assertSameValue(false, array_key_exists('username', $serverResponse['task']['transfer_server']), 'API should not put worker usernames in transfer_server.');
assertSameValue(false, array_key_exists('password', $serverResponse['task']['transfer_server']), 'API should not put worker passwords in transfer_server.');
assertSameValue(false, array_key_exists('transfer_auth', $serverResponse['task']), 'API should not send transfer credentials by default.');
@unlink($serverStorePath);
@unlink($serverStorePath . '.lock');

$response = reflection_handle_farm_api([
    'action' => 'confirm_taken',
    'version' => 'test-version',
    'pc_id' => 'node-01',
    'task_id' => 'job_1001',
], $store, $config);
assertSameValue('acknowledged', $response['status'], 'Workers should be able to lock queued jobs.');

$response = reflection_handle_farm_api([
    'action' => 'heartbeat_task',
    'version' => 'test-version',
    'pc_id' => 'node-01',
    'task_id' => 'job_1001',
], $store, $config);
assertSameValue('heartbeat_acknowledged', $response['status'], 'Workers should be able to heartbeat running jobs they own.');

$response = reflection_handle_farm_api([
    'action' => 'heartbeat_task',
    'version' => 'test-version',
    'pc_id' => 'node-02',
    'task_id' => 'job_1001',
], $store, $config);
assertSameValue('not_available', $response['status'], 'Workers should not heartbeat jobs owned by another worker.');

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
assertSameValue(false, $response['shutdown_debug_mode'], 'Task closeout should publish shutdown debug mode to the worker.');

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
    'shutdown_debug_mode' => true,
]);
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'wrong-but-allowed',
    'pc_id' => 'node-03',
], $store, $config);
assertSameValue('no_jobs', $response['status'], 'SOC below minimum should withhold new work.');
assertSameValue(true, $response['shutdown_after_task'], 'SOC below minimum should ask idle workers to shut down.');
assertSameValue(true, $response['shutdown_debug_mode'], 'No-job shutdown responses should tell workers when shutdown debug mode is active.');

$store->updateSettings([
    'ess_soc_percent' => 100,
    'ess_min_soc_percent' => 20,
    'idle_shutdown_after_no_job_checks' => 2,
    'shutdown_debug_mode' => false,
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
assertSameValue(false, $response['shutdown_debug_mode'], 'No-job limit responses should publish disabled shutdown debug mode.');

$retryJob = $store->createJob('dummy_task', 'incoming/retry.dat', 'outputs/retry.txt', false);
assertSameValue(true, $store->markJobRunning($retryJob['task_id'], 'node-04'), 'Retry test job should lock.');
assertSameValue(true, $store->finishJob($retryJob['task_id'], 'node-04', 'failed', 'simulated'), 'Retry test job should finish as failed.');
$data = $store->read();
$retriedJobs = array_values(array_filter($data['jobs'], static fn (array $job): bool => ($job['parent_task_id'] ?? '') === $retryJob['task_id']));
assertSameValue('queued', $retriedJobs[0]['status'], 'Failed jobs should be retried to the end of the queue.');
assertSameValue(1, $retriedJobs[0]['attempt'], 'Retried jobs should increment attempt count.');

$staleJob = $store->createJob('dummy_task', 'incoming/stale.dat', 'outputs/stale.txt', false);
assertSameValue(true, $store->markJobRunning($staleJob['task_id'], 'node-stale'), 'Stale test job should lock.');
$data = $store->read();
foreach ($data['jobs'] as &$jobForStaleTest) {
    if (($jobForStaleTest['task_id'] ?? '') === $staleJob['task_id']) {
        $jobForStaleTest['started_at'] = gmdate(DATE_ATOM, time() - 3600);
        $jobForStaleTest['heartbeat_at'] = gmdate(DATE_ATOM, time() - 3600);
    }
}
unset($jobForStaleTest);
file_put_contents($storePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
assertSameValue(1, $store->requeueStaleJobs(60), 'Stale running jobs should be detected.');
$data = $store->read();
$staleJobs = array_values(array_filter($data['jobs'], static fn (array $job): bool => ($job['task_id'] ?? '') === $staleJob['task_id']));
$requeuedStaleJobs = array_values(array_filter($data['jobs'], static fn (array $job): bool => ($job['requeued_from_stale_task_id'] ?? '') === $staleJob['task_id']));
assertSameValue('stale', $staleJobs[0]['status'], 'Stale jobs should be marked stale.');
assertSameValue(null, $data['workers']['node-stale']['current_job'], 'Stale jobs should clear the worker current_job field.');
assertSameValue('queued', $requeuedStaleJobs[0]['status'], 'Stale jobs should be requeued to the end by default.');
assertSameValue(1, $requeuedStaleJobs[0]['attempt'], 'Requeued stale jobs should increment attempt count.');


$interruptStorePath = sys_get_temp_dir() . '/reflection_interrupt_store_' . bin2hex(random_bytes(6)) . '.json';
$interruptStore = new FarmStore($interruptStorePath);
$interruptStore->updateSettings(['ess_soc_url' => '', 'stale_job_strategy' => 'requeue_to_end', 'stale_max_retries' => 1]);
$interruptJob = $interruptStore->createJob('dummy_task', 'incoming/interrupted.dat', 'outputs/interrupted.txt', false);
assertSameValue(true, $interruptStore->markJobRunning($interruptJob['task_id'], 'node-interrupt'), 'Interrupt test job should lock.');
$response = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'node-interrupt',
], $interruptStore, ['required_version' => 'test-version']);
assertSameValue('task_available', $response['status'], 'A worker asking for new work while still assigned should trigger master-side crash recovery.');
$interruptData = $interruptStore->read();
assertSameValue('stale', $interruptData['jobs'][0]['status'], 'Interrupted jobs should be marked stale.');
assertSameValue('queued', $interruptData['jobs'][1]['status'], 'Interrupted jobs should be requeued by stale policy.');
assertSameValue('worker_requested_new_task_without_completion', $interruptData['jobs'][0]['loss_reason'], 'Interrupted jobs should record why the master marked them lost.');
@unlink($interruptStorePath);
@unlink($interruptStorePath . '.lock');

$orderStorePath = sys_get_temp_dir() . '/reflection_order_store_' . bin2hex(random_bytes(6)) . '.json';
$orderStore = new FarmStore($orderStorePath);
$orderStore->updateSettings(['ess_soc_url' => '']);
$orderJobA = $orderStore->createJob('dummy_task', 'incoming/order-a.dat', null, false);
$orderJobB = $orderStore->createJob('dummy_task', 'incoming/order-b.dat', null, false);
$orderJobC = $orderStore->createJob('dummy_task', 'incoming/order-c.dat', null, false);
assertSameValue($orderJobA['task_id'], $orderStore->nextQueuedJob()['task_id'], 'The oldest queued job should be offered first by default.');
assertSameValue(true, $orderStore->moveQueuedJob($orderJobC['task_id'], 'earlier'), 'Queued jobs should be movable earlier.');
assertSameValue(true, $orderStore->moveQueuedJob($orderJobC['task_id'], 'earlier'), 'Queued jobs should be movable to the front.');
assertSameValue($orderJobC['task_id'], $orderStore->nextQueuedJob()['task_id'], 'Moving a queued job earlier should change worker pick-up order.');
assertSameValue(true, $orderStore->moveQueuedJob($orderJobC['task_id'], 'later'), 'Queued jobs should be movable later.');
assertSameValue($orderJobA['task_id'], $orderStore->nextQueuedJob()['task_id'], 'Moving a queued job later should restore the next queued item.');
assertSameValue(true, $orderStore->markJobRunning($orderJobA['task_id'], 'node-order'), 'Order test job should lock before delete checks.');
assertSameValue(false, $orderStore->deleteJob($orderJobA['task_id']), 'Running jobs should not be deleted from the dashboard.');
assertSameValue(false, $orderStore->moveQueuedJob($orderJobA['task_id'], 'later'), 'Running jobs should not be reordered.');
assertSameValue(true, $orderStore->deleteJob($orderJobB['task_id']), 'Queued jobs should be deletable from the dashboard.');
$orderData = $orderStore->read();
assertSameValue(2, count($orderData['jobs']), 'Deleted jobs should be removed from the live store.');
@unlink($orderStorePath);
@unlink($orderStorePath . '.lock');

$workerStorePath = sys_get_temp_dir() . '/reflection_worker_cleanup_store_' . bin2hex(random_bytes(6)) . '.json';
$workerStore = new FarmStore($workerStorePath);
$workerStore->updateSettings(['ess_soc_url' => '']);
$workerStore->recordWorkerCheckIn('node-fresh-cleanup', 'test-version');
assertSameValue(false, $workerStore->removeWorker('node-fresh-cleanup', true, 900), 'Fresh worker check-ins should not be removed by stale cleanup.');
$workerStore->recordWorkerCheckIn('node-stale-cleanup', 'test-version');
$workerData = $workerStore->read();
$workerData['workers']['node-stale-cleanup']['last_check_in'] = gmdate(DATE_ATOM, time() - 3600);
file_put_contents($workerStorePath, json_encode($workerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
assertSameValue(true, $workerStore->removeWorker('node-stale-cleanup', true, 900), 'Stale worker check-ins should be removable from the dashboard board.');
$workerData = $workerStore->read();
assertSameValue(false, array_key_exists('node-stale-cleanup', $workerData['workers']), 'Removed stale workers should disappear from the worker map.');
@unlink($workerStorePath);
@unlink($workerStorePath . '.lock');

$crashLoopStorePath = sys_get_temp_dir() . '/reflection_crash_loop_store_' . bin2hex(random_bytes(6)) . '.json';
$crashLoopStore = new FarmStore($crashLoopStorePath);
$crashLoopStore->updateSettings([
    'ess_soc_url' => '',
    'stale_job_strategy' => 'requeue_to_end',
    'stale_max_retries' => 3,
    'crash_loop_protection_enabled' => true,
    'crash_loop_lost_attempts' => 2,
    'crash_loop_distinct_workers' => 2,
]);
$crashLoopJob = $crashLoopStore->createJob('dummy_task', 'incoming/crash-loop.dat', 'outputs/crash-loop.txt', false);
assertSameValue(true, $crashLoopStore->markJobRunning($crashLoopJob['task_id'], 'node-crash-a'), 'Crash-loop first attempt should lock.');
$crashLoopStore->recoverInterruptedJobForWorker('node-crash-a');
$crashLoopData = $crashLoopStore->read();
assertSameValue('stale', $crashLoopData['jobs'][0]['status'], 'First crash-loop loss should be marked stale.');
assertSameValue('queued', $crashLoopData['jobs'][1]['status'], 'First crash-loop loss should still be retried after worker-request recovery.');
assertSameValue(true, $crashLoopStore->markJobRunning($crashLoopData['jobs'][1]['task_id'], 'node-crash-b'), 'Crash-loop second attempt should lock on another worker.');
$crashLoopStore->recoverInterruptedJobForWorker('node-crash-b');
$crashLoopData = $crashLoopStore->read();
assertSameValue('blocked', $crashLoopData['jobs'][1]['status'], 'Repeated lost attempts across workers should be blocked.');
assertSameValue(2, count($crashLoopData['jobs']), 'Blocked crash-loop jobs should not be requeued again.');
assertSameValue(null, $crashLoopData['workers']['node-crash-b']['current_job'], 'Blocked crash-loop jobs should clear the worker current_job field.');
@unlink($crashLoopStorePath);
@unlink($crashLoopStorePath . '.lock');



$layerStorePath = sys_get_temp_dir() . '/reflection_shutdown_layer_store_' . bin2hex(random_bytes(6)) . '.json';
$layerStore = new FarmStore($layerStorePath);
$layerStore->updateSettings([
    'ess_soc_url' => '',
    'idle_shutdown_after_no_job_checks' => 1,
    'shutdown_debug_mode' => true,
]);
$layerStore->updateMachines([
    ['pc_id' => 'core-switch-node', 'mac' => '', 'soc_margin_percent' => 5, 'wake_enabled' => false, 'shutdown_layer' => 0],
    ['pc_id' => 'endpoint-node', 'mac' => '', 'soc_margin_percent' => 5, 'wake_enabled' => false, 'shutdown_layer' => 2],
]);
$layerStore->recordWorkerCheckIn('core-switch-node', 'test-version');
$layerStore->recordWorkerCheckIn('endpoint-node', 'test-version');
$coreResponse = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'core-switch-node',
], $layerStore, ['required_version' => 'test-version', 'stale_after_seconds' => 900]);
assertSameValue(false, $coreResponse['shutdown_after_task'], 'Lower shutdown layers must stay online while higher layers are still online.');
assertSameValue('shutdown_layer_waiting', $coreResponse['reason'], 'Layer-blocked shutdowns should explain why the worker stays online.');
$endpointResponse = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'endpoint-node',
], $layerStore, ['required_version' => 'test-version', 'stale_after_seconds' => 900]);
assertSameValue(true, $endpointResponse['shutdown_after_task'], 'Highest online shutdown layer should be allowed to power off first.');
$layerData = $layerStore->read();
assertSameValue(true, !empty($layerData['workers']['endpoint-node']['expected_offline']), 'Shutdown approval should mark a debug worker expected-offline immediately.');
$coreResponseAfterEndpoint = reflection_handle_farm_api([
    'action' => 'request_task',
    'version' => 'test-version',
    'pc_id' => 'core-switch-node',
], $layerStore, ['required_version' => 'test-version', 'stale_after_seconds' => 900]);
assertSameValue(true, $coreResponseAfterEndpoint['shutdown_after_task'], 'Lower layers should power off after higher online layers are offline.');
@unlink($layerStorePath);
@unlink($layerStorePath . '.lock');

unlink($storePath);
@unlink($eventLogPath);
@unlink($fileHistoryPath);
echo "farm master tests passed\n";
