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

$duplicateRule = $automationStore->duplicateRule($rule['id'], ['dummy_task' => 'dummy']);
assertSameValue(false, $duplicateRule === null, 'Existing automation rules should be duplicatable.');
assertSameValue('', $duplicateRule['last_scan_at'] ?? '', 'Duplicated rules should not inherit the scan timestamp.');
assertSameValue([], $duplicateRule['last_scan_summary'] ?? [], 'Duplicated rules should not inherit the scan summary.');
assertSameValue(false, $duplicateRule['enabled'], 'Duplicated rules should be disabled until explicitly enabled.');
assertSameValue('Text files (copy)', $duplicateRule['name'], 'Duplicated rules should get a clear copy name.');
assertSameValue(false, $duplicateRule['id'] === $rule['id'], 'Duplicated rules should receive a new id.');

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


$invalidErrors = $automationStore->validateRule($automationStore->normalizeRule([
    'name' => 'Invalid placeholder',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'source_template' => '{relatiive}',
]), ['dummy_task' => 'dummy']);
assertSameValue(true, count($invalidErrors) > 0, 'Invalid template placeholders should be rejected.');
assertSameValue(true, strpos(implode(' ', $invalidErrors), '{relatiive}') !== false, 'Invalid placeholder error should include the bad placeholder name.');

$commandPlaceholderErrors = $automationStore->validateRule($automationStore->normalizeRule([
    'name' => 'Command task placeholder',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'source_template' => '{path}',
    'command_filter_mode' => 'exit_zero',
    'command_filter_command' => 'python3 {task_file} --preflight {path}',
]), ['dummy_task' => 'dummy']);
assertSameValue(false, strpos(implode(' ', $commandPlaceholderErrors), '{task_file}') !== false, 'Command templates should allow task-owned placeholders like {task_file}.');

$pathTaskPlaceholderErrors = $automationStore->validateRule($automationStore->normalizeRule([
    'name' => 'Path task placeholder invalid',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'source_template' => '{task_file}',
]), ['dummy_task' => 'dummy']);
assertSameValue(true, strpos(implode(' ', $pathTaskPlaceholderErrors), '{task_file}') !== false, 'Path templates should still reject command-only placeholders like {task_file}.');

file_put_contents($scanDir . DIRECTORY_SEPARATOR . 'candidate.cmd', 'candidate');
touch($scanDir . DIRECTORY_SEPARATOR . 'candidate.cmd', time() - 3600);
$workerFilterRule = $automationStore->saveRule([
    'name' => 'Worker preflight candidates',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir . DIRECTORY_SEPARATOR . 'candidate.cmd',
    'recursive' => false,
    'source_template' => '{path}',
    'extensions' => 'cmd',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
    'command_filter_mode' => 'exit_zero',
    'command_filter_command' => 'python3 {task_file} --preflight {path}',
    'command_timeout_seconds' => 900,
], ['dummy_task' => 'dummy']);
$workerFilterDryRun = $automationStore->runRule($workerFilterRule, $farmStore, true);
assertSameValue('would_queue_candidate', $workerFilterDryRun['rows'][0]['status'] ?? '', 'Dry run should show worker-filtered items as queued candidates, without running the command on the master.');
$workerFilterRun = $automationStore->runRule($workerFilterRule, $farmStore, false);
assertSameValue(1, $workerFilterRun['queued'], 'Worker command filters should queue candidate jobs for farms to evaluate.');
$data = $farmStore->read();
$candidateJob = end($data['jobs']);
assertSameValue(true, is_array($candidateJob['worker_command_filter'] ?? null), 'Candidate job should carry the worker command filter payload.');
assertSameValue(true, !empty($candidateJob['candidate_job']), 'Candidate job should be marked as a candidate instead of a separate filter-test job.');
assertSameValue('pending', $candidateJob['worker_preflight_status'] ?? '', 'Candidate jobs should wait for worker-side preflight.');


