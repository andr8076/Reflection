<?php

declare(strict_types=1);

final class FarmStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $directory = dirname($this->path);
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
        return $this->withLock(function (array $data) use ($module, $source, $delivery, $overwriteAllowed): array {
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
        return $this->withLock(function (array $data) use ($taskId, $pcId): array {
            $locked = false;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'queued') {
                    $job['status'] = 'running';
                    $job['worker'] = $pcId;
                    $job['started_at'] = gmdate(DATE_ATOM);
                    $locked = true;
                    break;
                }
            }
            unset($job);

            if ($locked) {
                $data['workers'][$pcId] = array_merge($data['workers'][$pcId] ?? [], [
                    'pc_id' => $pcId,
                    'last_check_in' => gmdate(DATE_ATOM),
                    'current_job' => $taskId,
                ]);
            }

            return ['data' => $data, 'result' => $locked];
        }, true);
    }

    public function finishJob(string $taskId, string $pcId, string $status, string $error): bool
    {
        return $this->withLock(function (array $data) use ($taskId, $pcId, $status, $error): array {
            $finished = false;
            foreach ($data['jobs'] as &$job) {
                if (($job['task_id'] ?? '') === $taskId && ($job['status'] ?? '') === 'running') {
                    $job['status'] = $status === 'success' ? 'success' : 'failed';
                    $job['worker'] = $pcId;
                    $job['error'] = $error;
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $finished = true;
                    break;
                }
            }
            unset($job);

            if ($finished && isset($data['workers'][$pcId])) {
                $data['workers'][$pcId]['last_check_in'] = gmdate(DATE_ATOM);
                $data['workers'][$pcId]['current_job'] = null;
            }

            return ['data' => $data, 'result' => $finished];
        }, true);
    }

    public function requeueStaleJobs(int $staleAfterSeconds): int
    {
        return $this->withLock(function (array $data) use ($staleAfterSeconds): array {
            $now = time();
            $count = 0;

            foreach ($data['jobs'] as &$job) {
                if (($job['status'] ?? '') !== 'running' || empty($job['started_at'])) {
                    continue;
                }

                $startedAt = strtotime((string) $job['started_at']);
                if ($startedAt !== false && ($now - $startedAt) > $staleAfterSeconds) {
                    $job['status'] = 'stale';
                    $job['error'] = 'Worker did not finish before the stale timeout.';
                    $job['finished_at'] = gmdate(DATE_ATOM);
                    $count++;
                }
            }
            unset($job);

            return ['data' => $data, 'result' => $count];
        }, true);
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
