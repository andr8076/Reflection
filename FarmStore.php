<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

    public function createJob(string $module, ?string $source, ?string $delivery, bool $overwriteAllowed, array $extra = []): array
    {
        $job = $this->withLock(function (array $data) use ($module, $source, $delivery, $overwriteAllowed, $extra): array {
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
                'heartbeat_at' => null,
                'finished_at' => null,
                'crash_key' => null,
            ];

            foreach ($extra as $key => $value) {
                if (is_string($key) && preg_match('/^[a-zA-Z0-9_]+$/', $key) === 1 && !array_key_exists($key, $job)) {
                    $job[$key] = $value;
                }
            }

            $job['crash_key'] = $this->jobCrashKey($job);
            $data['jobs'][] = $job;
            return ['data' => $data, 'result' => $job];
        }, true);

        $this->recordEvent('job_queued', $job);
        $this->recordFileTouch($job['source'], 'queued_as_source', $job);
        $this->recordFileTouch($job['delivery'], 'queued_as_delivery', $job);

        return $job;
    }


    public function hasOpenJob(string $module, ?string $source): bool
    {
        return $this->withLock(function (array $data) use ($module, $source): bool {
            foreach ($data['jobs'] as $job) {
                if (
                    ($job['module'] ?? '') === $module
                    && ($job['source'] ?? null) === $source
                    && in_array((string) ($job['status'] ?? ''), ['queued', 'running', 'blocked'], true)
                ) {
                    return true;
                }
            }

            return false;
        });
    }

    public function recordWorkerCheckIn(string $pcId, string $version, array $capabilities = []): void
    {
        $this->withLock(function (array $data) use ($pcId, $version, $capabilities): array {
            $worker = array_merge($data['workers'][$pcId] ?? [], [
                'pc_id' => $pcId,
                'version' => $version,
                'last_check_in' => gmdate(DATE_ATOM),
            ]);

            if ($capabilities !== []) {
                $worker['capabilities'] = $this->cleanWorkerCapabilities($capabilities);
            }

            $data['workers'][$pcId] = $worker;
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
                if (($job['status'] ?? '') === 'queued' && $this->isControlModule((string) ($job['module'] ?? ''))) {
                    return $job;
                }
            }

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
                    $now = gmdate(DATE_ATOM);
                    $job['started_at'] = $now;
                    $job['heartbeat_at'] = $now;
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

    public function heartbeatJob(string $taskId, string $pcId): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId, $pcId): array {
            $heartbeatJob = null;
            $now = gmdate(DATE_ATOM);

            foreach ($data['jobs'] as &$job) {
                if (
                    ($job['task_id'] ?? '') === $taskId
                    && ($job['status'] ?? '') === 'running'
                    && ($job['worker'] ?? '') === $pcId
                ) {
                    $job['heartbeat_at'] = $now;
                    $heartbeatJob = $job;
                    break;
                }
            }
            unset($job);

            if ($heartbeatJob !== null) {
                $data['workers'][$pcId] = array_merge($data['workers'][$pcId] ?? [], [
                    'pc_id' => $pcId,
                    'last_check_in' => $now,
                    'current_job' => $taskId,
                    'idle_no_job_checkins' => 0,
                ]);
            }

            return ['data' => $data, 'result' => $heartbeatJob];
        }, true);

        return is_array($result);
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
                        $retryJob['heartbeat_at'] = null;
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
        $result = $this->withLock(function (array $data) use ($staleAfterSeconds): array {
            $now = time();
            $nowText = gmdate(DATE_ATOM, $now);
            $staleAfterSeconds = max(1, $staleAfterSeconds);
            $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
            $strategy = (string) ($settings['stale_job_strategy'] ?? 'requeue_to_end');
            if (!in_array($strategy, ['mark_stale', 'requeue_to_end'], true)) {
                $strategy = 'requeue_to_end';
            }
            $maxStaleRetries = max(0, (int) ($settings['stale_max_retries'] ?? 1));
            $staleJobs = [];
            $blockedJobs = [];
            $requeuedJobs = [];

            foreach ($data['jobs'] as &$job) {
                if (($job['status'] ?? '') !== 'running') {
                    continue;
                }

                $progressText = (string) ($job['heartbeat_at'] ?? $job['started_at'] ?? '');
                if ($progressText === '') {
                    continue;
                }

                $lastProgressAt = strtotime($progressText);
                if ($lastProgressAt === false || ($now - $lastProgressAt) <= $staleAfterSeconds) {
                    continue;
                }

                $originalWorkerId = (string) ($job['worker'] ?? '');
                $job['status'] = 'stale';
                $job['error'] = 'Worker heartbeat timed out after ' . $staleAfterSeconds . ' seconds. Last progress: ' . $progressText;
                $job['stale_at'] = $nowText;
                $job['finished_at'] = $nowText;
                $job['crash_key'] = $this->jobCrashKey($job);

                if ($originalWorkerId !== '' && isset($data['workers'][$originalWorkerId])) {
                    $data['workers'][$originalWorkerId]['last_check_in'] = $nowText;
                    $data['workers'][$originalWorkerId]['current_job'] = null;
                }

                $blockInfo = $this->crashLoopBlockInfo($data, $job, $settings);
                if ($blockInfo !== null) {
                    $this->applyCrashLoopBlock($job, $blockInfo, $nowText);
                    $blockedJobs[] = $job;
                    continue;
                }

                $staleJobs[] = $job;

                $attempt = (int) ($job['attempt'] ?? 0);
                if ($strategy === 'requeue_to_end' && $attempt < $maxStaleRetries) {
                    $retryJob = $job;
                    $retryJob['task_id'] = $this->nextJobId($data);
                    $retryJob['status'] = 'queued';
                    $retryJob['worker'] = null;
                    $retryJob['error'] = '';
                    $retryJob['attempt'] = $attempt + 1;
                    $retryJob['parent_task_id'] = $job['parent_task_id'] ?? $job['task_id'];
                    $retryJob['requeued_from_stale_task_id'] = $job['task_id'];
                    $retryJob['created_at'] = $nowText;
                    $retryJob['started_at'] = null;
                    $retryJob['heartbeat_at'] = null;
                    $retryJob['finished_at'] = null;
                    $retryJob['crash_key'] = $this->jobCrashKey($retryJob);
                    unset($retryJob['stale_at'], $retryJob['blocked_at'], $retryJob['blocked_reason'], $retryJob['crash_pattern_count'], $retryJob['crash_pattern_workers']);
                    $data['jobs'][] = $retryJob;
                    $requeuedJobs[] = $retryJob;
                }
            }
            unset($job);

            return ['data' => $data, 'result' => ['stale' => $staleJobs, 'blocked' => $blockedJobs, 'requeued' => $requeuedJobs]];
        }, true);

        $staleJobs = is_array($result['stale'] ?? null) ? $result['stale'] : [];
        $blockedJobs = is_array($result['blocked'] ?? null) ? $result['blocked'] : [];
        $requeuedJobs = is_array($result['requeued'] ?? null) ? $result['requeued'] : [];

        foreach ($staleJobs as $job) {
            $this->recordEvent('job_stale', $job);
            $this->recordFileTouch($job['source'], 'stale_source', $job);
            $this->recordFileTouch($job['delivery'], 'stale_delivery', $job);
        }

        foreach ($blockedJobs as $job) {
            $this->recordEvent('job_blocked_crash_loop', $job);
            $this->recordFileTouch($job['source'], 'blocked_crash_loop_source', $job);
            $this->recordFileTouch($job['delivery'], 'blocked_crash_loop_delivery', $job);
        }

        foreach ($requeuedJobs as $job) {
            $this->recordEvent('job_requeued_after_stale', $job);
            $this->recordFileTouch($job['source'], 'requeued_after_stale_source', $job);
            $this->recordFileTouch($job['delivery'], 'requeued_after_stale_delivery', $job);
        }

        return count($staleJobs) + count($blockedJobs);
    }

    public function recoverInterruptedJobForWorker(string $pcId): array
    {
        $result = $this->withLock(function (array $data) use ($pcId): array {
            $nowText = gmdate(DATE_ATOM);
            $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
            $strategy = (string) ($settings['stale_job_strategy'] ?? 'requeue_to_end');
            if (!in_array($strategy, ['mark_stale', 'requeue_to_end'], true)) {
                $strategy = 'requeue_to_end';
            }
            $maxStaleRetries = max(0, (int) ($settings['stale_max_retries'] ?? 1));
            $worker = $data['workers'][$pcId] ?? [];
            $taskId = trim((string) ($worker['current_job'] ?? ''));
            $interruptedJob = null;
            $blockedJob = null;
            $requeuedJob = null;
            $clearedPointerOnly = false;

            if ($taskId !== '') {
                foreach ($data['jobs'] as &$job) {
                    if (
                        ($job['task_id'] ?? '') === $taskId
                        && ($job['status'] ?? '') === 'running'
                        && ($job['worker'] ?? '') === $pcId
                    ) {
                        $job['status'] = 'stale';
                        $job['error'] = 'Worker requested new work while this job was still running. Treating previous assignment as a worker crash or hard restart.';
                        $job['stale_at'] = $nowText;
                        $job['finished_at'] = $nowText;
                        $job['loss_reason'] = 'worker_requested_new_task_without_completion';
                        $job['crash_key'] = $this->jobCrashKey($job);

                        $blockInfo = $this->crashLoopBlockInfo($data, $job, $settings);
                        if ($blockInfo !== null) {
                            $this->applyCrashLoopBlock($job, $blockInfo, $nowText);
                            $blockedJob = $job;
                            $interruptedJob = $job;
                            break;
                        }

                        $interruptedJob = $job;

                        $attempt = (int) ($job['attempt'] ?? 0);
                        if ($strategy === 'requeue_to_end' && $attempt < $maxStaleRetries) {
                            $requeuedJob = $job;
                            $requeuedJob['task_id'] = $this->nextJobId($data);
                            $requeuedJob['status'] = 'queued';
                            $requeuedJob['worker'] = null;
                            $requeuedJob['error'] = '';
                            $requeuedJob['attempt'] = $attempt + 1;
                            $requeuedJob['parent_task_id'] = $job['parent_task_id'] ?? $job['task_id'];
                            $requeuedJob['requeued_from_stale_task_id'] = $job['task_id'];
                            $requeuedJob['created_at'] = $nowText;
                            $requeuedJob['started_at'] = null;
                            $requeuedJob['heartbeat_at'] = null;
                            $requeuedJob['finished_at'] = null;
                            $requeuedJob['loss_reason'] = null;
                            $requeuedJob['crash_key'] = $this->jobCrashKey($requeuedJob);
                            unset($requeuedJob['stale_at'], $requeuedJob['blocked_at'], $requeuedJob['blocked_reason'], $requeuedJob['crash_pattern_count'], $requeuedJob['crash_pattern_workers']);
                            $data['jobs'][] = $requeuedJob;
                        }
                        break;
                    }
                }
                unset($job);

                if ($interruptedJob === null) {
                    $clearedPointerOnly = true;
                }

                if (isset($data['workers'][$pcId])) {
                    $data['workers'][$pcId]['last_check_in'] = $nowText;
                    $data['workers'][$pcId]['current_job'] = null;
                    $data['workers'][$pcId]['idle_no_job_checkins'] = 0;
                }
            }

            return ['data' => $data, 'result' => [
                'interrupted' => $interruptedJob,
                'blocked' => $blockedJob,
                'requeued' => $requeuedJob,
                'cleared_pointer_only' => $clearedPointerOnly,
            ]];
        }, true);

        if (!is_array($result)) {
            return ['interrupted' => null, 'blocked' => null, 'requeued' => null, 'cleared_pointer_only' => false];
        }

        if (is_array($result['blocked'] ?? null)) {
            $this->recordEvent('job_blocked_crash_loop', $result['blocked']);
            $this->recordFileTouch($result['blocked']['source'], 'blocked_crash_loop_source', $result['blocked']);
            $this->recordFileTouch($result['blocked']['delivery'], 'blocked_crash_loop_delivery', $result['blocked']);
        } elseif (is_array($result['interrupted'] ?? null)) {
            $this->recordEvent('job_interrupted_by_worker_restart', $result['interrupted']);
            $this->recordFileTouch($result['interrupted']['source'], 'interrupted_source', $result['interrupted']);
            $this->recordFileTouch($result['interrupted']['delivery'], 'interrupted_delivery', $result['interrupted']);
        }

        if (is_array($result['requeued'] ?? null)) {
            $this->recordEvent('job_requeued_after_worker_restart', $result['requeued']);
            $this->recordFileTouch($result['requeued']['source'], 'requeued_after_worker_restart_source', $result['requeued']);
            $this->recordFileTouch($result['requeued']['delivery'], 'requeued_after_worker_restart_delivery', $result['requeued']);
        }

        return $result;
    }


    public function trimJobArchive(int $keepLines): int
    {
        $keepLines = max(0, $keepLines);
        if (!is_file($this->jobArchivePath)) {
            return 0;
        }

        if ($keepLines === 0) {
            $removed = $this->countFileLines($this->jobArchivePath);
            @file_put_contents($this->jobArchivePath, '', LOCK_EX);
            return $removed;
        }

        $lineCount = $this->countFileLines($this->jobArchivePath);
        if ($lineCount <= $keepLines) {
            return 0;
        }

        $tail = $this->tailLines($this->jobArchivePath, $keepLines);
        @file_put_contents($this->jobArchivePath, implode(PHP_EOL, $tail) . PHP_EOL, LOCK_EX);
        return $lineCount - count($tail);
    }

    public function blockedJobs(int $limit = 200): array
    {
        $data = $this->read();
        $jobs = array_values(array_filter($data['jobs'] ?? [], static function (array $job): bool {
            return ($job['status'] ?? '') === 'blocked';
        }));
        $jobs = array_reverse($jobs);
        return array_slice($jobs, 0, max(1, $limit));
    }

    public function retryBlockedJob(string $taskId): ?array
    {
        $result = $this->withLock(function (array $data) use ($taskId): array {
            $newJob = null;
            foreach ($data['jobs'] as $job) {
                if (($job['task_id'] ?? '') !== $taskId || ($job['status'] ?? '') !== 'blocked') {
                    continue;
                }

                $newJob = $job;
                $newJob['task_id'] = $this->nextJobId($data);
                $newJob['status'] = 'queued';
                $newJob['worker'] = null;
                $newJob['error'] = '';
                $newJob['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
                $newJob['parent_task_id'] = $job['parent_task_id'] ?? $job['task_id'];
                $newJob['created_at'] = gmdate(DATE_ATOM);
                $newJob['started_at'] = null;
                $newJob['heartbeat_at'] = null;
                $newJob['finished_at'] = null;
                $newJob['manual_retry_from_blocked_task_id'] = $job['task_id'];
                unset($newJob['blocked_at'], $newJob['blocked_reason']);
                $newJob['crash_key'] = $this->jobCrashKey($newJob);
                $data['jobs'][] = $newJob;
                break;
            }
            return ['data' => $data, 'result' => $newJob];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_manual_retry_from_blocked', $result);
            return $result;
        }
        return null;
    }

    public function markJobIgnored(string $taskId): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId): array {
            $changed = null;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && in_array((string) ($job['status'] ?? ''), ['blocked', 'failed', 'stale'], true)) {
                    $job['status'] = 'ignored';
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $job['error'] = trim((string) ($job['error'] ?? '') . ' Marked ignored by operator.');
                    $changed = $job;
                    break;
                }
            }
            unset($job);
            return ['data' => $data, 'result' => $changed];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_ignored', $result);
            return true;
        }
        return false;
    }

    public function unblockJob(string $taskId): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId): array {
            $changed = null;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'blocked') {
                    $job['status'] = 'failed';
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $job['error'] = trim((string) ($job['error'] ?? '') . ' Unblocked by operator; automation may queue this source again.');
                    unset($job['blocked_at'], $job['blocked_reason']);
                    $changed = $job;
                    break;
                }
            }
            unset($job);
            return ['data' => $data, 'result' => $changed];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_unblocked', $result);
            return true;
        }
        return false;
    }

    public function deleteJob(string $taskId): bool
    {
        $result = $this->withLock(function (array $data) use ($taskId): array {
            $deleted = null;
            $remaining = [];
            foreach ($data['jobs'] as $job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') !== 'running') {
                    $deleted = $job;
                    continue;
                }
                $remaining[] = $job;
            }
            if ($deleted !== null) {
                $data['jobs'] = $remaining;
            }
            return ['data' => $data, 'result' => $deleted];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_deleted', $result);
            return true;
        }
        return false;
    }

    public function updateSettings(array $settings): array
    {
        return $this->withLock(function (array $data) use ($settings): array {
            $data['settings'] = array_merge($this->defaultSettings(), $data['settings'] ?? [], $settings);
            $data['settings']['max_retries'] = max(0, (int) ($data['settings']['max_retries'] ?? 0));
            $strategy = (string) ($data['settings']['stale_job_strategy'] ?? 'requeue_to_end');
            $data['settings']['stale_job_strategy'] = in_array($strategy, ['mark_stale', 'requeue_to_end'], true) ? $strategy : 'requeue_to_end';
            $data['settings']['stale_max_retries'] = max(0, (int) ($data['settings']['stale_max_retries'] ?? 1));
            $data['settings']['crash_loop_protection_enabled'] = !empty($data['settings']['crash_loop_protection_enabled']);
            $data['settings']['crash_loop_lost_attempts'] = max(1, (int) ($data['settings']['crash_loop_lost_attempts'] ?? 2));
            $data['settings']['crash_loop_distinct_workers'] = max(1, (int) ($data['settings']['crash_loop_distinct_workers'] ?? 1));
            if (array_key_exists('ess_soc_percent', $data['settings'])) {
                $data['settings']['ess_soc_percent'] = max(0, min(100, (int) ($data['settings']['ess_soc_percent'] ?? 100)));
            }
            $data['settings']['ess_min_soc_percent'] = max(0, min(100, (int) ($data['settings']['ess_min_soc_percent'] ?? 20)));
            $data['settings']['ess_ignore_when_unavailable'] = !empty($data['settings']['ess_ignore_when_unavailable']);
            $data['settings']['ess_soc_status'] = $this->cleanEssStatus((string) ($data['settings']['ess_soc_status'] ?? 'manual'));
            $data['settings']['ess_soc_error'] = $this->limitString((string) ($data['settings']['ess_soc_error'] ?? ''), 500);
            $data['settings']['ess_soc_raw_sample'] = $this->limitString((string) ($data['settings']['ess_soc_raw_sample'] ?? ''), 500);
            $data['settings']['idle_shutdown_after_no_job_checks'] = max(0, (int) ($data['settings']['idle_shutdown_after_no_job_checks'] ?? 0));
            $data['settings']['auto_wake_for_queued_jobs'] = !empty($data['settings']['auto_wake_for_queued_jobs']);
            $data['settings']['automation_run_due_on_worker_checkin'] = !empty($data['settings']['automation_run_due_on_worker_checkin']);
            $data['settings']['automation_checkin_cooldown_seconds'] = max(0, min(3600, (int) ($data['settings']['automation_checkin_cooldown_seconds'] ?? 60)));
            $dispatchMode = (string) ($data['settings']['wake_dispatch_mode'] ?? 'worker_relay');
            $data['settings']['wake_dispatch_mode'] = in_array($dispatchMode, ['direct', 'worker_relay', 'direct_then_worker_relay'], true) ? $dispatchMode : 'worker_relay';
            $data['settings']['auto_wake_cooldown_seconds'] = max(0, (int) ($data['settings']['auto_wake_cooldown_seconds'] ?? 300));
            $data['settings']['auto_wake_max_targets_per_run'] = max(0, (int) ($data['settings']['auto_wake_max_targets_per_run'] ?? 20));
            $data['settings']['wake_broadcast_address'] = $this->limitString(trim((string) ($data['settings']['wake_broadcast_address'] ?? '255.255.255.255')), 100) ?: '255.255.255.255';
            $data['settings']['wake_udp_port'] = max(1, min(65535, (int) ($data['settings']['wake_udp_port'] ?? 9)));
            $data['settings']['job_history_keep_completed'] = max(0, (int) ($data['settings']['job_history_keep_completed'] ?? 500));
            $data['settings']['event_log_keep_lines'] = max(0, (int) ($data['settings']['event_log_keep_lines'] ?? 1000));
            $data['settings']['file_history_keep_paths'] = max(0, (int) ($data['settings']['file_history_keep_paths'] ?? 500));
            $data['settings']['file_history_keep_entries_per_path'] = max(0, (int) ($data['settings']['file_history_keep_entries_per_path'] ?? 10));
            $data['settings']['job_archive_keep_lines'] = max(0, (int) ($data['settings']['job_archive_keep_lines'] ?? 5000));
            $data['settings']['worker_temp_max_age_hours'] = max(1, (int) ($data['settings']['worker_temp_max_age_hours'] ?? 24));
            $data['settings']['quarantine_keep_days'] = max(1, (int) ($data['settings']['quarantine_keep_days'] ?? 14));
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


    public function queuedWorkCount(): int
    {
        $data = $this->read();
        $count = 0;
        foreach ($data['jobs'] as $job) {
            if (($job['status'] ?? '') === 'queued' && !$this->isControlModule((string) ($job['module'] ?? ''))) {
                $count++;
            }
        }

        return $count;
    }

    public function idleOnlineWorkerCount(int $staleAfterSeconds): int
    {
        $data = $this->read();
        $workers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $count = 0;
        foreach ($workers as $worker) {
            if (trim((string) ($worker['current_job'] ?? '')) === '') {
                $count++;
            }
        }

        return $count;
    }

    public function demandWakePlan(int $staleAfterSeconds): array
    {
        $data = $this->read();
        $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
        $queuedWork = 0;
        foreach ($data['jobs'] as $job) {
            if (($job['status'] ?? '') === 'queued' && !$this->isControlModule((string) ($job['module'] ?? ''))) {
                $queuedWork++;
            }
        }

        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $idleOnlineWorkers = 0;
        foreach ($onlineWorkers as $worker) {
            if (trim((string) ($worker['current_job'] ?? '')) === '') {
                $idleOnlineWorkers++;
            }
        }

        $needed = max(0, $queuedWork - $idleOnlineWorkers);
        $eligibleTargets = $this->wakeTargetsFromData($data, $settings, $staleAfterSeconds, true, false);
        $cooldownSeconds = max(0, (int) ($settings['auto_wake_cooldown_seconds'] ?? 300));
        $readyTargets = $this->filterWakeTargetsByCooldown($eligibleTargets, $data['wake_history'] ?? [], $cooldownSeconds);
        $maxTargets = max(0, (int) ($settings['auto_wake_max_targets_per_run'] ?? 20));
        $targets = array_slice($readyTargets, 0, $maxTargets > 0 ? min($needed, $maxTargets) : 0);

        return [
            'enabled' => !empty($settings['auto_wake_for_queued_jobs']),
            'queued_work' => $queuedWork,
            'online_workers' => count($onlineWorkers),
            'idle_online_workers' => $idleOnlineWorkers,
            'needed' => $needed,
            'eligible_targets' => count($eligibleTargets),
            'ready_targets' => count($readyTargets),
            'cooldown_seconds' => $cooldownSeconds,
            'max_targets_per_run' => $maxTargets,
            'targets' => $targets,
        ];
    }

    public function autoWakeForQueuedJobs(int $staleAfterSeconds, string $reason = 'auto_demand'): array
    {
        $plan = $this->demandWakePlan($staleAfterSeconds);
        if (empty($plan['enabled']) || ($plan['targets'] ?? []) === []) {
            $plan['wake_result'] = [
                'sent' => 0,
                'failed' => 0,
                'errors' => [],
            ];
            return $plan;
        }

        $plan['wake_result'] = $this->dispatchWakeTargets($plan['targets'], $reason);
        return $plan;
    }


    public function dispatchWakeTargets(array $targets, string $reason = 'manual', ?string $preferredWorkerId = null): array
    {
        $settings = $this->effectiveSettings();
        $mode = (string) ($settings['wake_dispatch_mode'] ?? 'worker_relay');
        if (!in_array($mode, ['direct', 'worker_relay', 'direct_then_worker_relay'], true)) {
            $mode = 'worker_relay';
        }

        if ($mode === 'direct') {
            $result = $this->sendWakePackets($targets, $reason);
            $result['method'] = 'direct';
            $result['queued'] = 0;
            return $result;
        }

        if ($mode === 'direct_then_worker_relay') {
            $direct = $this->sendWakePackets($targets, $reason);
            if ((int) ($direct['failed'] ?? 0) === 0) {
                $direct['method'] = 'direct';
                $direct['queued'] = 0;
                return $direct;
            }

            $failedTargets = [];
            foreach (($direct['errors'] ?? []) as $error) {
                if (is_array($error) && trim((string) ($error['mac'] ?? '')) !== '') {
                    $failedTargets[] = $error;
                }
            }
            $relay = $this->queueWakeRelayJob($failedTargets !== [] ? $failedTargets : $targets, $reason, $preferredWorkerId);
            $relay['method'] = 'direct_then_worker_relay';
            $relay['direct_result'] = $direct;
            return $relay;
        }

        $relay = $this->queueWakeRelayJob($targets, $reason, $preferredWorkerId);
        $relay['method'] = 'worker_relay';
        return $relay;
    }

    public function queueWakeRelayJob(array $targets, string $reason = 'manual', ?string $preferredWorkerId = null): array
    {
        $settings = $this->effectiveSettings();
        $broadcast = trim((string) ($settings['wake_broadcast_address'] ?? '255.255.255.255')) ?: '255.255.255.255';
        $port = max(1, min(65535, (int) ($settings['wake_udp_port'] ?? 9)));
        $cleanTargets = [];
        foreach ($targets as $target) {
            $target = is_array($target) ? $target : ['mac' => (string) $target];
            $mac = trim((string) ($target['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }
            $cleanTargets[] = [
                'pc_id' => (string) ($target['pc_id'] ?? ''),
                'mac' => $mac,
            ];
        }

        if ($cleanTargets === []) {
            return [
                'sent' => 0,
                'failed' => 0,
                'queued' => 0,
                'errors' => [],
                'relay_job' => null,
                'relay_pending' => false,
            ];
        }

        $payload = json_encode([
            'targets' => $cleanTargets,
            'broadcast' => $broadcast,
            'port' => $port,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return [
                'sent' => 0,
                'failed' => count($cleanTargets),
                'queued' => 0,
                'errors' => [['error' => 'Unable to encode Wake-on-LAN relay payload.']],
                'relay_job' => null,
                'relay_pending' => false,
            ];
        }

        $nowText = gmdate(DATE_ATOM);
        $result = $this->withLock(function (array $data) use ($payload, $cleanTargets, $reason, $preferredWorkerId, $nowText): array {
            foreach ($data['jobs'] as $job) {
                if (($job['module'] ?? '') === 'wake_farm' && in_array((string) ($job['status'] ?? ''), ['queued', 'running'], true)) {
                    return ['data' => $data, 'result' => [
                        'job' => $job,
                        'created' => false,
                        'pending' => true,
                    ]];
                }
            }

            $job = [
                'task_id' => $this->nextJobId($data),
                'module' => 'wake_farm',
                'source' => $payload,
                'delivery' => '',
                'overwrite_allowed' => false,
                'attempt' => 0,
                'parent_task_id' => null,
                'status' => 'queued',
                'worker' => null,
                'error' => '',
                'created_at' => $nowText,
                'started_at' => null,
                'heartbeat_at' => null,
                'finished_at' => null,
                'crash_key' => null,
                'control_reason' => 'wake_relay',
                'wake_reason' => $reason,
                'preferred_worker' => $preferredWorkerId !== null ? $preferredWorkerId : '',
            ];
            $job['crash_key'] = $this->jobCrashKey($job);
            $data['jobs'][] = $job;

            $history = is_array($data['wake_history'] ?? null) ? $data['wake_history'] : [];
            foreach ($cleanTargets as $target) {
                $key = $this->wakeTargetKey($target);
                $history[$key] = [
                    'pc_id' => (string) ($target['pc_id'] ?? ''),
                    'mac' => (string) ($target['mac'] ?? ''),
                    'last_wake_at' => $nowText,
                    'reason' => $reason,
                    'success' => null,
                    'status' => 'queued_for_worker_relay',
                    'error' => '',
                ];
            }
            uasort($history, static function (array $a, array $b): int {
                return strcmp((string) ($b['last_wake_at'] ?? ''), (string) ($a['last_wake_at'] ?? ''));
            });
            $data['wake_history'] = array_slice($history, 0, 500, true);

            return ['data' => $data, 'result' => [
                'job' => $job,
                'created' => true,
                'pending' => false,
            ]];
        }, true);

        $job = is_array($result['job'] ?? null) ? $result['job'] : null;
        if (!empty($result['created']) && $job !== null) {
            $this->recordEvent('wake_relay_queued', $job);
            $this->recordSystemEvent('wake_relay_queued', '', [
                'reason' => $reason,
                'targets' => $cleanTargets,
                'relay_task_id' => (string) ($job['task_id'] ?? ''),
            ]);
        }

        return [
            'sent' => 0,
            'failed' => 0,
            'queued' => !empty($result['created']) ? count($cleanTargets) : 0,
            'errors' => [],
            'relay_job' => $job,
            'relay_pending' => !empty($result['pending']),
        ];
    }

    public function wakeTargetsForCurrentSoc(bool $excludeOnline = false, int $staleAfterSeconds = 900): array
    {
        $data = $this->read();
        $settings = array_merge($this->defaultSettings(), $data['settings'] ?? []);
        return $this->wakeTargetsFromData($data, $settings, $staleAfterSeconds, false, $excludeOnline);
    }

    public function sendWakePackets(array $targets, string $reason = 'manual'): array
    {
        $settings = $this->effectiveSettings();
        $broadcast = trim((string) ($settings['wake_broadcast_address'] ?? '255.255.255.255')) ?: '255.255.255.255';
        $port = max(1, min(65535, (int) ($settings['wake_udp_port'] ?? 9)));
        $sent = [];
        $errors = [];
        $attempts = [];

        foreach ($targets as $target) {
            $target = is_array($target) ? $target : ['mac' => (string) $target];
            $mac = trim((string) ($target['mac'] ?? ''));
            $key = $this->wakeTargetKey($target);
            try {
                $this->sendWakePacket($mac, $broadcast, $port);
                $sent[] = $target;
                $attempts[$key] = [
                    'pc_id' => (string) ($target['pc_id'] ?? ''),
                    'mac' => $mac,
                    'last_wake_at' => gmdate(DATE_ATOM),
                    'reason' => $reason,
                    'success' => true,
                    'error' => '',
                ];
            } catch (Throwable $exception) {
                $errors[] = [
                    'pc_id' => (string) ($target['pc_id'] ?? ''),
                    'mac' => $mac,
                    'error' => $exception->getMessage(),
                ];
                $attempts[$key] = [
                    'pc_id' => (string) ($target['pc_id'] ?? ''),
                    'mac' => $mac,
                    'last_wake_at' => gmdate(DATE_ATOM),
                    'reason' => $reason,
                    'success' => false,
                    'error' => $this->limitString($exception->getMessage(), 300),
                ];
            }
        }

        if ($attempts !== []) {
            $this->withLock(function (array $data) use ($attempts): array {
                $history = is_array($data['wake_history'] ?? null) ? $data['wake_history'] : [];
                foreach ($attempts as $key => $attempt) {
                    $history[$key] = $attempt;
                }
                uasort($history, static function (array $a, array $b): int {
                    return strcmp((string) ($b['last_wake_at'] ?? ''), (string) ($a['last_wake_at'] ?? ''));
                });
                $data['wake_history'] = array_slice($history, 0, 500, true);
                return ['data' => $data, 'result' => null];
            }, true);
        }

        if ($sent !== []) {
            $this->recordSystemEvent('wake_sent', '', [
                'reason' => $reason,
                'targets' => array_map(static function (array $target): array {
                    return [
                        'pc_id' => (string) ($target['pc_id'] ?? ''),
                        'mac' => (string) ($target['mac'] ?? ''),
                    ];
                }, $sent),
            ]);
        }
        foreach ($errors as $error) {
            $this->recordSystemEvent('wake_failed', (string) ($error['error'] ?? ''), $error);
        }

        return [
            'sent' => count($sent),
            'failed' => count($errors),
            'errors' => $errors,
        ];
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
            'wake_history' => is_array($data['wake_history'] ?? null) ? $data['wake_history'] : [],
            'last_job_number' => (int) ($data['last_job_number'] ?? 1000),
        ];
    }


    private function isControlModule(string $module): bool
    {
        return in_array($module, ['noop', 'status', 'reload_tasks', 'shutdown', 'update_worker', 'wake_farm', 'storage_test'], true);
    }

    private function onlineWorkersFromData(array $data, int $staleAfterSeconds): array
    {
        $staleAfterSeconds = max(1, $staleAfterSeconds);
        $cutoff = time() - $staleAfterSeconds;
        $online = [];
        foreach (($data['workers'] ?? []) as $worker) {
            if (!is_array($worker)) {
                continue;
            }
            $lastCheckIn = strtotime((string) ($worker['last_check_in'] ?? ''));
            if ($lastCheckIn !== false && $lastCheckIn >= $cutoff) {
                $pcId = trim((string) ($worker['pc_id'] ?? ''));
                if ($pcId !== '') {
                    $online[$pcId] = $worker;
                }
            }
        }

        return $online;
    }

    private function wakeTargetsFromData(array $data, array $settings, int $staleAfterSeconds, bool $excludeOnline, bool $ignoreCooldown): array
    {
        $onlineWorkers = $this->onlineWorkersFromData($data, $staleAfterSeconds);
        $machines = [];
        foreach (($data['machines'] ?? []) as $machine) {
            if (!is_array($machine) || empty($machine['wake_enabled']) || trim((string) ($machine['mac'] ?? '')) === '') {
                continue;
            }
            $pcId = trim((string) ($machine['pc_id'] ?? ''));
            if ($excludeOnline && $pcId !== '' && isset($onlineWorkers[$pcId])) {
                continue;
            }
            $machine['soc_margin_percent'] = max(1, (int) ($machine['soc_margin_percent'] ?? 5));
            $machines[] = $machine;
        }

        usort($machines, static function (array $a, array $b): int {
            return ((int) ($a['soc_margin_percent'] ?? 5)) <=> ((int) ($b['soc_margin_percent'] ?? 5));
        });

        if (!$this->essSocCanLimitWorkers($settings)) {
            return $machines;
        }

        $budget = max(0, (int) ($settings['ess_soc_percent'] ?? 100) - (int) ($settings['ess_min_soc_percent'] ?? 20));
        foreach (($data['machines'] ?? []) as $machine) {
            if (!is_array($machine) || empty($machine['wake_enabled'])) {
                continue;
            }
            $pcId = trim((string) ($machine['pc_id'] ?? ''));
            if ($pcId !== '' && isset($onlineWorkers[$pcId])) {
                $budget -= max(1, (int) ($machine['soc_margin_percent'] ?? 5));
            }
        }
        $budget = max(0, $budget);

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

    private function filterWakeTargetsByCooldown(array $targets, array $history, int $cooldownSeconds): array
    {
        if ($cooldownSeconds <= 0) {
            return $targets;
        }

        $now = time();
        $ready = [];
        foreach ($targets as $target) {
            $key = $this->wakeTargetKey($target);
            $lastWakeAt = strtotime((string) ($history[$key]['last_wake_at'] ?? ''));
            if ($lastWakeAt !== false && ($now - $lastWakeAt) < $cooldownSeconds) {
                continue;
            }
            $ready[] = $target;
        }

        return $ready;
    }

    private function wakeTargetKey(array $target): string
    {
        $pcId = trim((string) ($target['pc_id'] ?? ''));
        if ($pcId !== '') {
            return 'pc:' . $pcId;
        }

        return 'mac:' . strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) ($target['mac'] ?? '')) ?? '');
    }

    private function sendWakePacket(string $macAddress, string $broadcastAddress, int $port): void
    {
        $cleanMac = preg_replace('/[^a-fA-F0-9]/', '', $macAddress) ?? '';
        if (strlen($cleanMac) !== 12) {
            throw new RuntimeException('Invalid MAC address: ' . $macAddress);
        }

        $macBytes = hex2bin($cleanMac);
        if ($macBytes === false) {
            throw new RuntimeException('Invalid MAC address: ' . $macAddress);
        }
        $payload = str_repeat(chr(255), 6) . str_repeat($macBytes, 16);

        if (function_exists('socket_create') && defined('AF_INET') && defined('SOCK_DGRAM') && defined('SOL_UDP')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) {
                throw new RuntimeException('Unable to create UDP socket for Wake-on-LAN.');
            }
            try {
                if (defined('SOL_SOCKET') && defined('SO_BROADCAST')) {
                    @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
                }
                $sent = @socket_sendto($socket, $payload, strlen($payload), 0, $broadcastAddress, $port);
                if ($sent === false) {
                    $message = function_exists('socket_last_error') && function_exists('socket_strerror')
                        ? socket_strerror(socket_last_error($socket))
                        : 'unknown socket error';
                    throw new RuntimeException('Unable to send Wake-on-LAN packet: ' . $message);
                }
            } finally {
                @socket_close($socket);
            }
            return;
        }

        $stream = @fsockopen('udp://' . $broadcastAddress, $port, $errorNumber, $errorString, 2.0);
        if ($stream === false) {
            throw new RuntimeException('Unable to open UDP stream for Wake-on-LAN: ' . $errorString . ' (' . $errorNumber . ')');
        }
        try {
            $written = @fwrite($stream, $payload);
            if ($written === false || $written < strlen($payload)) {
                throw new RuntimeException('Unable to write complete Wake-on-LAN packet.');
            }
        } finally {
            @fclose($stream);
        }
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

    private function jobCrashKey(array $job): string
    {
        $module = trim((string) ($job['module'] ?? ''));
        $source = trim((string) ($job['source'] ?? ''));
        if ($module === '' || $source === '' || $this->isControlModule($module)) {
            return '';
        }

        $parts = [
            'module' => $module,
            'source' => $source,
            'transfer_server_id' => (string) ($job['transfer_server_id'] ?? ''),
        ];

        return sha1(json_encode($parts, JSON_UNESCAPED_SLASHES) ?: ($module . '|' . $source));
    }

    private function crashLoopBlockInfo(array $data, array $job, array $settings): ?array
    {
        if (empty($settings['crash_loop_protection_enabled'])) {
            return null;
        }

        $crashKey = (string) ($job['crash_key'] ?? '');
        if ($crashKey === '') {
            $crashKey = $this->jobCrashKey($job);
        }
        if ($crashKey === '') {
            return null;
        }

        $lostAttemptsNeeded = max(1, (int) ($settings['crash_loop_lost_attempts'] ?? 2));
        $distinctWorkersNeeded = max(1, (int) ($settings['crash_loop_distinct_workers'] ?? 1));
        $lostCount = 0;
        $workers = [];

        foreach (($data['jobs'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateKey = (string) ($candidate['crash_key'] ?? '');
            if ($candidateKey === '') {
                $candidateKey = $this->jobCrashKey($candidate);
            }
            if ($candidateKey !== $crashKey) {
                continue;
            }

            $status = (string) ($candidate['status'] ?? '');
            if (!in_array($status, ['stale', 'blocked'], true)) {
                continue;
            }

            $lostCount++;
            $worker = trim((string) ($candidate['worker'] ?? ''));
            if ($worker !== '') {
                $workers[$worker] = true;
            }
        }

        $distinctWorkers = count($workers);
        if ($lostCount < $lostAttemptsNeeded || $distinctWorkers < $distinctWorkersNeeded) {
            return null;
        }

        return [
            'crash_key' => $crashKey,
            'lost_count' => $lostCount,
            'distinct_workers' => $distinctWorkers,
            'workers' => array_keys($workers),
            'lost_attempts_needed' => $lostAttemptsNeeded,
            'distinct_workers_needed' => $distinctWorkersNeeded,
        ];
    }

    private function applyCrashLoopBlock(array &$job, array $blockInfo, string $nowText): void
    {
        $previousError = trim((string) ($job['error'] ?? ''));
        $workerText = implode(', ', array_slice($blockInfo['workers'] ?? [], 0, 8));
        $reason = 'Crash-loop protection blocked this work item after '
            . (int) ($blockInfo['lost_count'] ?? 0)
            . ' lost/abandoned attempt(s)';
        if ((int) ($blockInfo['distinct_workers'] ?? 0) > 0) {
            $reason .= ' across ' . (int) ($blockInfo['distinct_workers'] ?? 0) . ' worker(s)';
        }
        if ($workerText !== '') {
            $reason .= ' [' . $workerText . ']';
        }
        $reason .= '. It will not be requeued automatically.';

        $job['status'] = 'blocked';
        $job['blocked_at'] = $nowText;
        $job['blocked_reason'] = $reason;
        $job['crash_pattern_count'] = (int) ($blockInfo['lost_count'] ?? 0);
        $job['crash_pattern_workers'] = array_values($blockInfo['workers'] ?? []);
        $job['error'] = $previousError !== '' ? ($reason . ' Last lost-job error: ' . $previousError) : $reason;
    }


    private function cleanWorkerCapabilities(array $capabilities): array
    {
        $clean = [];
        if (isset($capabilities['tasks']) && is_array($capabilities['tasks'])) {
            $tasks = [];
            foreach ($capabilities['tasks'] as $task) {
                $task = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $task);
                if ($task !== '') {
                    $tasks[$task] = true;
                }
            }
            $clean['tasks'] = array_keys($tasks);
        }
        foreach (['can_send_wol', 'ffmpeg', 'ffprobe', 'task_isolation'] as $key) {
            if (array_key_exists($key, $capabilities)) {
                $clean[$key] = !empty($capabilities[$key]);
            }
        }
        foreach (['free_disk_bytes', 'free_temp_bytes'] as $key) {
            if (array_key_exists($key, $capabilities)) {
                $clean[$key] = max(0, (int) $capabilities[$key]);
            }
        }
        foreach (['platform', 'python', 'temp_dir'] as $key) {
            if (array_key_exists($key, $capabilities)) {
                $clean[$key] = $this->limitString((string) $capabilities[$key], 200);
            }
        }
        $clean['reported_at'] = gmdate(DATE_ATOM);
        return $clean;
    }

    private function defaultSettings(): array
    {
        return array_merge(reflection_default_runtime_settings(), $this->configuredDefaultSettings);
    }

    private function nextJobId(array &$data): string
    {
        $data['last_job_number'] = (int) ($data['last_job_number'] ?? 1000) + 1;
        return 'job_' . $data['last_job_number'];
    }
}