$mappedRule = $automationStore->saveRule([
    'name' => 'Mapped worker paths',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'recursive' => true,
    'worker_path_mappings' => $scanDir . ' => /ftp/movies',
    'source_template' => '{worker_path}',
    'delivery_mode' => 'same_as_source',
    'overwrite_allowed' => false,
    'output_suffix' => '_worker',
    'extensions' => 'txt',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['dummy_task' => 'dummy']);
$mappedTest = $automationStore->testRule($mappedRule, $scanDir . DIRECTORY_SEPARATOR . 'one.txt', 10);
assertSameValue('/ftp/movies/one.txt', $mappedTest['rows'][0]['source'], 'Mapped rules should send worker-visible paths as the job source.');
assertSameValue('/ftp/movies/one_worker.txt', $mappedTest['rows'][0]['delivery'], 'Same-as-source delivery should use the worker-visible source path.');

$mappedInvalid = $automationStore->validateRule($automationStore->normalizeRule([
    'name' => 'Invalid mapping',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir,
    'worker_path_mappings' => $scanDir . ' /ftp/movies',
]), ['dummy_task' => 'dummy']);
assertSameValue(true, count($mappedInvalid) > 0, 'Invalid worker path mappings should be rejected.');


$result = $automationStore->runRule($automationStore->rule($rule['id']), $farmStore, false);
assertSameValue(0, $result['queued'], 'Unchanged files should not be queued twice.');

$rule = $automationStore->saveRule(array_merge($rule, ['requeue_unchanged' => true]), ['dummy_task' => 'dummy']);
$result = $automationStore->runRule($rule, $farmStore, false);
assertSameValue(1, $result['queued'], 'Requeue override should queue an unchanged file even while an equivalent job is already open.');
$data = $farmStore->read();
assertSameValue(3, count($data['jobs']), 'Requeue override should allow concurrent duplicate jobs without disturbing candidate jobs.');

$due = $automationStore->runDueRules($farmStore, true);
assertSameValue(0, count($due), 'Recently scanned rule should not be due yet.');

$contractStore = new AutomationStore($dataDir, [
    'h265_encode' => [
        'delivery' => [
            'mode' => 'auto',
            'template' => '{dir}/{name}_h265.mkv',
            'extension' => '.mkv',
        ],
    ],
]);
file_put_contents($scanDir . DIRECTORY_SEPARATOR . 'movie.mp4', 'video');
touch($scanDir . DIRECTORY_SEPARATOR . 'movie.mp4', time() - 3600);
$h265Rule = $contractStore->saveRule([
    'name' => 'H265 automatic delivery',
    'enabled' => false,
    'module' => 'h265_encode',
    'scan_roots' => $scanDir . DIRECTORY_SEPARATOR . 'movie.mp4',
    'recursive' => false,
    'source_template' => '{path}',
    'delivery_mode' => 'same_as_source',
    'overwrite_allowed' => true,
    'extensions' => 'mp4',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['h265_encode' => 'H265']);
$h265Test = $contractStore->testRule($h265Rule, $scanDir . DIRECTORY_SEPARATOR . 'movie.mp4', 10);
assertSameValue($scanDir . DIRECTORY_SEPARATOR . 'movie_h265.mkv', $h265Test['rows'][0]['delivery'], 'Task automatic delivery should override same-as-source for h265_encode.');
$h265Run = $contractStore->runRule($h265Rule, $farmStore, false);
assertSameValue(1, $h265Run['queued'], 'H265 rule should queue one job.');
$data = $farmStore->read();
$lastJob = end($data['jobs']);
assertSameValue($scanDir . DIRECTORY_SEPARATOR . 'movie_h265.mkv', $lastJob['delivery'], 'Queued h265 job should deliver to MKV path from task contract.');

$protocolStore = new AutomationStore($dataDir, [], ['archive' => 'sftp']);
$protocolRule = $protocolStore->saveRule([
    'name' => 'SFTP capability routing',
    'enabled' => false,
    'module' => 'dummy_task',
    'scan_roots' => $scanDir . DIRECTORY_SEPARATOR . 'two.log',
    'recursive' => false,
    'source_template' => '{path}',
    'transfer_server_id' => 'archive',
    'extensions' => 'log',
    'require_unchanged_seconds' => 0,
    'max_files_per_scan' => 10,
    'max_jobs_per_scan' => 10,
], ['dummy_task' => 'dummy'], ['archive']);
$protocolRun = $protocolStore->runRule($protocolRule, $farmStore, false);
assertSameValue(1, $protocolRun['queued'], 'Automation jobs should queue through their selected transfer server.');
$data = $farmStore->read();
$protocolJob = end($data['jobs']);
assertSameValue('sftp', $protocolJob['required_transfer_scheme'] ?? '', 'Automation jobs should carry transfer protocol requirements into worker scheduling.');

$badTemplateErrors = $contractStore->validateRule($contractStore->normalizeRule([
    'name' => 'Bad H265 delivery',
    'enabled' => false,
    'module' => 'h265_encode',
    'scan_roots' => $scanDir,
    'source_template' => '{path}',
    'delivery_mode' => 'template',
    'delivery_template' => '{dir}/{name}.mp4',
]), ['h265_encode' => 'H265']);
assertSameValue(true, strpos(implode(' ', $badTemplateErrors), '.mkv') !== false, 'Custom h265 delivery templates must be validated against the task extension.');


array_map('unlink', glob($dataDir . DIRECTORY_SEPARATOR . '*') ?: []);
array_map('unlink', glob($scanDir . DIRECTORY_SEPARATOR . '*') ?: []);
@rmdir($scanDir);
@rmdir($dataDir);
@rmdir($base);

echo "automation tests passed\n";
