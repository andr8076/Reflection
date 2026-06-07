<?php

declare(strict_types=1);

require_once __DIR__ . '/FarmStore.php';

final class AutomationStore
{
    private string $directory;
    private string $rulesPath;
    private string $statePath;
    private string $runLogPath;
    private string $lockPath;
    private string $dueCheckLockPath;
    private string $dueCheckStatePath;
    private array $taskSpecs;

    public function __construct(string $directory, array $taskSpecs = [])
    {
        $this->directory = $directory;
        $this->taskSpecs = $taskSpecs;
        $this->rulesPath = $directory . DIRECTORY_SEPARATOR . 'automation_rules.json';
        $this->statePath = $directory . DIRECTORY_SEPARATOR . 'automation_state.json';
        $this->runLogPath = $directory . DIRECTORY_SEPARATOR . 'automation_runs.jsonl';
        $this->lockPath = $directory . DIRECTORY_SEPARATOR . 'automation.lock';
        $this->dueCheckLockPath = $directory . DIRECTORY_SEPARATOR . 'automation_due_check.lock';
        $this->dueCheckStatePath = $directory . DIRECTORY_SEPARATOR . 'automation_due_check.json';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create automation data directory: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('Automation data directory is not writable: %s', $directory));
        }
    }

    public function rules(): array
    {
        $data = $this->readJson($this->rulesPath, ['rules' => []]);
        $rules = [];
        foreach (($data['rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $rules[] = $this->normalizeRule($rule);
            }
        }

        usort($rules, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rules;
    }

    public function rule(string $id): ?array
    {
        foreach ($this->rules() as $rule) {
            if (($rule['id'] ?? '') === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function newRule(): array
    {
        return $this->normalizeRule([
            'id' => '',
            'name' => 'New automation rule',
            'enabled' => false,
            'module' => 'dummy_task',
            'scan_roots' => [],
            'recursive' => true,
            'worker_path_mappings' => '/volume1 => /',
            'transfer_server_id' => '',
            'source_template' => '{worker_path}',
            'delivery_mode' => 'template',
            'delivery_template' => '',
            'output_suffix' => '_processed',
            'overwrite_allowed' => false,
            'extensions' => '',
            'include_globs' => '',
            'exclude_globs' => "@eaDir\n#recycle\n*.tmp\n*.part",
            'include_regex' => '',
            'exclude_regex' => '',
            'min_size_mb' => '',
            'max_size_mb' => '',
            'require_unchanged_seconds' => 120,
            'command_filter_mode' => 'disabled',
            'command_filter_command' => '',
            'command_filter_regex' => '',
            'command_timeout_seconds' => 20,
            'max_files_per_scan' => 500,
            'max_jobs_per_scan' => 25,
            'scan_interval_minutes' => 60,
            'requeue_unchanged' => false,
            'state_keep_paths' => 10000,
        ]);
    }

    public function saveRule(array $input, array $allowedTasks, array $allowedStorageServerIds = []): array
    {
        $rule = $this->normalizeRule($input);
        if (($rule['id'] ?? '') === '') {
            $rule['id'] = $this->newRuleId();
            $rule['created_at'] = gmdate(DATE_ATOM);
        }
        $rule['updated_at'] = gmdate(DATE_ATOM);

        $errors = $this->validateRule($rule, $allowedTasks, $allowedStorageServerIds);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $this->withLock(function () use ($rule): void {
            $data = $this->readJson($this->rulesPath, ['rules' => []]);
            $rules = [];
            $updated = false;
            foreach (($data['rules'] ?? []) as $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                if (($existing['id'] ?? '') === $rule['id']) {
                    $rules[] = $rule;
                    $updated = true;
                } else {
                    $rules[] = $this->normalizeRule($existing);
                }
            }

            if (!$updated) {
                $rules[] = $rule;
            }

            $this->atomicWriteJson($this->rulesPath, ['rules' => array_values($rules)]);
        });

        return $rule;
    }

    public function deleteRule(string $id): bool
    {
        return $this->withLock(function () use ($id): bool {
            $data = $this->readJson($this->rulesPath, ['rules' => []]);
            $before = count($data['rules'] ?? []);
            $rules = [];
            foreach (($data['rules'] ?? []) as $rule) {
                if (is_array($rule) && ($rule['id'] ?? '') !== $id) {
                    $rules[] = $this->normalizeRule($rule);
                }
            }

            $this->atomicWriteJson($this->rulesPath, ['rules' => array_values($rules)]);
            $this->removeRuleState($id);
            return count($rules) !== $before;
        });
    }

    public function duplicateRule(string $id, array $allowedTasks, array $allowedStorageServerIds = []): ?array
    {
        $sourceRule = $this->rule($id);
        if ($sourceRule === null) {
            return null;
        }

        $copy = $sourceRule;
        $copy['id'] = '';
        $copy['name'] = $this->duplicateRuleName((string) ($sourceRule['name'] ?? 'Automation rule'));
        $copy['enabled'] = false;
        $copy['last_scan_at'] = null;
        $copy['last_scan_summary'] = [];
        $copy['created_at'] = null;
        $copy['updated_at'] = null;

        return $this->saveRule($copy, $allowedTasks, $allowedStorageServerIds);
    }

    public function setEnabled(string $id, bool $enabled): ?array
    {
        return $this->withLock(function () use ($id, $enabled): ?array {
            $data = $this->readJson($this->rulesPath, ['rules' => []]);
            $updatedRule = null;
            foreach ($data['rules'] as &$rule) {
                if (is_array($rule) && ($rule['id'] ?? '') === $id) {
                    $rule = $this->normalizeRule($rule);
                    $rule['enabled'] = $enabled;
                    $rule['updated_at'] = gmdate(DATE_ATOM);
                    $updatedRule = $rule;
                    break;
                }
            }
            unset($rule);

            $this->atomicWriteJson($this->rulesPath, ['rules' => array_values($data['rules'] ?? [])]);
            return $updatedRule;
        });
    }

    public function validateRule(array $rule, array $allowedTasks, array $allowedStorageServerIds = []): array
    {
        $errors = [];
        if (trim((string) ($rule['name'] ?? '')) === '') {
            $errors[] = 'Rule name is required.';
        }
        if (!array_key_exists((string) ($rule['module'] ?? ''), $allowedTasks)) {
            $errors[] = 'Choose an allowed task for the rule.';
        }
        if (($rule['scan_roots'] ?? []) === []) {
            $errors[] = 'At least one scan root is required.';
        }
        $transferServerId = trim((string) ($rule['transfer_server_id'] ?? ''));
        if ($transferServerId !== '' && $allowedStorageServerIds !== [] && !in_array($transferServerId, $allowedStorageServerIds, true)) {
            $errors[] = 'Choose an available storage server for this automation rule.';
        }
        foreach (($rule['scan_roots'] ?? []) as $root) {
            if (preg_match('/[\x00-\x1F\x7F]/', (string) $root) === 1) {
                $errors[] = 'Scan roots may not contain control characters.';
                break;
            }
        }
        foreach ($this->cleanLines($rule['worker_path_mappings'] ?? []) as $line) {
            if (!$this->parseWorkerPathMappingLine($line)) {
                $errors[] = 'Worker path mappings must use: master path => worker path.';
                break;
            }
        }
        foreach (['include_regex', 'exclude_regex', 'command_filter_regex'] as $regexKey) {
            $regex = trim((string) ($rule[$regexKey] ?? ''));
            if ($regex !== '' && !$this->regexIsValid($regex)) {
                $errors[] = $regexKey . ' is not a valid PHP regular expression.';
            }
        }
        if (!in_array((string) ($rule['command_filter_mode'] ?? 'disabled'), ['disabled', 'exit_zero', 'output_matches', 'output_not_matches'], true)) {
            $errors[] = 'The command filter mode is invalid.';
        }
        if (in_array((string) ($rule['command_filter_mode'] ?? 'disabled'), ['output_matches', 'output_not_matches'], true) && trim((string) ($rule['command_filter_regex'] ?? '')) === '') {
            $errors[] = 'Command output modes require a command output regex.';
        }
        $taskDelivery = $this->taskDeliverySpec((string) ($rule['module'] ?? ''));
        $taskAutoTemplate = $this->taskAutoDeliveryTemplate($taskDelivery);
        $customDeliveryTemplate = trim((string) ($rule['delivery_template'] ?? ''));
        $usesTaskAutoDelivery = $taskAutoTemplate !== ''
            && (($rule['delivery_mode'] ?? 'template') !== 'template' || $customDeliveryTemplate === '');

        if (!$usesTaskAutoDelivery && ($rule['delivery_mode'] ?? 'template') === 'same_as_source' && empty($rule['overwrite_allowed']) && trim((string) ($rule['output_suffix'] ?? '')) === '') {
            $errors[] = 'Same-as-source delivery without overwrite requires an output suffix.';
        }

        $errors = array_merge($errors, $this->templateValidationErrors(
            (string) ($rule['source_template'] ?? '{worker_path}'),
            'Source template'
        ));

        if ($usesTaskAutoDelivery) {
            $errors = array_merge($errors, $this->templateValidationErrors($taskAutoTemplate, 'Task automatic delivery template'));
            $requiredExtension = $this->taskDeliveryExtension($taskDelivery);
            if (!$this->templateEndsWithExtension($taskAutoTemplate, $requiredExtension)) {
                $errors[] = 'Task automatic delivery template for ' . (string) ($rule['module'] ?? '') . ' must end with ' . $requiredExtension . '.';
            }
        } elseif (($rule['delivery_mode'] ?? 'template') === 'template') {
            $errors = array_merge($errors, $this->templateValidationErrors(
                $customDeliveryTemplate,
                'Delivery template'
            ));
            $requiredExtension = $this->taskDeliveryExtension($taskDelivery);
            if ($customDeliveryTemplate !== '' && !$this->templateEndsWithExtension($customDeliveryTemplate, $requiredExtension)) {
                $errors[] = (string) ($rule['module'] ?? 'Task') . ' delivery template must end with ' . $requiredExtension . ', or leave it blank to use the task automatic template.';
            }
        }
        if (($rule['command_filter_mode'] ?? 'disabled') !== 'disabled') {
            $errors = array_merge($errors, $this->templateValidationErrors(
                (string) ($rule['command_filter_command'] ?? ''),
                'Command template',
                $this->validCommandTemplatePlaceholders()
            ));
        }

        return $errors;
    }

    public function testRule(array $rule, ?string $samplePaths = null, int $limit = 50): array
    {
        $rule = $this->normalizeRule($rule);
        $rows = [];
        $candidates = $samplePaths !== null && trim($samplePaths) !== ''
            ? $this->sampleCandidates($samplePaths)
            : $this->scanCandidates($rule, $limit);

        foreach ($candidates as $candidate) {
            if (count($rows) >= $limit) {
                break;
            }
            $candidate = $this->decorateCandidateForRule($rule, $candidate);
            $result = $this->evaluateCandidate($rule, $candidate);
            $source = $result['include'] ? $this->buildSource($rule, $candidate) : '';
            $delivery = $result['include'] ? $this->buildDelivery($rule, $candidate, $source) : '';
            $rows[] = array_merge($candidate, $result, [
                'source' => $source,
                'delivery' => $delivery,
            ]);
        }

        return [
            'rows' => $rows,
            'scanned' => count($candidates),
            'matched' => count(array_filter($rows, static function (array $row): bool { return !empty($row['include']); })),
        ];
    }

    public function runRule(array $rule, FarmStore $farmStore, bool $dryRun = false): array
    {
        $rule = $this->normalizeRule($rule);
        $startedAt = gmdate(DATE_ATOM);
        $candidates = $this->scanCandidates($rule, (int) ($rule['max_files_per_scan'] ?? 500));
        $summary = [
            'rule_id' => (string) ($rule['id'] ?? ''),
            'rule_name' => (string) ($rule['name'] ?? ''),
            'started_at' => $startedAt,
            'finished_at' => null,
            'dry_run' => $dryRun,
            'scanned' => 0,
            'matched' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
            'rows' => [],
        ];

        $maxJobs = max(0, (int) ($rule['max_jobs_per_scan'] ?? 25));
        foreach ($candidates as $candidate) {
            $summary['scanned']++;
            $candidate = $this->decorateCandidateForRule($rule, $candidate);
            $evaluation = $this->evaluateCandidate($rule, $candidate);
            if (empty($evaluation['include'])) {
                $summary['skipped']++;
                $this->appendRunRow($summary, $candidate, $evaluation, 'skipped');
                continue;
            }

            $summary['matched']++;
            $source = $this->buildSource($rule, $candidate);
            $delivery = $this->buildDelivery($rule, $candidate, $source);
            $delivery = $delivery !== '' ? $delivery : null;
            $fingerprint = $this->candidateFingerprint($candidate);

            if (!$this->shouldQueue($rule, $candidate, $source, $farmStore, $fingerprint, $reason)) {
                $summary['skipped']++;
                $this->appendRunRow($summary, $candidate, ['include' => false, 'reason' => $reason], 'duplicate');
                continue;
            }

            if ($summary['queued'] >= $maxJobs) {
                $summary['skipped']++;
                $this->appendRunRow($summary, $candidate, ['include' => false, 'reason' => 'Reached max jobs per scan.'], 'limit');
                continue;
            }

            if ($dryRun) {
                $summary['queued']++;
                $this->appendRunRow($summary, $candidate, array_merge($evaluation, ['source' => $source, 'delivery' => $delivery]), 'would_queue');
                continue;
            }

            try {
                $job = $farmStore->createJob(
                    (string) ($rule['module'] ?? ''),
                    $source,
                    $delivery,
                    !empty($rule['overwrite_allowed']),
                    array_filter([
                        'automation_rule_id' => (string) ($rule['id'] ?? ''),
                        'automation_rule_name' => (string) ($rule['name'] ?? ''),
                        'automation_fingerprint' => $fingerprint,
                        'transfer_server_id' => trim((string) ($rule['transfer_server_id'] ?? '')),
                    ], static function ($value): bool {
                        return $value !== '';
                    })
                );
                $this->recordQueuedState($rule, $candidate, $source, $delivery, $fingerprint, $job);
                $summary['queued']++;
                $this->appendRunRow($summary, $candidate, array_merge($evaluation, ['source' => $source, 'delivery' => $delivery, 'task_id' => $job['task_id'] ?? '']), 'queued');
            } catch (Throwable $exception) {
                $summary['errors']++;
                $this->appendRunRow($summary, $candidate, ['include' => false, 'reason' => $exception->getMessage()], 'error');
            }
        }

        $summary['finished_at'] = gmdate(DATE_ATOM);
        $this->recordRun($summary);
        if (!$dryRun) {
            $this->markRuleScanned((string) ($rule['id'] ?? ''), $summary);
        }

        return $summary;
    }

    public function runDueRules(FarmStore $farmStore, bool $dryRun = false): array
    {
        $results = [];
        foreach ($this->rules() as $rule) {
            if (empty($rule['enabled']) || !$this->ruleIsDue($rule)) {
                continue;
            }
            $results[] = $this->runRule($rule, $farmStore, $dryRun);
        }

        return $results;
    }

    public function runDueRulesForWorkerCheckin(FarmStore $farmStore, bool $dryRun = false, int $cooldownSeconds = 60): array
    {
        if ($dryRun) {
            return $this->runDueRules($farmStore, true);
        }

        $cooldownSeconds = max(0, $cooldownSeconds);
        $lockHandle = @fopen($this->dueCheckLockPath, 'c+');
        if ($lockHandle === false) {
            // If the coordination lock cannot be opened, fall back to the
            // normal due-rule logic rather than preventing workers from using
            // the farm. The error will be visible in the PHP error log.
            error_log('Reflection automation due-check lock could not be opened: ' . $this->dueCheckLockPath);
            return $this->runDueRules($farmStore, false);
        }

        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return [[
                'status' => 'skipped',
                'reason' => 'automation_check_already_running',
                'trigger' => 'worker_checkin',
                'started_at' => gmdate(DATE_ATOM),
            ]];
        }

        try {
            $now = time();
            $state = $this->readJson($this->dueCheckStatePath, []);
            $lastFinished = strtotime((string) ($state['last_finished_at'] ?? ''));
            $lastStarted = strtotime((string) ($state['last_started_at'] ?? ''));
            $lastCheck = $lastFinished !== false ? $lastFinished : ($lastStarted !== false ? $lastStarted : null);

            if ($cooldownSeconds > 0 && $lastCheck !== null && ($now - $lastCheck) < $cooldownSeconds) {
                return [[
                    'status' => 'skipped',
                    'reason' => 'automation_check_cooldown',
                    'trigger' => 'worker_checkin',
                    'cooldown_seconds' => $cooldownSeconds,
                    'seconds_remaining' => max(0, $cooldownSeconds - ($now - $lastCheck)),
                    'last_finished_at' => (string) ($state['last_finished_at'] ?? ''),
                ]];
            }

            $state['last_started_at'] = gmdate(DATE_ATOM);
            $state['last_trigger'] = 'worker_checkin';
            $state['last_status'] = 'running';
            $this->atomicWriteJson($this->dueCheckStatePath, $state);

            try {
                $results = $this->runDueRules($farmStore, false);
                $state['last_finished_at'] = gmdate(DATE_ATOM);
                $state['last_status'] = 'complete';
                $state['last_result_count'] = count(array_filter($results, static function ($result): bool {
                    return is_array($result) && (($result['status'] ?? '') !== 'skipped');
                }));
                $state['last_error'] = '';
                $this->atomicWriteJson($this->dueCheckStatePath, $state);
                return $results;
            } catch (Throwable $exception) {
                $state['last_finished_at'] = gmdate(DATE_ATOM);
                $state['last_status'] = 'failed';
                $state['last_error'] = $this->limitString($exception->getMessage(), 500);
                $this->atomicWriteJson($this->dueCheckStatePath, $state);
                throw $exception;
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function ruleIsDue(array $rule): bool
    {
        $rule = $this->normalizeRule($rule);
        $last = (string) ($rule['last_scan_at'] ?? '');
        if ($last === '') {
            return true;
        }
        $lastTime = strtotime($last);
        if ($lastTime === false) {
            return true;
        }
        $interval = max(1, (int) ($rule['scan_interval_minutes'] ?? 60)) * 60;
        return (time() - $lastTime) >= $interval;
    }

    public function recentRuns(int $limit = 20): array
    {
        $lines = $this->tailLines($this->runLogPath, max(0, $limit));
        $runs = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $runs[] = $decoded;
            }
        }
        return array_reverse($runs);
    }

    public function normalizeRule(array $input): array
    {
        $rule = $this->newRuleDefaults();
        foreach ($input as $key => $value) {
            if (array_key_exists($key, $rule)) {
                $rule[$key] = $value;
            }
        }

        $rule['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($rule['id'] ?? '')) ?: '';
        $rule['name'] = trim((string) ($rule['name'] ?? ''));
        $rule['enabled'] = !empty($rule['enabled']);
        $rule['module'] = trim((string) ($rule['module'] ?? ''));
        $rule['scan_roots'] = $this->cleanLines($rule['scan_roots'] ?? []);
        $rule['recursive'] = !empty($rule['recursive']);
        $rule['worker_path_mappings'] = implode("\n", $this->cleanLines($rule['worker_path_mappings'] ?? []));
        $rule['transfer_server_id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($rule['transfer_server_id'] ?? '')) ?: '';
        $rule['source_template'] = trim((string) ($rule['source_template'] ?? '{worker_path}')) ?: '{worker_path}';
        $rule['delivery_mode'] = in_array((string) ($rule['delivery_mode'] ?? 'template'), ['template', 'same_as_source'], true)
            ? (string) ($rule['delivery_mode'] ?? 'template')
            : 'template';
        $rule['delivery_template'] = trim((string) ($rule['delivery_template'] ?? ''));
        $rule['output_suffix'] = $this->normalizeOutputSuffix($rule['output_suffix'] ?? '_processed');
        $rule['overwrite_allowed'] = !empty($rule['overwrite_allowed']);
        $rule['extensions'] = $this->cleanCommaList((string) ($rule['extensions'] ?? ''));
        $rule['include_globs'] = implode("\n", $this->cleanLines($rule['include_globs'] ?? []));
        $rule['exclude_globs'] = implode("\n", $this->cleanLines($rule['exclude_globs'] ?? []));
        $rule['include_regex'] = trim((string) ($rule['include_regex'] ?? ''));
        $rule['exclude_regex'] = trim((string) ($rule['exclude_regex'] ?? ''));
        $rule['min_size_mb'] = $this->normalizeOptionalNumber($rule['min_size_mb'] ?? '');
        $rule['max_size_mb'] = $this->normalizeOptionalNumber($rule['max_size_mb'] ?? '');
        $rule['require_unchanged_seconds'] = max(0, (int) ($rule['require_unchanged_seconds'] ?? 0));
        $rule['command_filter_mode'] = (string) ($rule['command_filter_mode'] ?? 'disabled');
        $rule['command_filter_command'] = trim((string) ($rule['command_filter_command'] ?? ''));
        $rule['command_filter_regex'] = trim((string) ($rule['command_filter_regex'] ?? ''));
        $rule['command_timeout_seconds'] = max(1, min(3600, (int) ($rule['command_timeout_seconds'] ?? 20)));
        $rule['max_files_per_scan'] = max(1, min(100000, (int) ($rule['max_files_per_scan'] ?? 500)));
        $rule['max_jobs_per_scan'] = max(0, min(10000, (int) ($rule['max_jobs_per_scan'] ?? 25)));
        $rule['scan_interval_minutes'] = max(1, min(10080, (int) ($rule['scan_interval_minutes'] ?? 60)));
        $rule['requeue_unchanged'] = !empty($rule['requeue_unchanged']);
        $rule['state_keep_paths'] = max(0, min(500000, (int) ($rule['state_keep_paths'] ?? 10000)));
        $rule['last_scan_at'] = $this->cleanOptionalString($rule['last_scan_at'] ?? null);
        $rule['last_scan_summary'] = is_array($rule['last_scan_summary'] ?? null) ? $rule['last_scan_summary'] : [];
        $rule['created_at'] = $this->cleanOptionalString($rule['created_at'] ?? null);
        $rule['updated_at'] = $this->cleanOptionalString($rule['updated_at'] ?? null);

        return $rule;
    }

    private function newRuleDefaults(): array
    {
        return [
            'id' => '',
            'name' => '',
            'enabled' => false,
            'module' => 'dummy_task',
            'scan_roots' => [],
            'recursive' => true,
            'worker_path_mappings' => '/volume1 => /',
            'transfer_server_id' => '',
            'source_template' => '{worker_path}',
            'delivery_mode' => 'template',
            'delivery_template' => '',
            'output_suffix' => '_processed',
            'overwrite_allowed' => false,
            'extensions' => '',
            'include_globs' => '',
            'exclude_globs' => '',
            'include_regex' => '',
            'exclude_regex' => '',
            'min_size_mb' => '',
            'max_size_mb' => '',
            'require_unchanged_seconds' => 120,
            'command_filter_mode' => 'disabled',
            'command_filter_command' => '',
            'command_filter_regex' => '',
            'command_timeout_seconds' => 20,
            'max_files_per_scan' => 500,
            'max_jobs_per_scan' => 25,
            'scan_interval_minutes' => 60,
            'requeue_unchanged' => false,
            'state_keep_paths' => 10000,
            'last_scan_at' => null,
            'last_scan_summary' => [],
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function sampleCandidates(string $samplePaths): array
    {
        $paths = $this->cleanLines($samplePaths);
        $candidates = [];
        foreach ($paths as $path) {
            $path = $this->normalizePath($path);
            $stat = @stat($path);
            $candidates[] = $this->candidateFromPath($path, dirname($path), $stat !== false ? $stat : null);
        }
        return $candidates;
    }

    private function scanCandidates(array $rule, int $limit): array
    {
        $candidates = [];
        foreach (($rule['scan_roots'] ?? []) as $root) {
            if (count($candidates) >= $limit) {
                break;
            }
            $root = $this->normalizePath((string) $root);
            if ($root === '' || !file_exists($root)) {
                continue;
            }

            if (is_file($root)) {
                $stat = @stat($root);
                $candidates[] = $this->candidateFromPath($root, dirname($root), $stat !== false ? $stat : null);
                continue;
            }

            if (!is_dir($root)) {
                continue;
            }

            if (!empty($rule['recursive'])) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $fileInfo) {
                    if (count($candidates) >= $limit) {
                        break 2;
                    }
                    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->isLink()) {
                        continue;
                    }
                    $candidates[] = $this->candidateFromPath($fileInfo->getPathname(), $root, null);
                }
            } else {
                $iterator = new DirectoryIterator($root);
                foreach ($iterator as $fileInfo) {
                    if (count($candidates) >= $limit) {
                        break 2;
                    }
                    if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                        continue;
                    }
                    $candidates[] = $this->candidateFromPath($fileInfo->getPathname(), $root, null);
                }
            }
        }

        return $candidates;
    }

    private function candidateFromPath(string $path, string $root, ?array $stat): array
    {
        $path = $this->normalizePath($path);
        $root = $this->normalizePath($root);
        $basename = basename($path);
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $name = $extension !== '' ? substr($basename, 0, -strlen($extension) - 1) : $basename;
        $dir = $this->normalizePath(dirname($path));
        $relative = $this->relativePath($path, $root);
        $size = $stat !== null ? (int) ($stat['size'] ?? 0) : (is_file($path) ? (int) filesize($path) : 0);
        $mtime = $stat !== null ? (int) ($stat['mtime'] ?? 0) : (file_exists($path) ? (int) filemtime($path) : 0);

        return [
            'path' => $path,
            'root' => $root,
            'relative' => $relative,
            'dir' => $dir,
            'basename' => $basename,
            'name' => $name,
            'ext' => $extension,
            'size' => $size,
            'mtime' => $mtime,
            'worker_path' => $path,
            'worker_root' => $root,
            'worker_relative' => $relative,
            'worker_dir' => $dir,
            'worker_basename' => $basename,
            'worker_name' => $name,
            'worker_ext' => $extension,
        ];
    }

    private function evaluateCandidate(array $rule, array $candidate): array
    {
        $extensionList = $this->extensionsArray((string) ($rule['extensions'] ?? ''));
        $ext = strtolower((string) ($candidate['ext'] ?? ''));
        if ($extensionList !== [] && !in_array($ext, $extensionList, true)) {
            return ['include' => false, 'reason' => 'Extension is not included.'];
        }

        $minSize = $this->mbToBytes($rule['min_size_mb'] ?? '');
        $maxSize = $this->mbToBytes($rule['max_size_mb'] ?? '');
        $size = (int) ($candidate['size'] ?? 0);
        if ($minSize !== null && $size < $minSize) {
            return ['include' => false, 'reason' => 'File is smaller than the minimum size.'];
        }
        if ($maxSize !== null && $size > $maxSize) {
            return ['include' => false, 'reason' => 'File is larger than the maximum size.'];
        }

        $unchangedSeconds = max(0, (int) ($rule['require_unchanged_seconds'] ?? 0));
        $mtime = (int) ($candidate['mtime'] ?? 0);
        if ($unchangedSeconds > 0 && $mtime > 0 && (time() - $mtime) < $unchangedSeconds) {
            return ['include' => false, 'reason' => 'File changed too recently.'];
        }

        $path = (string) ($candidate['path'] ?? '');
        $relative = (string) ($candidate['relative'] ?? '');
        $basename = (string) ($candidate['basename'] ?? '');

        $includeGlobs = $this->cleanLines($rule['include_globs'] ?? []);
        if ($includeGlobs !== [] && !$this->matchesAnyGlob($includeGlobs, $path, $relative, $basename)) {
            return ['include' => false, 'reason' => 'Did not match include glob.'];
        }

        $excludeGlobs = $this->cleanLines($rule['exclude_globs'] ?? []);
        if ($excludeGlobs !== [] && $this->matchesAnyGlob($excludeGlobs, $path, $relative, $basename)) {
            return ['include' => false, 'reason' => 'Matched exclude glob.'];
        }

        $includeRegex = trim((string) ($rule['include_regex'] ?? ''));
        if ($includeRegex !== '' && @preg_match($includeRegex, $path . "\n" . $relative . "\n" . $basename) !== 1) {
            return ['include' => false, 'reason' => 'Did not match include regex.'];
        }

        $excludeRegex = trim((string) ($rule['exclude_regex'] ?? ''));
        if ($excludeRegex !== '' && @preg_match($excludeRegex, $path . "\n" . $relative . "\n" . $basename) === 1) {
            return ['include' => false, 'reason' => 'Matched exclude regex.'];
        }

        $commandResult = $this->evaluateCommandFilter($rule, $candidate);
        if (!$commandResult['include']) {
            return $commandResult;
        }

        return ['include' => true, 'reason' => 'Matched all filters.'];
    }

    private function evaluateCommandFilter(array $rule, array $candidate): array
    {
        $mode = (string) ($rule['command_filter_mode'] ?? 'disabled');
        $command = trim((string) ($rule['command_filter_command'] ?? ''));
        if ($mode === 'disabled' || $command === '') {
            return ['include' => true, 'reason' => 'Command filter disabled.'];
        }

        $builtCommand = $this->applyCommandTemplate($command, $candidate, (string) ($rule['module'] ?? ''));
        $result = $this->runCommand($builtCommand, (int) ($rule['command_timeout_seconds'] ?? 20));
        $output = trim((string) ($result['output'] ?? ''));
        $exitCode = (int) ($result['exit_code'] ?? 1);

        if (!empty($result['timed_out'])) {
            return ['include' => false, 'reason' => 'Command filter timed out.', 'command_output' => $output];
        }

        if ($mode === 'exit_zero') {
            return [
                'include' => $exitCode === 0,
                'reason' => $exitCode === 0 ? 'Command exited with 0.' : 'Command exited with ' . $exitCode . '.',
                'command_output' => $output,
            ];
        }

        if ($exitCode !== 0) {
            return ['include' => false, 'reason' => 'Command exited with ' . $exitCode . '.', 'command_output' => $output];
        }

        $regex = trim((string) ($rule['command_filter_regex'] ?? ''));
        if ($regex === '' || !$this->regexIsValid($regex)) {
            return ['include' => false, 'reason' => 'Command output regex is invalid or blank.', 'command_output' => $output];
        }

        $matches = @preg_match($regex, $output) === 1;
        if ($mode === 'output_matches') {
            return ['include' => $matches, 'reason' => $matches ? 'Command output matched.' : 'Command output did not match.', 'command_output' => $output];
        }

        if ($mode === 'output_not_matches') {
            return ['include' => !$matches, 'reason' => !$matches ? 'Command output did not match.' : 'Command output matched excluded output.', 'command_output' => $output];
        }

        return ['include' => false, 'reason' => 'Unknown command filter mode.', 'command_output' => $output];
    }

    private function shouldQueue(array $rule, array $candidate, string $source, FarmStore $farmStore, string $fingerprint, ?string &$reason): bool
    {
        if (!empty($rule['requeue_unchanged'])) {
            $reason = null;
            return true;
        }

        if ($farmStore->hasOpenJob((string) ($rule['module'] ?? ''), $source)) {
            $reason = 'A queued, running, or crash-blocked job already exists for this task/source.';
            return false;
        }

        $state = $this->readState();
        $ruleId = (string) ($rule['id'] ?? '');
        $pathKey = sha1((string) ($candidate['path'] ?? ''));
        $entry = $state['rules'][$ruleId]['paths'][$pathKey] ?? null;
        if (is_array($entry) && ($entry['fingerprint'] ?? '') === $fingerprint) {
            $reason = 'This unchanged file was already queued by this automation rule.';
            return false;
        }

        $reason = null;
        return true;
    }

    private function recordQueuedState(array $rule, array $candidate, string $source, ?string $delivery, string $fingerprint, array $job): void
    {
        $ruleId = (string) ($rule['id'] ?? '');
        if ($ruleId === '') {
            return;
        }

        $this->withLock(function () use ($rule, $candidate, $source, $delivery, $fingerprint, $job, $ruleId): void {
            $state = $this->readState();
            if (!isset($state['rules'][$ruleId]) || !is_array($state['rules'][$ruleId])) {
                $state['rules'][$ruleId] = ['paths' => []];
            }
            if (!isset($state['rules'][$ruleId]['paths']) || !is_array($state['rules'][$ruleId]['paths'])) {
                $state['rules'][$ruleId]['paths'] = [];
            }

            $pathKey = sha1((string) ($candidate['path'] ?? ''));
            $state['rules'][$ruleId]['paths'][$pathKey] = [
                'path' => (string) ($candidate['path'] ?? ''),
                'fingerprint' => $fingerprint,
                'source' => $source,
                'delivery' => $delivery,
                'task_id' => $job['task_id'] ?? null,
                'queued_at' => gmdate(DATE_ATOM),
                'size' => (int) ($candidate['size'] ?? 0),
                'mtime' => (int) ($candidate['mtime'] ?? 0),
            ];

            $keep = max(0, (int) ($rule['state_keep_paths'] ?? 10000));
            if ($keep === 0) {
                $state['rules'][$ruleId]['paths'] = [];
            } elseif (count($state['rules'][$ruleId]['paths']) > $keep) {
                uasort($state['rules'][$ruleId]['paths'], static function (array $a, array $b): int {
                    return strcmp((string) ($b['queued_at'] ?? ''), (string) ($a['queued_at'] ?? ''));
                });
                $state['rules'][$ruleId]['paths'] = array_slice($state['rules'][$ruleId]['paths'], 0, $keep, true);
            }

            $this->atomicWriteJson($this->statePath, $state);
        });
    }

    private function markRuleScanned(string $ruleId, array $summary): void
    {
        if ($ruleId === '') {
            return;
        }
        $this->withLock(function () use ($ruleId, $summary): void {
            $data = $this->readJson($this->rulesPath, ['rules' => []]);
            foreach ($data['rules'] as &$rule) {
                if (is_array($rule) && ($rule['id'] ?? '') === $ruleId) {
                    $rule = $this->normalizeRule($rule);
                    $rule['last_scan_at'] = (string) ($summary['finished_at'] ?? gmdate(DATE_ATOM));
                    $rule['last_scan_summary'] = [
                        'scanned' => (int) ($summary['scanned'] ?? 0),
                        'matched' => (int) ($summary['matched'] ?? 0),
                        'queued' => (int) ($summary['queued'] ?? 0),
                        'skipped' => (int) ($summary['skipped'] ?? 0),
                        'errors' => (int) ($summary['errors'] ?? 0),
                    ];
                    break;
                }
            }
            unset($rule);
            $this->atomicWriteJson($this->rulesPath, ['rules' => array_values($data['rules'] ?? [])]);
        });
    }

    private function appendRunRow(array &$summary, array $candidate, array $evaluation, string $status): void
    {
        if (count($summary['rows']) >= 40) {
            return;
        }
        $summary['rows'][] = [
            'status' => $status,
            'path' => (string) ($candidate['path'] ?? ''),
            'reason' => (string) ($evaluation['reason'] ?? ''),
            'source' => (string) ($evaluation['source'] ?? ''),
            'delivery' => (string) ($evaluation['delivery'] ?? ''),
            'task_id' => (string) ($evaluation['task_id'] ?? ''),
            'command_output' => $this->limitString((string) ($evaluation['command_output'] ?? ''), 250),
        ];
    }

    private function recordRun(array $summary): void
    {
        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            @file_put_contents($this->runLogPath, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function removeRuleState(string $id): void
    {
        $state = $this->readState();
        if (isset($state['rules'][$id])) {
            unset($state['rules'][$id]);
            $this->atomicWriteJson($this->statePath, $state);
        }
    }

    private function readState(): array
    {
        $state = $this->readJson($this->statePath, ['rules' => []]);
        if (!isset($state['rules']) || !is_array($state['rules'])) {
            $state['rules'] = [];
        }
        return $state;
    }

    private function extensionsArray(string $extensions): array
    {
        if (trim($extensions) === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', strtolower($extensions)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " .\t\n\r\0\x0B");
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    private function matchesAnyGlob(array $patterns, string $path, string $relative, string $basename): bool
    {
        foreach ($patterns as $pattern) {
            foreach ([$path, $relative, $basename] as $value) {
                $flags = defined('FNM_CASEFOLD') ? FNM_CASEFOLD : 0;
                if (fnmatch($pattern, $value, $flags)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function buildSource(array $rule, array $candidate): string
    {
        $source = $this->applyPathTemplate((string) ($rule['source_template'] ?? '{worker_path}'), $candidate);
        return $source !== '' ? $source : (string) ($candidate['path'] ?? '');
    }

    private function buildDelivery(array $rule, array $candidate, string $source): string
    {
        $taskDelivery = $this->taskDeliverySpec((string) ($rule['module'] ?? ''));
        $taskAutoTemplate = $this->taskAutoDeliveryTemplate($taskDelivery);
        $customDeliveryTemplate = trim((string) ($rule['delivery_template'] ?? ''));

        if ($taskAutoTemplate !== '' && (($rule['delivery_mode'] ?? 'template') !== 'template' || $customDeliveryTemplate === '')) {
            return $this->applyPathTemplateToPath($taskAutoTemplate, $source);
        }

        if (($rule['delivery_mode'] ?? 'template') === 'same_as_source') {
            if (!empty($rule['overwrite_allowed'])) {
                return $source;
            }
            return $this->siblingPathWithSuffix($source, (string) ($rule['output_suffix'] ?? '_processed'));
        }

        return $this->applyPathTemplate($customDeliveryTemplate, $candidate);
    }

    private function taskDeliverySpec(string $module): array
    {
        $spec = $this->taskSpecs[$module]['delivery'] ?? [];
        return is_array($spec) ? $spec : [];
    }

    private function taskAutoDeliveryTemplate(array $deliverySpec): string
    {
        $mode = strtolower((string) ($deliverySpec['mode'] ?? ''));
        $template = trim((string) ($deliverySpec['template'] ?? ''));
        return $mode === 'auto' && $template !== '' ? $template : '';
    }

    private function taskDeliveryExtension(array $deliverySpec): string
    {
        $extension = trim((string) ($deliverySpec['extension'] ?? ''));
        if ($extension === '' || $extension === 'source') {
            return '';
        }
        return $extension[0] === '.' ? strtolower($extension) : '.' . strtolower($extension);
    }

    private function templateEndsWithExtension(string $template, string $extension): bool
    {
        $extension = strtolower(trim($extension));
        if ($extension === '') {
            return true;
        }
        $template = strtolower(trim($template));
        return substr($template, -strlen($extension)) === $extension;
    }

    private function pathTemplateParts(string $pathOrUri): array
    {
        $source = str_replace('\\', '/', trim($pathOrUri));
        $sourceForName = rtrim($source, '/');
        $slash = strrpos($sourceForName, '/');
        $directory = $slash === false ? '' : substr($sourceForName, 0, $slash);
        $basename = $slash === false ? $sourceForName : substr($sourceForName, $slash + 1);
        if ($basename === '') {
            $basename = 'output';
        }

        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $name = $extension !== '' ? substr($basename, 0, -strlen($extension) - 1) : $basename;
        $dotExtension = $extension !== '' ? '.' . $extension : '';

        return [
            'source' => $source,
            'path' => $source,
            'root' => '',
            'relative' => $basename,
            'dir' => $directory,
            'directory' => $directory,
            'basename' => $basename,
            'name' => $name !== '' ? $name : $basename,
            'ext' => $extension,
            'dot_ext' => $dotExtension,
            'mtime' => '',
            'size' => '',
            'worker_path' => $source,
            'worker_root' => '',
            'worker_relative' => $basename,
            'worker_dir' => $directory,
            'worker_basename' => $basename,
            'worker_name' => $name !== '' ? $name : $basename,
            'worker_ext' => $extension,
            'worker_dot_ext' => $dotExtension,
        ];
    }

    private function applyPathTemplateToPath(string $template, string $pathOrUri): string
    {
        $rendered = $this->applyPathTemplate($template, $this->pathTemplateParts($pathOrUri));
        if ($rendered !== '' && preg_match('#^\s*\{(?:dir|directory)\}/#', $template) === 1 && strpos($pathOrUri, '/') === false) {
            $rendered = ltrim($rendered, '/');
        }
        return preg_replace('#(?<!:)//+#', '/', $rendered) ?? $rendered;
    }

    private function siblingPathWithSuffix(string $pathOrUri, string $suffix): string
    {
        $suffix = $this->normalizeOutputSuffix($suffix);
        if ($suffix === '') {
            $suffix = '_processed';
        }

        $parts = @parse_url($pathOrUri);
        if (is_array($parts) && isset($parts['scheme']) && isset($parts['path'])) {
            $newPath = $this->appendSuffixToPath((string) ($parts['path'] ?? ''), $suffix);
            $rebuilt = (string) $parts['scheme'] . '://';
            if (isset($parts['user'])) {
                $rebuilt .= rawurlencode((string) $parts['user']);
                if (isset($parts['pass'])) {
                    $rebuilt .= ':' . rawurlencode((string) $parts['pass']);
                }
                $rebuilt .= '@';
            }
            $rebuilt .= (string) ($parts['host'] ?? '');
            if (isset($parts['port'])) {
                $rebuilt .= ':' . (string) $parts['port'];
            }
            $rebuilt .= $newPath;
            if (isset($parts['query'])) {
                $rebuilt .= '?' . (string) $parts['query'];
            }
            if (isset($parts['fragment'])) {
                $rebuilt .= '#' . (string) $parts['fragment'];
            }
            return $rebuilt;
        }

        return $this->appendSuffixToPath($pathOrUri, $suffix);
    }

    private function appendSuffixToPath(string $path, string $suffix): string
    {
        $directory = dirname($path);
        $basename = basename($path);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $name = $extension !== '' ? substr($basename, 0, -strlen($extension) - 1) : $basename;
        $newName = $name . $suffix . ($extension !== '' ? '.' . $extension : '');
        return ($directory === '' || $directory === '.') ? $newName : rtrim($directory, '/') . '/' . $newName;
    }

    private function validTemplatePlaceholders(): array
    {
        return [
            'source', 'path', 'root', 'relative', 'dir', 'directory', 'basename', 'name', 'ext', 'dot_ext', 'mtime', 'size',
            'worker_path', 'worker_root', 'worker_relative', 'worker_dir', 'worker_basename', 'worker_name', 'worker_ext', 'worker_dot_ext',
        ];
    }

    private function validCommandTemplatePlaceholders(): array
    {
        return array_values(array_unique(array_merge($this->validTemplatePlaceholders(), [
            'farm_root', 'task_dir', 'task_file',
        ])));
    }

    private function templateValidationErrors(string $template, string $label, ?array $validPlaceholders = null): array
    {
        $template = trim($template);
        if ($template === '') {
            return [];
        }

        // Be defensive here: command templates have their own placeholder set.
        // The save path passes that set explicitly, but this label-based fallback
        // prevents future call sites from accidentally validating command filters
        // against path-only placeholders and rejecting {task_file}.
        if ($validPlaceholders === null && strtolower($label) === 'command template') {
            $validPlaceholders = $this->validCommandTemplatePlaceholders();
        }

        $errors = [];
        if (substr_count($template, '{') !== substr_count($template, '}')) {
            $errors[] = $label . ' has unmatched curly braces.';
        }

        preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        $valid = array_flip($validPlaceholders ?? $this->validTemplatePlaceholders());
        foreach (($matches[1] ?? []) as $placeholder) {
            if (!isset($valid[$placeholder])) {
                $errors[] = $label . ' contains unknown placeholder {' . $placeholder . '}.';
            }
        }

        return array_values(array_unique($errors));
    }

    private function applyPathTemplate(string $template, array $candidate): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        return strtr($template, [
            '{source}' => (string) ($candidate['source'] ?? ($candidate['path'] ?? '')),
            '{path}' => (string) ($candidate['path'] ?? ''),
            '{root}' => (string) ($candidate['root'] ?? ''),
            '{relative}' => (string) ($candidate['relative'] ?? ''),
            '{dir}' => (string) ($candidate['dir'] ?? ''),
            '{directory}' => (string) ($candidate['dir'] ?? ''),
            '{basename}' => (string) ($candidate['basename'] ?? ''),
            '{name}' => (string) ($candidate['name'] ?? ''),
            '{ext}' => (string) ($candidate['ext'] ?? ''),
            '{dot_ext}' => (string) (($candidate['ext'] ?? '') !== '' ? '.' . ($candidate['ext'] ?? '') : ''),
            '{mtime}' => (string) ($candidate['mtime'] ?? ''),
            '{size}' => (string) ($candidate['size'] ?? ''),
            '{worker_path}' => (string) ($candidate['worker_path'] ?? ($candidate['path'] ?? '')),
            '{worker_root}' => (string) ($candidate['worker_root'] ?? ($candidate['root'] ?? '')),
            '{worker_relative}' => (string) ($candidate['worker_relative'] ?? ($candidate['relative'] ?? '')),
            '{worker_dir}' => (string) ($candidate['worker_dir'] ?? ($candidate['dir'] ?? '')),
            '{worker_basename}' => (string) ($candidate['worker_basename'] ?? ($candidate['basename'] ?? '')),
            '{worker_name}' => (string) ($candidate['worker_name'] ?? ($candidate['name'] ?? '')),
            '{worker_ext}' => (string) ($candidate['worker_ext'] ?? ($candidate['ext'] ?? '')),
            '{worker_dot_ext}' => (string) (($candidate['worker_ext'] ?? ($candidate['ext'] ?? '')) !== '' ? '.' . ($candidate['worker_ext'] ?? ($candidate['ext'] ?? '')) : ''),
        ]);
    }

    private function applyCommandTemplate(string $template, array $candidate, string $module = ''): string
    {
        $farmRoot = __DIR__;
        $taskDir = $farmRoot . DIRECTORY_SEPARATOR . 'cluster' . DIRECTORY_SEPARATOR . 'tasks';
        $taskFile = $module !== '' ? $taskDir . DIRECTORY_SEPARATOR . basename($module) . '.py' : '';

        return strtr($template, [
            '{source}' => escapeshellarg((string) ($candidate['source'] ?? ($candidate['path'] ?? ''))),
            '{path}' => escapeshellarg((string) ($candidate['path'] ?? '')),
            '{root}' => escapeshellarg((string) ($candidate['root'] ?? '')),
            '{relative}' => escapeshellarg((string) ($candidate['relative'] ?? '')),
            '{dir}' => escapeshellarg((string) ($candidate['dir'] ?? '')),
            '{directory}' => escapeshellarg((string) ($candidate['dir'] ?? '')),
            '{basename}' => escapeshellarg((string) ($candidate['basename'] ?? '')),
            '{name}' => escapeshellarg((string) ($candidate['name'] ?? '')),
            '{ext}' => escapeshellarg((string) ($candidate['ext'] ?? '')),
            '{dot_ext}' => escapeshellarg((string) (($candidate['ext'] ?? '') !== '' ? '.' . ($candidate['ext'] ?? '') : '')),
            '{mtime}' => escapeshellarg((string) ($candidate['mtime'] ?? '')),
            '{size}' => escapeshellarg((string) ($candidate['size'] ?? '')),
            '{worker_path}' => escapeshellarg((string) ($candidate['worker_path'] ?? ($candidate['path'] ?? ''))),
            '{worker_root}' => escapeshellarg((string) ($candidate['worker_root'] ?? ($candidate['root'] ?? ''))),
            '{worker_relative}' => escapeshellarg((string) ($candidate['worker_relative'] ?? ($candidate['relative'] ?? ''))),
            '{worker_dir}' => escapeshellarg((string) ($candidate['worker_dir'] ?? ($candidate['dir'] ?? ''))),
            '{worker_basename}' => escapeshellarg((string) ($candidate['worker_basename'] ?? ($candidate['basename'] ?? ''))),
            '{worker_name}' => escapeshellarg((string) ($candidate['worker_name'] ?? ($candidate['name'] ?? ''))),
            '{worker_ext}' => escapeshellarg((string) ($candidate['worker_ext'] ?? ($candidate['ext'] ?? ''))),
            '{worker_dot_ext}' => escapeshellarg((string) (($candidate['worker_ext'] ?? ($candidate['ext'] ?? '')) !== '' ? '.' . ($candidate['worker_ext'] ?? ($candidate['ext'] ?? '')) : '')),
            '{farm_root}' => escapeshellarg($farmRoot),
            '{task_dir}' => escapeshellarg($taskDir),
            '{task_file}' => escapeshellarg($taskFile),
        ]);
    }

    private function runCommand(string $command, int $timeoutSeconds): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'output' => 'Unable to start command.', 'timed_out' => false];
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $output = '';
        $deadline = time() + max(1, $timeoutSeconds);
        $timedOut = false;
        while (true) {
            foreach ($pipes as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                    if (strlen($output) > 4096) {
                        $output = substr($output, -4096);
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }

        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipe);
        }
        $exitCode = proc_close($process);
        if ($timedOut) {
            $exitCode = 124;
        }

        return [
            'exit_code' => (int) $exitCode,
            'output' => $this->limitString(trim($output), 4096),
            'timed_out' => $timedOut,
        ];
    }

    private function decorateCandidateForRule(array $rule, array $candidate): array
    {
        $workerPath = $this->mapMasterPathToWorkerPath((string) ($candidate['path'] ?? ''), $rule);
        $workerRoot = $this->mapMasterPathToWorkerPath((string) ($candidate['root'] ?? ''), $rule);
        $workerRelative = $this->relativePath($workerPath, $workerRoot);
        $workerBasename = basename($workerPath);
        $workerExtension = strtolower(pathinfo($workerBasename, PATHINFO_EXTENSION));
        $workerName = $workerExtension !== '' ? substr($workerBasename, 0, -strlen($workerExtension) - 1) : $workerBasename;

        $candidate['worker_path'] = $workerPath;
        $candidate['worker_root'] = $workerRoot;
        $candidate['worker_relative'] = $workerRelative;
        $candidate['worker_dir'] = $this->normalizePath(dirname($workerPath));
        $candidate['worker_basename'] = $workerBasename;
        $candidate['worker_name'] = $workerName;
        $candidate['worker_ext'] = $workerExtension;

        return $candidate;
    }

    private function mapMasterPathToWorkerPath(string $path, array $rule): string
    {
        $path = $this->normalizePath($path);
        $mappings = $this->workerPathMappings($rule);
        if ($mappings === []) {
            return $path;
        }

        usort($mappings, static function (array $a, array $b): int {
            return strlen((string) $b['from']) <=> strlen((string) $a['from']);
        });

        foreach ($mappings as $mapping) {
            $from = rtrim((string) $mapping['from'], '/');
            $to = rtrim((string) $mapping['to'], '/');
            if ($from === '') {
                continue;
            }
            if ($path === $from || strpos($path, $from . '/') === 0) {
                $suffix = $path === $from ? '' : substr($path, strlen($from));
                if ($to === '') {
                    return ltrim($suffix, '/');
                }
                if ($to === '/') {
                    return '/' . ltrim($suffix, '/');
                }
                return $to . $suffix;
            }
        }

        return $path;
    }

    private function workerPathMappings(array $rule): array
    {
        $mappings = [];
        foreach ($this->cleanLines($rule['worker_path_mappings'] ?? []) as $line) {
            $mapping = $this->parseWorkerPathMappingLine($line);
            if ($mapping !== null) {
                $mappings[] = $mapping;
            }
        }
        return $mappings;
    }

    private function parseWorkerPathMappingLine(string $line): ?array
    {
        $line = trim(str_replace('\\', '/', $line));
        if ($line === '') {
            return null;
        }

        $parts = null;
        if (strpos($line, '=>') !== false) {
            $parts = explode('=>', $line, 2);
        } elseif (strpos($line, '=') !== false) {
            $parts = explode('=', $line, 2);
        }

        if ($parts === null || count($parts) !== 2) {
            return null;
        }

        $from = rtrim(trim($parts[0]), '/');
        $to = trim($parts[1]);
        if ($from === '' || preg_match('/[\x00-\x1F\x7F]/', $from . $to) === 1) {
            return null;
        }

        return [
            'from' => $from,
            'to' => rtrim(str_replace('\\', '/', $to), '/'),
        ];
    }

    private function candidateFingerprint(array $candidate): string
    {
        return hash('sha256', implode('|', [
            (string) ($candidate['path'] ?? ''),
            (string) ($candidate['size'] ?? 0),
            (string) ($candidate['mtime'] ?? 0),
        ]));
    }

    private function mbToBytes($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) round(((float) $value) * 1024 * 1024);
    }

    private function normalizeOptionalNumber($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return (string) max(0, (float) str_replace(',', '.', $value));
    }

    private function normalizeOutputSuffix($value): string
    {
        $suffix = trim((string) $value);
        if ($suffix === '') {
            return '';
        }
        $suffix = preg_replace('~[\\/\x00-\x1F\x7F]+~', '_', $suffix);
        return $suffix === null ? '_processed' : $suffix;
    }

    private function cleanCommaList(string $value): string
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part, " .\t\n\r\0\x0B");
            if ($part !== '') {
                $clean[] = strtolower($part);
            }
        }
        return implode(', ', array_values(array_unique($clean)));
    }

    private function cleanLines($value): array
    {
        if (is_array($value)) {
            $lines = $value;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
        }
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $clean[] = str_replace('\\', '/', $line);
        }
        return array_values(array_unique($clean));
    }

    private function cleanOptionalString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private function relativePath(string $path, string $root): string
    {
        $path = $this->normalizePath($path);
        $root = rtrim($this->normalizePath($root), '/');
        if ($root !== '' && strpos($path, $root . '/') === 0) {
            return substr($path, strlen($root) + 1);
        }
        return basename($path);
    }

    private function regexIsValid(string $regex): bool
    {
        set_error_handler(static function (): bool { return true; });
        $result = preg_match($regex, 'test');
        restore_error_handler();
        return $result !== false;
    }

    private function newRuleId(): string
    {
        try {
            return 'auto_' . bin2hex(random_bytes(6));
        } catch (Exception $exception) {
            return 'auto_' . substr(sha1(uniqid('', true)), 0, 12);
        }
    }

    private function duplicateRuleName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'Automation rule';
        $existing = [];
        foreach ($this->rules() as $rule) {
            $existing[(string) ($rule['name'] ?? '')] = true;
        }

        $base = $name . ' (copy)';
        if (!isset($existing[$base])) {
            return $base;
        }

        for ($number = 2; $number < 1000; $number++) {
            $candidate = $name . ' (copy ' . $number . ')';
            if (!isset($existing[$candidate])) {
                return $candidate;
            }
        }

        return $name . ' (copy ' . gmdate('YmdHis') . ')';
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $contents = (string) file_get_contents($path);
        if (trim($contents) === '') {
            return $default;
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function atomicWriteJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode automation JSON data.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create JSON directory: %s', $directory));
        }
        $temporaryPath = tempnam($directory, basename($path) . '.tmp.');
        if ($temporaryPath === false) {
            throw new RuntimeException(sprintf('Unable to create temporary JSON file in: %s', $directory));
        }
        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open temporary JSON file: %s', $temporaryPath));
            }
            fwrite($handle, $json . PHP_EOL);
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
            if (!@rename($temporaryPath, $path)) {
                throw new RuntimeException(sprintf('Unable to replace JSON file atomically: %s', $path));
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function withLock(callable $callback)
    {
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open automation lock: %s', $this->lockPath));
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException(sprintf('Unable to lock automation store: %s', $this->lockPath));
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function tailLines(string $path, int $limit): array
    {
        if ($limit <= 0 || !is_file($path)) {
            return [];
        }
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return [];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $buffer = '';
        $position = $size;
        $chunkSize = 8192;
        try {
            while ($position > 0 && substr_count($buffer, "\n") <= $limit) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;
                if (fseek($handle, $position) !== 0) {
                    break;
                }
                $chunk = fread($handle, $readSize);
                if ($chunk === false) {
                    break;
                }
                $buffer = $chunk . $buffer;
            }
        } finally {
            fclose($handle);
        }
        $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line): bool { return trim($line) !== ''; }));
        return array_slice($lines, -$limit);
    }

    private function limitString(string $value, int $limit): string
    {
        if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit - 1) . '…';
        }
        if (strlen($value) > $limit) {
            return substr($value, 0, $limit - 1) . '…';
        }
        return $value;
    }
}
