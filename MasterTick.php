<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/FarmStore.php';
require_once __DIR__ . '/AutomationStore.php';
require_once __DIR__ . '/StorageStore.php';
require_once __DIR__ . '/ui_helpers.php';

/**
 * Run all recurring master work under one non-overlapping lock.
 *
 * Web requests only mutate the state explicitly requested by that request.
 * Lease expiry, ESS refresh, automations, wake decisions, and retention are
 * owned by this tick and should be scheduled once per minute.
 */
function reflection_run_master_tick(
    array $config,
    ?FarmStore $farmStore = null,
    ?AutomationStore $automationStore = null
): array {
    $dataDirectory = dirname((string) ($config['storage_path'] ?? (__DIR__ . '/data/farm_master.json')));
    if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0775, true) && !is_dir($dataDirectory)) {
        throw new RuntimeException('Unable to create master tick data directory: ' . $dataDirectory);
    }

    $lockPath = $dataDirectory . DIRECTORY_SEPARATOR . 'master_tick.lock';
    $lockHandle = @fopen($lockPath, 'c+');
    if ($lockHandle === false) {
        throw new RuntimeException('Unable to open master tick lock: ' . $lockPath);
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return [
            'status' => 'busy',
            'started_at' => gmdate(DATE_ATOM),
            'message' => 'Another master tick is already running.',
        ];
    }

    $startedAt = gmdate(DATE_ATOM);
    $started = microtime(true);
    try {
        $farmStore = $farmStore ?? reflection_farm_store($config);
        if ($automationStore === null) {
            $storageStore = new StorageStore($dataDirectory, $config['transfer_server'] ?? null);
            $transferServerSchemes = [];
            foreach ($storageStore->enabledServers(true) as $server) {
                $serverId = trim((string) ($server['id'] ?? ''));
                if ($serverId !== '') {
                    $transferServerSchemes[$serverId] = (string) ($server['scheme'] ?? 'ftp');
                }
            }
            $automationStore = new AutomationStore(
                $dataDirectory,
                is_array($config['task_specs'] ?? null) ? $config['task_specs'] : [],
                $transferServerSchemes
            );
        }

        $soc = $farmStore->refreshEssSocFromConfiguredEndpoint(false);
        $expiredJobs = $farmStore->requeueStaleJobs(max(1, (int) ($config['stale_after_seconds'] ?? 900)));
        $automationResults = $automationStore->runDueRules($farmStore, false);
        $queued = 0;
        foreach ($automationResults as $automationResult) {
            $queued += (int) ($automationResult['queued'] ?? 0);
        }

        $wake = $farmStore->autoWakeForQueuedJobs(
            max(1, (int) ($config['stale_after_seconds'] ?? 900)),
            'master_tick'
        );
        $maintenance = reflection_run_store_maintenance($farmStore, $farmStore->effectiveSettings());

        $result = [
            'status' => 'ok',
            'started_at' => $startedAt,
            'finished_at' => gmdate(DATE_ATOM),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ess_soc_percent' => $soc,
            'expired_jobs' => $expiredJobs,
            'rules_run' => count($automationResults),
            'jobs_queued' => $queued,
            'auto_wake' => $wake,
            'maintenance' => $maintenance,
            'automation_results' => $automationResults,
        ];
        reflection_write_master_tick_status($dataDirectory, $result);
        return $result;
    } catch (Throwable $exception) {
        $result = [
            'status' => 'error',
            'started_at' => $startedAt,
            'finished_at' => gmdate(DATE_ATOM),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $exception->getMessage(),
        ];
        reflection_write_master_tick_status($dataDirectory, $result);
        throw $exception;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function reflection_read_master_tick_status(string $dataDirectory): array
{
    $path = rtrim($dataDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master_tick_status.json';
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function reflection_write_master_tick_status(string $dataDirectory, array $status): void
{
    $path = rtrim($dataDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'master_tick_status.json';
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $encoded = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to persist master tick status: ' . $path);
    }
}
