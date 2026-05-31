<?php

declare(strict_types=1);

final class FarmStore
{
    private string $path;
    private string $lockPath;
    private string $eventLogPath;
    private string $fileHistoryPath;
    private string $jobArchivePath;
    private array $configuredDefaultSettings;

    public function __construct(string $path, array $defaultSettings = [])
    {
        $this->path = $path;
        $this->lockPath = $this->path . '.lock';
        $this->configuredDefaultSettings = $defaultSettings;
        $directory = dirname($this->path);
        $this->eventLogPath = $directory . DIRECTORY_SEPARATOR . 'farm_events.log';
        $this->fileHistoryPath = $directory . DIRECTORY_SEPARATOR . 'farm_file_history.json';
        $this->jobArchivePath = $directory . DIRECTORY_SEPARATOR . 'farm_job_archive.jsonl';
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

    public function jobPage(int $page = 1, int $perPage = 50, string $statusFilter = 'all'): array
    {
        $data = $this->read();
        $jobs = $data['jobs'] ?? [];
        $statusCounts = [];
        foreach ($jobs as $job) {
            $status = (string) ($job['status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        $filteredJobs = array_values(array_filter($jobs, static function (array $job) use ($statusFilter): bool {
            $status = (string) ($job['status'] ?? 'unknown');
            if ($statusFilter === 'all') {
                return true;
            }

            if ($statusFilter === 'active') {
                return in_array($status, ['queued', 'running'], true);
            }

            if ($statusFilter === 'finished') {
                return !in_array($status, ['queued', 'running'], true);
            }

            return $status === $statusFilter;
        }));

        $filteredJobs = array_reverse($filteredJobs);
        $perPage = max(10, min(200, $perPage));
        $total = count($filteredJobs);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        return [
            'jobs' => array_slice($filteredJobs, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'status_filter' => $statusFilter,
            'status_counts' => $statusCounts,
        ];
    }

    public function archiveOldCompletedJobs(int $keepCompleted): int
    {
        $keepCompleted = max(0, $keepCompleted);
        $archivedJobs = $this->withLock(function (array $data) use ($keepCompleted): array {
            $completedIndexes = [];
            foreach ($data['jobs'] as $index => $job) {
                $status = (string) ($job['status'] ?? 'unknown');
                if (!in_array($status, ['queued', 'running'], true)) {
                    $completedIndexes[] = $index;
                }
            }

            $archiveCount = max(0, count($completedIndexes) - $keepCompleted);
            if ($archiveCount === 0) {
                return ['data' => $data, 'result' => []];
            }

            $indexesToArchive = array_flip(array_slice($completedIndexes, 0, $archiveCount));
            $remainingJobs = [];
            $jobsToArchive = [];
            foreach ($data['jobs'] as $index => $job) {
                if (isset($indexesToArchive[$index])) {
                    $job['archived_at'] = gmdate(DATE_ATOM);
                    $jobsToArchive[] = $job;
                    continue;
                }

                $remainingJobs[] = $job;
            }

            if ($jobsToArchive !== []) {
                $this->appendArchivedJobs($jobsToArchive);
            }

            $data['jobs'] = $remainingJobs;
            return ['data' => $data, 'result' => $jobsToArchive];
        }, true);

        return is_array($archivedJobs) ? count($archivedJobs) : 0;
    }

    public function trimEventLog(int $keepLines): int
    {
        $keepLines = max(0, $keepLines);
        if (!is_file($this->eventLogPath)) {
            return 0;
        }

        if ($keepLines === 0) {
            $removed = $this->countFileLines($this->eventLogPath);
            @file_put_contents($this->eventLogPath, '', LOCK_EX);
            return $removed;
        }

        $lineCount = $this->countFileLines($this->eventLogPath);
        if ($lineCount <= $keepLines) {
            return 0;
        }

        $tail = $this->tailLines($this->eventLogPath, $keepLines);
        @file_put_contents($this->eventLogPath, implode(PHP_EOL, $tail) . PHP_EOL, LOCK_EX);
        return $lineCount - count($tail);
    }

    public function compactFileHistory(int $maxPaths, int $entriesPerPath): int
    {
        $maxPaths = max(0, $maxPaths);
        $entriesPerPath = max(0, $entriesPerPath);
        if (!is_file($this->fileHistoryPath)) {
            return 0;
        }

        $history = $this->readFileHistory();
        if ($history === []) {
            return 0;
        }

        $removed = 0;
        $compacted = [];
        foreach ($history as $path => $touches) {
            if (!is_array($touches) || $entriesPerPath === 0) {
                $removed += is_array($touches) ? count($touches) : 1;
                continue;
            }

            $removed += max(0, count($touches) - $entriesPerPath);
            $keptTouches = array_slice($touches, -$entriesPerPath);
            $latestTimestamp = (string) ($keptTouches[count($keptTouches) - 1]['timestamp'] ?? '');
            $compacted[$path] = [
                'latest' => $latestTimestamp,
                'touches' => $keptTouches,
            ];
        }

        uasort($compacted, static function (array $a, array $b): int {
            return strcmp((string) ($b['latest'] ?? ''), (string) ($a['latest'] ?? ''));
        });

        if ($maxPaths === 0) {
            $removed += count($compacted);
            $compacted = [];
        } elseif (count($compacted) > $maxPaths) {
            $removed += count($compacted) - $maxPaths;
            $compacted = array_slice($compacted, 0, $maxPaths, true);
        }

        $newHistory = [];
        foreach ($compacted as $path => $entry) {
            $newHistory[$path] = $entry['touches'];
        }

        $this->atomicWriteJson($this->fileHistoryPath, $newHistory);
        return $removed;
    }

    public function archiveInfo(): array
    {
        return [
            'path' => $this->jobArchivePath,
            'exists' => is_file($this->jobArchivePath),
            'size_bytes' => is_file($this->jobArchivePath) ? (int) filesize($this->jobArchivePath) : 0,
            'jobs' => is_file($this->jobArchivePath) ? $this->countFileLines($this->jobArchivePath) : 0,
        ];
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
                if (
                    ($job['task_id'] ?? '') === $taskId
                    && ($job['status'] ?? '') === 'running'
                    && ($job['worker'] ?? '') === $pcId
                ) {
                    $job['status'] = $status === 'success' ? 'success' : 'failed';
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
                    $workerId = (string) ($job['worker'] ?? '');
                    if ($workerId !== '' && isset($data['workers'][$workerId])) {
                        $data['workers'][$workerId]['last_check_in'] = gmdate(DATE_ATOM);
                        $data['workers'][$workerId]['current_job'] = null;
                    }
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
            $data['settings']['ess_ignore_when_unavailable'] = !empty($data['settings']['ess_ignore_when_unavailable']);
            $data['settings']['ess_soc_status'] = $this->cleanEssStatus((string) ($data['settings']['ess_soc_status'] ?? 'manual'));
            $data['settings']['ess_soc_error'] = $this->limitString((string) ($data['settings']['ess_soc_error'] ?? ''), 500);
            $data['settings']['ess_soc_raw_sample'] = $this->limitString((string) ($data['settings']['ess_soc_raw_sample'] ?? ''), 500);
            $data['settings']['idle_shutdown_after_no_job_checks'] = max(0, (int) ($data['settings']['idle_shutdown_after_no_job_checks'] ?? 0));
            $data['settings']['job_history_keep_completed'] = max(0, (int) ($data['settings']['job_history_keep_completed'] ?? 500));
            $data['settings']['event_log_keep_lines'] = max(0, (int) ($data['settings']['event_log_keep_lines'] ?? 1000));
            $data['settings']['file_history_keep_paths'] = max(0, (int) ($data['settings']['file_history_keep_paths'] ?? 500));
            $data['settings']['file_history_keep_entries_per_path'] = max(0, (int) ($data['settings']['file_history_keep_entries_per_path'] ?? 10));
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
            if (($settings['ess_soc_status'] ?? '') !== 'manual') {
                $this->recordEssSocStatus('manual', null, 'Manual SOC value is being used because no ESS SOC URL is configured.');
            }
            return null;
        }

        $checkedAt = gmdate(DATE_ATOM);
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
                'header' => "Accept: application/json, text/plain;q=0.9, */*;q=0.1
",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->recordEssSocStatus(
                'offline',
                null,
                'Unable to read ESS SOC endpoint. SOC worker limiting is ignored until a valid value is received again.',
                '',
                $checkedAt,
            );
            return null;
        }

        $parsed = $this->parseSocResponse($body);
        if ($parsed['soc'] === null) {
            $this->recordEssSocStatus(
                'parse_error',
                null,
                (string) $parsed['error'] . ' SOC worker limiting is ignored until a valid value is received again.',
                $body,
                $checkedAt,
            );
            return null;
        }

        $soc = (int) $parsed['soc'];
        $this->recordEssSocStatus('online', $soc, '', $body, $checkedAt);
        return $soc;
    }

