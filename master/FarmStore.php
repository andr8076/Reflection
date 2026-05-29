<?php

declare(strict_types=1);

final class FarmStore
{
    private string $path;
    private string $eventLogPath;
    private string $fileHistoryPath;

    public function __construct(string $path)
    {
        $this->path = $path;
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
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'running') {
                    $job['status'] = $status === 'success' ? 'success' : 'failed';
                    $job['worker'] = $pcId;
                    $job['error'] = $error;
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $finishedJob = $job;
                    break;
                }
            }
            unset($job);

            if ($finishedJob !== null && isset($data['workers'][$pcId])) {
                $data['workers'][$pcId]['last_check_in'] = gmdate(DATE_ATOM);
                $data['workers'][$pcId]['current_job'] = null;
            }

            return ['data' => $data, 'result' => $finishedJob];
        }, true);

        if (is_array($result)) {
            $this->recordEvent('job_' . $result['status'], $result);
            $this->recordFileTouch($result['source'], 'finished_source_' . $result['status'], $result);
            $this->recordFileTouch($result['delivery'], 'finished_delivery_' . $result['status'], $result);
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
            'last_job_number' => (int) ($data['last_job_number'] ?? 1000),
        ];
    }

    private function nextJobId(array &$data): string
    {
        $data['last_job_number'] = (int) ($data['last_job_number'] ?? 1000) + 1;
        return 'job_' . $data['last_job_number'];
    }
}
