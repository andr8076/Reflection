<?php

declare(strict_types=1);

require_once __DIR__ . '/../FarmStore.php';
require_once __DIR__ . '/../AutomationStore.php';
require_once __DIR__ . '/../StorageStore.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function assertRuntimeFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return;
    }
    throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/reflection_persistence_' . bin2hex(random_bytes(6));
mkdir($root, 0775, true);

$farmPath = $root . '/farm_store.json';
$farmStore = new FarmStore($farmPath);
$farmStore->updateSettings(['ess_soc_url' => '']);
$farmStore->updateSettings(['job_lease_seconds' => 240]);
assertSameValue(true, is_file($farmPath . '.bak'), 'Farm-store writes should keep the previous complete JSON as a backup.');
file_put_contents($farmPath, '');
assertRuntimeFailure(static fn () => $farmStore->read(), 'An empty farm store must not silently reset production state.');
assertSameValue(true, count(glob($farmPath . '.corrupt-*') ?: []) === 1, 'An empty farm store should be preserved for diagnosis.');

$automationPath = $root . '/automation_rules.json';
file_put_contents($automationPath, '');
$automationStore = new AutomationStore($root);
assertRuntimeFailure(static fn () => $automationStore->rules(), 'An empty automation store must not silently erase rules.');
assertSameValue(true, count(glob($automationPath . '.corrupt-*') ?: []) === 1, 'An empty automation store should be preserved for diagnosis.');

$storagePath = $root . '/storage_servers.json';
file_put_contents($storagePath, '');
$storageStore = new StorageStore($root);
assertRuntimeFailure(static fn () => $storageStore->servers(false), 'An empty storage-server file must not silently erase server definitions.');
assertSameValue(true, count(glob($storagePath . '.corrupt-*') ?: []) === 1, 'An empty storage-server file should be preserved for diagnosis.');

foreach (glob($root . '/*') ?: [] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($root);

echo "persistence tests passed\n";