    public function allowedActiveWorkers(): int
    {
        $settings = $this->effectiveSettings();
        if (!$this->essSocCanLimitWorkers($settings)) {
            return PHP_INT_MAX;
        }

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
        $machines = array_values(array_filter($this->machines(), static function (array $machine): bool {
            return !empty($machine['wake_enabled']) && !empty($machine['mac']);
        }));
        usort($machines, static function (array $a, array $b): int {
            return ((int) ($a['soc_margin_percent'] ?? 5)) <=> ((int) ($b['soc_margin_percent'] ?? 5));
        });

        if (!$this->essSocCanLimitWorkers($settings)) {
            return $machines;
        }

        $budget = max(0, (int) ($settings['ess_soc_percent'] ?? 100) - (int) ($settings['ess_min_soc_percent'] ?? 20));
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

        $lines = $this->tailLines($this->eventLogPath, max(0, $limit));
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

        uasort($history, static function (array $a, array $b): int {
            $latestA = (string) ($a[count($a) - 1]['timestamp'] ?? '');
            $latestB = (string) ($b[count($b) - 1]['timestamp'] ?? '');
            return strcmp($latestB, $latestA);
        });
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

    private function recordSystemEvent(string $event, string $error = '', array $extra = []): void
    {
        $entry = array_merge([
            'timestamp' => gmdate(DATE_ATOM),
            'event' => $event,
            'task_id' => null,
            'module' => null,
            'status' => null,
            'worker' => null,
            'source' => null,
            'delivery' => null,
            'error' => $error,
        ], $extra);

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

        $settings = $this->effectiveSettings();
        $entriesPerPath = max(1, (int) ($settings['file_history_keep_entries_per_path'] ?? 10));
        $maxPaths = max(1, (int) ($settings['file_history_keep_paths'] ?? 500));

        $history[$path][] = $entry;
        $history[$path] = array_slice($history[$path], -$entriesPerPath);

        uasort($history, static function (array $a, array $b): int {
            $latestA = (string) ($a[count($a) - 1]['timestamp'] ?? '');
            $latestB = (string) ($b[count($b) - 1]['timestamp'] ?? '');
            return strcmp($latestB, $latestA);
        });

        if (count($history) > $maxPaths) {
            $history = array_slice($history, 0, $maxPaths, true);
        }

        $this->atomicWriteJson($this->fileHistoryPath, $history);
    }

    private function appendArchivedJobs(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        $lines = [];
        foreach ($jobs as $job) {
            $encoded = json_encode($job, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $lines[] = $encoded;
            }
        }

        if ($lines !== []) {
            $written = @file_put_contents($this->jobArchivePath, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($written === false) {
                throw new RuntimeException(sprintf('Unable to append archived jobs to: %s', $this->jobArchivePath));
            }
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
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        return array_slice($lines, -$limit);
    }

    private function countFileLines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $count = 0;
        $file = new SplFileObject($path, 'r');
        while (!$file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                $count++;
            }
        }

        return $count;
    }

    private function withLock(callable $callback, bool $write = false)
    {
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open farm store lock: %s', $this->lockPath));
        }

        if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) {
            fclose($handle);
            throw new RuntimeException(sprintf('Unable to lock farm store: %s', $this->lockPath));
        }

