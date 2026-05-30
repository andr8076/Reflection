<?php

declare(strict_types=1);

final class FarmStore
{
    private string $path;
    private string $eventLogPath;
    private string $fileHistoryPath;
    private array $configuredDefaultSettings;

    public function __construct(string $path, array $defaultSettings = [])
    {
        $this->path = $path;
        $this->configuredDefaultSettings = $defaultSettings;
        $directory = dirname($this->path);
        $this->eventLogPath = $directory . DIRECTORY_SEPARATOR . 'farm_events.log';
        $this->fileHistoryPath = $directory . DIRECTORY_SEPARATOR . 'farm_file_history.json';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $parentDirectory = dirname($directory);
            throw new RuntimeException(sprintf(
                'Unable to create farm store directory: %s. Create it manually and make it writable by the web server user, or set REFLECTION_MASTER_STORE to a writable JSON file path. Parent directory: %s',
                $directory,
                $parentDirectory,
            ));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf(
                'Farm store directory is not writable: %s. Make this directory writable by the web server user, or set REFLECTION_MASTER_STORE to a writable JSON file path.',
                $directory,
            ));
        }
    }

    public function read(): array
    {
        return $this->withLock(function (array $data): array {
            return $data;
        });
    }

    public function createJob(string $module, ?string $source, ?string $delivery, bool $overwriteAllowed): array
    {
        $job = $this->withLock(function (array $data) use ($module, $source, $delivery, $overwriteAllowed): array {
            $job = [
                'task_id' => $this->nextJobId($data),
                'module' => $module,
                'source' => $source,
                'delivery' => $delivery,
                'overwrite_allowed' => $overwriteAllowed,
                'attempt' => 0,
                'parent_task_id' => null,
                'status' => 'queued',
                'worker' => null,
                'error' => '',
                'created_at' => gmdate(DATE_ATOM),
                'started_at' => null,
                'finished_at' => null,
            ];

            $data['jobs'][] = $job;
            return ['data' => $data, 'result' => $job];
        }, true);

        $this->recordEvent('job_queued', $job);
        $this->recordFileTouch($job['source'], 'queued_as_source', $job);
        $this->recordFileTouch($job['delivery'], 'queued_as_delivery', $job);

        return $job;
    }

    public function recordWorkerCheckIn(string $pcId, string $version): void
    {
        $this->withLock(function (array $data) use ($pcId, $version): array {
            $data['workers'][$pcId] = array_merge($data['workers'][$pcId] ?? [], [
                'pc_id' => $pcId,
                'version' => $version,
                'last_check_in' => gmdate(DATE_ATOM),
            ]);

            return ['data' => $data, 'result' => null];
        }, true);
    }

    public function recordWorkerNoJobCheckIn(string $pcId): int
    {
        return $this->withLock(function (array $data) use ($pcId): array {
            $worker = $data['workers'][$pcId] ?? ['pc_id' => $pcId];
            $worker['idle_no_job_checkins'] = max(0, (int) ($worker['idle_no_job_checkins'] ?? 0)) + 1;
            $worker['current_job'] = null;
            $data['workers'][$pcId] = $worker;

            return ['data' => $data, 'result' => $worker['idle_no_job_checkins']];
        }, true);
    }

    public function resetWorkerNoJobCheckIns(string $pcId): void
    {
        $this->withLock(function (array $data) use ($pcId): array {
            if (isset($data['workers'][$pcId])) {
                $data['workers'][$pcId]['idle_no_job_checkins'] = 0;
            }

            return ['data' => $data, 'result' => null];
        }, true);
    }

    public function nextQueuedJob(): ?array
    {
        return $this->withLock(function (array $data): ?array {
            foreach ($data['jobs'] as $job) {
                if (($job['status'] ?? '') === 'queued') {
                    return $job;
                }
            }

            return null;
        });
    }

    public function markJobRunning(string $taskId, string $pcId): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId, $pcId): array {
            $lockedJob = null;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'queued') {
                    $job['status'] = 'running';
                    $job['worker'] = $pcId;
                    $job['started_at'] = gmdate(DATE_ATOM);
                    $lockedJob = $job;
                    break;
                }
            }
            unset($job);

            if ($lockedJob !== null) {
                $data['workers'][$pcId] = array_merge($data['workers'][$pcId] ?? [], [
                    'pc_id' => $pcId,
                    'last_check_in' => gmdate(DATE_ATOM),
                    'current_job' => $taskId,
                    'idle_no_job_checkins' => 0,
                ]);
            }

            return ['data' => $data, 'result' => $lockedJob];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_started', $result);
            $this->recordFileTouch($result['source'], 'started_source', $result);
            return true;
        }

        return false;
    }

    public function finishJob(string $taskId, string $pcId, string $status, string $error): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId, $pcId, $status, $error): array {
            $finishedJob = null;
            $retryJob = null;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'running') {
                    $job['status'] = $status === 'success' ? 'success' : 'failed';
                    $job['worker'] = $pcId;
                    $job['error'] = $error;
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $finishedJob = $job;

                    $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
                    $attempt = (int) ($job['attempt'] ?? 0);
                    $maxRetries = max(0, (int) ($settings['max_retries'] ?? 0));
                    if ($job['status'] === 'failed' && ($settings['failure_strategy'] ?? 'mark_failed') === 'retry_to_end' && $attempt < $maxRetries) {
                        $retryJob = $job;
                        $retryJob['task_id'] = $this->nextJobId($data);
                        $retryJob['status'] = 'queued';
                        $retryJob['worker'] = null;
                        $retryJob['error'] = '';
                        $retryJob['attempt'] = $attempt + 1;
                        $retryJob['parent_task_id'] = $job['parent_task_id'] ?? $job['task_id'];
                        $retryJob['created_at'] = gmdate(DATE_ATOM);
                        $retryJob['started_at'] = null;
                        $retryJob['finished_at'] = null;
                        $data['jobs'][] = $retryJob;
                    }
                    break;
                }
            }
            unset($job);

            if ($finishedJob !== null && isset($data['workers'][$pcId])) {
                $data['workers'][$pcId]['last_check_in'] = gmdate(DATE_ATOM);
                $data['workers'][$pcId]['current_job'] = null;
            }

            return ['data' => $data, 'result' => ['finished' => $finishedJob, 'retry' => $retryJob]];
        }, true);

        if (is_array($result) && is_array($result['finished'] ?? null)) {
            $finishedJob = $result['finished'];
            $this->recordEvent('job_' . $finishedJob['status'], $finishedJob);
            $this->recordFileTouch($finishedJob['source'], 'finished_source_' . $finishedJob['status'], $finishedJob);
            $this->recordFileTouch($finishedJob['delivery'], 'finished_delivery_' . $finishedJob['status'], $finishedJob);

            if (is_array($result['retry'] ?? null)) {
                $this->recordEvent('job_retried', $result['retry']);
                $this->recordFileTouch($result['retry']['source'], 'retried_source', $result['retry']);
                $this->recordFileTouch($result['retry']['delivery'], 'retried_delivery', $result['retry']);
            }

            return true;
        }

        return false;
    }

    public function requeueStaleJobs(int $staleAfterSeconds): int
    {
        $staleJobs = $this->withLock(function (array $data) use ($staleAfterSeconds): array {
            $now = time();
            $staleJobs = [];

            foreach ($data['jobs'] as &$job) {
                if (($job['status'] ?? '') !== 'running' || empty($job['started_at'])) {
                    continue;
                }

                $startedAt = strtotime((string) $job['started_at']);
                if ($startedAt !== false && ($now - $startedAt) > $staleAfterSeconds) {
                    $job['status'] = 'stale';
                    $job['error'] = 'Worker did not finish before the stale timeout.';
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $staleJobs[] = $job;
                }
            }
            unset($job);

            return ['data' => $data, 'result' => $staleJobs];
        }, true);

        foreach ($staleJobs as $job) {
            $this->recordEvent('job_stale', $job);
            $this->recordFileTouch($job['source'], 'stale_source', $job);
            $this->recordFileTouch($job['delivery'], 'stale_delivery', $job);
        }

        return count($staleJobs);
    }

    public function updateSettings(array $settings): array
    {
        return $this->withLock(function (array $data) use ($settings): array {
            $data['settings'] = array_merge($this->defaultSettings(), $data['settings'] ?? [], $settings);
            $data['settings']['max_retries'] = max(0, (int) ($data['settings']['max_retries'] ?? 0));
            $data['settings']['ess_soc_percent'] = max(0, min(100, (int) ($data['settings']['ess_soc_percent'] ?? 100)));
            $data['settings']['ess_min_soc_percent'] = max(0, min(100, (int) ($data['settings']['ess_min_soc_percent'] ?? 20)));
            $data['settings']['idle_shutdown_after_no_job_checks'] = max(0, (int) ($data['settings']['idle_shutdown_after_no_job_checks'] ?? 0));
            return ['data' => $data, 'result' => $data['settings']];
        }, true);
    }

    public function updateMachines(array $machines): array
    {
        return $this->withLock(function (array $data) use ($machines): array {
            $cleanMachines = [];
            foreach ($machines as $machine) {
                if (!is_array($machine)) {
                    continue;
                }

                $pcId = trim((string) ($machine['pc_id'] ?? ''));
                $mac = trim((string) ($machine['mac'] ?? ''));
                if ($pcId === '' && $mac === '') {
                    continue;
                }

                $cleanMachines[] = [
                    'pc_id' => $pcId !== '' ? $pcId : $mac,
                    'mac' => $mac,
                    'soc_margin_percent' => max(0, (int) ($machine['soc_margin_percent'] ?? 5)),
                    'wake_enabled' => !empty($machine['wake_enabled']),
                ];
            }

            $data['machines'] = $cleanMachines;
            return ['data' => $data, 'result' => $cleanMachines];
        }, true);
    }

    public function effectiveSettings(): array
    {
        $data = $this->read();
        return array_merge($this->defaultSettings(), $data['settings'] ?? []);
    }

    public function machines(): array
    {
        $data = $this->read();
        return $data['machines'] ?? [];
    }

    public function refreshEssSocFromConfiguredEndpoint(): ?int
    {
        $settings = $this->effectiveSettings();
        $url = trim((string) ($settings['ess_soc_url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $soc = null;
        $trimmedBody = trim($body);
        if (is_numeric($trimmedBody)) {
            $soc = $this->normalizeSocPercent((float) $trimmedBody);
        } else {
            $payload = json_decode($body, true);
            if (is_array($payload)) {
                $soc = $this->extractSocPercent($payload);
            }
        }
        if ($soc === null) {
            return null;
        }

        $this->updateSettings(['ess_soc_percent' => $soc]);
        return $soc;
    }

    public function allowedActiveWorkers(): int
    {
        $settings = $this->effectiveSettings();
        $soc = (int) ($settings['ess_soc_percent'] ?? 100);
        $minimum = (int) ($settings['ess_min_soc_percent'] ?? 20);
        if ($soc <= $minimum) {
            return 0;
        }

        $budget = $soc - $minimum;
        $margins = [];
        foreach ($this->machines() as $machine) {
            if (!empty($machine['wake_enabled'])) {
                $margins[] = max(1, (int) ($machine['soc_margin_percent'] ?? 5));
            }
        }

        if ($margins === []) {
            return PHP_INT_MAX;
        }

        sort($margins, SORT_NUMERIC);
        $allowed = 0;
        foreach ($margins as $margin) {
            if ($budget < $margin) {
                break;
            }

            $budget -= $margin;
            $allowed++;
        }

        return $allowed;
    }

    public function runningWorkerCount(): int
    {
        $data = $this->read();
        $workers = $data['workers'] ?? [];
        $count = 0;
        foreach ($workers as $worker) {
            if (!empty($worker['current_job'])) {
                $count++;
            }
        }

        return $count;
    }

    public function wakeTargetsForCurrentSoc(): array
    {
        $settings = $this->effectiveSettings();
        $budget = max(0, (int) ($settings['ess_soc_percent'] ?? 100) - (int) ($settings['ess_min_soc_percent'] ?? 20));
        $machines = array_values(array_filter($this->machines(), static function (array $machine): bool {
            return !empty($machine['wake_enabled']) && !empty($machine['mac']);
        }));
        usort($machines, static function (array $a, array $b): int {
            return ((int) ($a['soc_margin_percent'] ?? 5)) <=> ((int) ($b['soc_margin_percent'] ?? 5));
        });

        $targets = [];
        foreach ($machines as $machine) {
            $margin = max(1, (int) ($machine['soc_margin_percent'] ?? 5));
            if ($budget < $margin) {
                break;
            }

            $budget -= $margin;
            $targets[] = $machine;
        }

        return $targets;
    }

    public function readRecentEvents(int $limit = 50): array
    {
        if (!is_file($this->eventLogPath)) {
            return [];
        }

        $lines = file($this->eventLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $events = [];

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return array_reverse($events);
    }

    public function readFileHistory(): array
    {
        if (!is_file($this->fileHistoryPath)) {
            return [];
        }

        $history = json_decode((string) file_get_contents($this->fileHistoryPath), true);
        if (!is_array($history)) {
            return [];
        }

        ksort($history);
        return $history;
    }

    private function recordEvent(string $event, array $job): void
    {
        $entry = [
            'timestamp' => gmdate(DATE_ATOM),
            'event' => $event,
            'task_id' => $job['task_id'] ?? null,
            'module' => $job['module'] ?? null,
            'status' => $job['status'] ?? null,
            'worker' => $job['worker'] ?? null,
            'source' => $job['source'] ?? null,
            'delivery' => $job['delivery'] ?? null,
            'error' => $job['error'] ?? '',
        ];

        @file_put_contents($this->eventLogPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function recordFileTouch(?string $path, string $action, array $job): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $history = $this->readFileHistory();
        $entry = [
            'timestamp' => gmdate(DATE_ATOM),
            'action' => $action,
            'task_id' => $job['task_id'] ?? null,
            'module' => $job['module'] ?? null,
            'status' => $job['status'] ?? null,
            'worker' => $job['worker'] ?? null,
            'paired_path' => ($path === ($job['source'] ?? null)) ? ($job['delivery'] ?? null) : ($job['source'] ?? null),
            'error' => $job['error'] ?? '',
        ];

        $history[$path][] = $entry;
        @file_put_contents($this->fileHistoryPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    }

    private function withLock(callable $callback, bool $write = false)
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open farm store: %s', $this->path));
        }

        flock($handle, $write ? LOCK_EX : LOCK_SH);
        rewind($handle);
        $contents = stream_get_contents($handle);
        $data = $this->normalizeData($contents ? json_decode($contents, true) : null);

        $callbackResult = $callback($data);
        $result = $callbackResult;

        if ($write) {
            $dataToWrite = $callbackResult['data'] ?? $data;
            $result = $callbackResult['result'] ?? null;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($this->normalizeData($dataToWrite), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            fflush($handle);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }

    private function normalizeData($data): array
    {
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'jobs' => array_values($data['jobs'] ?? []),
            'workers' => $data['workers'] ?? [],
            'settings' => array_merge($this->defaultSettings(), $data['settings'] ?? []),
            'machines' => array_values($data['machines'] ?? []),
            'last_job_number' => (int) ($data['last_job_number'] ?? 1000),
        ];
    }

    private function extractSocPercent(array $payload): ?int
    {
        foreach (['soc', 'SOC', 'stateOfCharge', 'state_of_charge', 'battery_percent'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return $this->normalizeSocPercent((float) $payload[$key]);
            }
        }

        if (isset($payload['battery']) && is_array($payload['battery'])) {
            return $this->extractSocPercent($payload['battery']);
        }

        return null;
    }

    private function normalizeSocPercent(float $value): int
    {
        if ($value >= 0.0 && $value <= 1.0) {
            $value *= 100;
        }

        return max(0, min(100, (int) round($value)));
    }

    private function defaultSettings(): array
    {
        return array_merge([
            'enforce_version' => true,
            'failure_strategy' => 'mark_failed',
            'max_retries' => 0,
            'ess_soc_percent' => 100,
            'ess_soc_url' => 'http://192.168.1.245:8076',
            'ess_min_soc_percent' => 20,
            'ess_shutdown_below_minimum' => true,
            'idle_shutdown_after_no_job_checks' => 0,
        ], $this->configuredDefaultSettings);
    }

    private function nextJobId(array &$data): string
    {
        $data['last_job_number'] = (int) ($data['last_job_number'] ?? 1000) + 1;
        return 'job_' . $data['last_job_number'];
    }
}
