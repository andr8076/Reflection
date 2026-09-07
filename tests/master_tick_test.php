<?php

declare(strict_types=1);

require_once __DIR__ . '/../MasterTick.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/reflection_master_tick_' . bin2hex(random_bytes(6));
$dataDirectory = $root . '/data';
mkdir($dataDirectory, 0775, true);
$storePath = $dataDirectory . '/farm_store.json';
$farmStore = new FarmStore($storePath);
$farmStore->updateSettings([
    'ess_soc_url' => '',
    'auto_wake_for_queued_jobs' => false,
]);
$automationStore = new AutomationStore($dataDirectory);
$config = [
    'storage_path' => $storePath,
    'stale_after_seconds' => 60,
    'task_specs' => [],
];

$result = reflection_run_master_tick($config, $farmStore, $automationStore);
assertSameValue('ok', $result['status'], 'The authoritative master tick should complete against an idle farm.');
assertSameValue(0, $result['expired_jobs'], 'An idle farm should not expire any leases.');
assertSameValue(0, $result['rules_run'], 'An idle farm should not run absent automation rules.');
$status = reflection_read_master_tick_status($dataDirectory);
assertSameValue('ok', $status['status'] ?? '', 'The tick should publish a readable health status for the dashboard.');

$lockHandle = fopen($dataDirectory . '/master_tick.lock', 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Unable to hold the master tick test lock.');
}
$busy = reflection_run_master_tick($config, $farmStore, $automationStore);
assertSameValue('busy', $busy['status'], 'Overlapping master ticks should be rejected instead of running twice.');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

foreach (glob($dataDirectory . '/*') ?: [] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($dataDirectory);
rmdir($root);

echo "master tick tests passed\n";