        try {
            $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';
            $decoded = null;
            if (trim($contents) !== '') {
                $decoded = json_decode($contents, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->preserveCorruptStore($contents);
                    throw new RuntimeException(sprintf(
                        'Farm store JSON is invalid: %s. A corrupt backup was written beside the store.',
                        json_last_error_msg(),
                    ));
                }
            }

            $data = $this->normalizeData($decoded);
            $callbackResult = $callback($data);
            $result = $callbackResult;

            if ($write) {
                $dataToWrite = $callbackResult['data'] ?? $data;
                $result = $callbackResult['result'] ?? null;
                $this->atomicWriteJson($this->path, $this->normalizeData($dataToWrite));
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function atomicWriteJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode farm JSON data.');
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
            $temporaryHandle = fopen($temporaryPath, 'wb');
            if ($temporaryHandle === false) {
                throw new RuntimeException(sprintf('Unable to open temporary JSON file: %s', $temporaryPath));
            }

            fwrite($temporaryHandle, $json . PHP_EOL);
            fflush($temporaryHandle);
            if (function_exists('fsync')) {
                fsync($temporaryHandle);
            }
            fclose($temporaryHandle);

            if (!@rename($temporaryPath, $path)) {
                throw new RuntimeException(sprintf('Unable to replace JSON file atomically: %s', $path));
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function preserveCorruptStore(string $contents): void
    {
        $backupPath = $this->path . '.corrupt-' . gmdate('Ymd-His');
        @file_put_contents($backupPath, $contents, LOCK_EX);
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

    private function parseSocResponse(string $body): array
    {
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            return ['soc' => null, 'error' => 'ESS SOC endpoint returned an empty response.'];
        }

        $plainSoc = $this->parseSocValue($trimmedBody);
        if ($plainSoc !== null) {
            return ['soc' => $plainSoc, 'error' => ''];
        }

        $payload = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
            $soc = $this->extractSocPercent($payload);
            if ($soc !== null) {
                return ['soc' => $soc, 'error' => ''];
            }

            return ['soc' => null, 'error' => 'ESS SOC JSON was valid, but no supported SOC key contained a valid value.'];
        }

        return ['soc' => null, 'error' => 'ESS SOC output was not a supported plain number/percent or JSON document.'];
    }

    private function extractSocPercent(array $payload): ?int
    {
        foreach (['soc', 'SOC', 'stateOfCharge', 'state_of_charge', 'battery_percent', 'batteryPercent', 'charge', 'charge_percent'] as $key) {
            if (array_key_exists($key, $payload)) {
                $soc = $this->parseSocValue($payload[$key]);
                if ($soc !== null) {
                    return $soc;
                }
            }
        }

        foreach (['battery', 'ess', 'system', 'data'] as $nestedKey) {
            if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
                $soc = $this->extractSocPercent($payload[$nestedKey]);
                if ($soc !== null) {
                    return $soc;
                }
            }
        }

        return null;
    }

    private function parseSocValue($value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $this->normalizeSocPercent((float) $value);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (!preg_match('/^([+-]?\d+(?:\.\d+)?)\s*%?$/', $value, $matches)) {
            return null;
        }

        return $this->normalizeSocPercent((float) $matches[1]);
    }

    private function normalizeSocPercent(float $value): ?int
    {
        if (!is_finite($value) || $value < 0.0) {
            return null;
        }

        if ($value <= 1.0) {
            $value *= 100;
        }

        if ($value > 100.0) {
            return null;
        }

        return (int) round($value);
    }

    private function essSocCanLimitWorkers(array $settings): bool
    {
        $url = trim((string) ($settings['ess_soc_url'] ?? ''));
        if ($url === '') {
            return true;
        }

        if (empty($settings['ess_ignore_when_unavailable'])) {
            return true;
        }

        return ($settings['ess_soc_status'] ?? 'manual') === 'online';
    }

    private function recordEssSocStatus(string $status, ?int $soc, string $error = '', string $rawBody = '', ?string $checkedAt = null): void
    {
        $status = $this->cleanEssStatus($status);
        $checkedAt = $checkedAt ?? gmdate(DATE_ATOM);
        $sample = $this->limitString(preg_replace('/\s+/', ' ', trim($rawBody)) ?? '', 500);
        $error = $this->limitString($error, 500);

        $previous = $this->effectiveSettings();
        $update = [
            'ess_soc_status' => $status,
            'ess_soc_last_checked_at' => $checkedAt,
            'ess_soc_error' => $error,
            'ess_soc_raw_sample' => $sample,
        ];

        if ($soc !== null) {
            $update['ess_soc_percent'] = $soc;
            $update['ess_soc_last_success_at'] = $checkedAt;
        } elseif ($status !== 'manual') {
            $update['ess_soc_last_failure_at'] = $checkedAt;
        }

        $this->updateSettings($update);

        $previousStatus = (string) ($previous['ess_soc_status'] ?? 'manual');
        $previousError = (string) ($previous['ess_soc_error'] ?? '');
        if ($previousStatus !== $status || ($status !== 'online' && $previousError !== $error)) {
            $this->recordSystemEvent('ess_soc_' . $status, $error, [
                'soc' => $soc,
                'raw_sample' => $sample,
            ]);
        }
    }

    private function cleanEssStatus(string $status): string
    {
        return in_array($status, ['manual', 'online', 'offline', 'parse_error'], true) ? $status : 'manual';
    }

    private function limitString(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit);
        }

        if (!function_exists('mb_strlen') && strlen($value) > $limit) {
            return substr($value, 0, $limit);
        }

        return $value;
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
            'ess_ignore_when_unavailable' => true,
            'ess_soc_status' => 'manual',
            'ess_soc_error' => '',
            'ess_soc_raw_sample' => '',
            'ess_soc_last_checked_at' => null,
            'ess_soc_last_success_at' => null,
            'ess_soc_last_failure_at' => null,
            'idle_shutdown_after_no_job_checks' => 0,
            'job_history_keep_completed' => 500,
            'event_log_keep_lines' => 1000,
            'file_history_keep_paths' => 500,
            'file_history_keep_entries_per_path' => 10,
        ], $this->configuredDefaultSettings);
    }

    private function nextJobId(array &$data): string
    {
        $data['last_job_number'] = (int) ($data['last_job_number'] ?? 1000) + 1;
        return 'job_' . $data['last_job_number'];
    }
}
