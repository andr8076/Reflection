<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../FarmStore.php';
require_once __DIR__ . '/../AutomationStore.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reflection_automation_' . bin2hex(random_bytes(6));
$dataDir = $base . DIRECTORY_SEPARATOR . 'data';
$scanDir = $base . DIRECTORY_SEPARATOR . 'scan';
mkdir($dataDir, 0775, true);
mkdir($scanDir, 0775, true);
file_put_contents($scanDir . DIRECTORY_SEPARATOR . 'one.txt', 'one');
file_put_contents($scanDir . DIRECTORY_SEPARATOR . 'two.log', 'two');
touch($scanDir . DIRECTORY_SEPARATOR . 'one.txt', time() - 3600);
touch($scanDir . DIRECTORY_SEPARATOR . 'two.log', time() - 3600);

$farmStore = new FarmStore($dataDir . DIRECTORY_SEPARATOR . 'farm_store.json', ['ess_soc_url' => '']);
$automationStore = new AutomationStore($dataDir);
$rule = $automationStore->saveRule([
    'name' => 'Text files',
    'enabled' => true,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'recursive' => true,
    'source_template' => '{path}',
    'delivery_template' => $base . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . '{basename}',
    'extensions' => 'txt',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['dummy_task' => 'dummy']);

$test = $automationStore->testRule($rule, '', 10);
assertSameValue(2, $test['scanned'], 'Filter test should scan both files under the root.');
assertSameValue(1, $test['matched'], 'Filter test should match only txt files.');

$result = $automationStore->runRule($rule, $farmStore, false);
assertSameValue(2, $result['scanned'], 'Run should scan both files.');
assertSameValue(1, $result['queued'], 'Run should queue one matching file.');
$data = $farmStore->read();
assertSameValue(1, count($data['jobs']), 'Farm store should contain one queued automation job.');
assertSameValue('dummy_task', $data['jobs'][0]['module'], 'Automation job should use the configured task.');
assertSameValue($rule['id'], $data['jobs'][0]['automation_rule_id'], 'Automation jobs should be tagged with the rule id.');

$sameSourceRule = $automationStore->saveRule([
    'name' => 'Same source replacement',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir . DIRECTORY_SEPARATOR . 'one.txt',
    'recursive' => false,
    'source_template' => '{path}',
    'delivery_mode' => 'same_as_source',
    'overwrite_allowed' => true,
    'extensions' => 'txt',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['dummy_task' => 'dummy']);
$sameSourceTest = $automationStore->testRule($sameSourceRule, $scanDir . DIRECTORY_SEPARATOR . 'one.txt', 10);
assertSameValue($scanDir . DIRECTORY_SEPARATOR . 'one.txt', $sameSourceTest['rows'][0]['delivery'], 'Same-as-source with overwrite should deliver back to the source path.');

$siblingRule = $automationStore->saveRule([
    'name' => 'Same source sibling',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir . DIRECTORY_SEPARATOR . 'one.txt',
    'recursive' => false,
    'source_template' => '{path}',
    'delivery_mode' => 'same_as_source',
    'overwrite_allowed' => false,
    'output_suffix' => '_converted',
    'extensions' => 'txt',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['dummy_task' => 'dummy']);
$siblingTest = $automationStore->testRule($siblingRule, $scanDir . DIRECTORY_SEPARATOR . 'one.txt', 10);
assertSameValue($scanDir . DIRECTORY_SEPARATOR . 'one_converted.txt', $siblingTest['rows'][0]['delivery'], 'Same-as-source without overwrite should add the configured suffix.');

$result = $automationStore->runRule($automationStore->rule($rule['id']), $farmStore, false);
assertSameValue(0, $result['queued'], 'Unchanged files should not be queued twice.');

$due = $automationStore->runDueRules($farmStore, true);
assertSameValue(0, count($due), 'Recently scanned rule should not be due yet.');

array_map('unlink', glob($dataDir . DIRECTORY_SEPARATOR . '*') ?: []);
array_map('unlink', glob($scanDir . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($scanDir);
@rmdir($dataDir);
@rmdir($base);

echo "automation tests passed\n";
