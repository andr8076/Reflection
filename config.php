<?php

declare(strict_types=1);

function reflection_string_starts_with(string $value, string $prefix): bool
{
    return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0;
}

function reflection_read_git_version(string $appRoot): ?string
{
    $gitPath = $appRoot . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($gitPath) && !is_file($gitPath)) {
        return null;
    }

    $output = [];
    $status = 1;
    @exec('git -C ' . escapeshellarg($appRoot) . ' rev-parse --short=12 HEAD 2>/dev/null', $output, $status);
    if ($status === 0 && isset($output[0])) {
        $version = trim((string) $output[0]);
        if ($version !== '') {
            return $version;
        }
    }

    $gitDirectory = reflection_resolve_git_directory($appRoot);
    if ($gitDirectory === null) {
        return null;
    }

    $headPath = $gitDirectory . DIRECTORY_SEPARATOR . 'HEAD';
    if (!is_file($headPath)) {
        return null;
    }

    $head = trim((string) file_get_contents($headPath));
    if ($head === '') {
        return null;
    }

    if (!reflection_string_starts_with($head, 'ref: ')) {
        return substr($head, 0, 12);
    }

    $ref = trim(substr($head, 5));
    $refPath = $gitDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
    if (is_file($refPath)) {
        $commit = trim((string) file_get_contents($refPath));
        return $commit !== '' ? substr($commit, 0, 12) : null;
    }

    $packedRefsPath = $gitDirectory . DIRECTORY_SEPARATOR . 'packed-refs';
    if (is_file($packedRefsPath)) {
        foreach (file($packedRefsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#' || $line[0] === '^') {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2 && $parts[1] === $ref) {
                return substr($parts[0], 0, 12);
            }
        }
    }

    return null;
}


function reflection_read_commit_file(string $appRoot): ?string
{
    $commitPath = $appRoot . DIRECTORY_SEPARATOR . '.reflection_commit';
    if (!is_file($commitPath) || !is_readable($commitPath)) {
        return null;
    }

    $commit = trim((string) file_get_contents($commitPath));
    if ($commit === '') {
        return null;
    }

    // Accept the normal full SHA-1 hash written by the Synology updater and
    // short Git hashes too. Reject anything else so this file cannot inject
    // odd values into the version policy shown to workers.
    if (preg_match('/^[0-9a-fA-F]{7,40}$/', $commit) !== 1) {
        return null;
    }

    return strtolower($commit);
}

function reflection_read_deployed_version(string $appRoot): ?string
{
    return reflection_read_git_version($appRoot)
        ?? reflection_read_commit_file($appRoot);
}

function reflection_resolve_git_directory(string $appRoot): ?string
{
    $gitPath = $appRoot . DIRECTORY_SEPARATOR . '.git';
    if (is_dir($gitPath)) {
        return $gitPath;
    }

    if (!is_file($gitPath)) {
        return null;
    }

    $content = trim((string) file_get_contents($gitPath));
    if (!reflection_string_starts_with($content, 'gitdir:')) {
        return null;
    }

    $gitDirectory = trim(substr($content, strlen('gitdir:')));
    if ($gitDirectory === '') {
        return null;
    }

    if (!reflection_string_starts_with($gitDirectory, DIRECTORY_SEPARATOR)) {
        $gitDirectory = $appRoot . DIRECTORY_SEPARATOR . $gitDirectory;
    }

    return is_dir($gitDirectory) ? realpath($gitDirectory) ?: $gitDirectory : null;
}

function reflection_directory_can_store(string $directory): bool
{
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    return is_writable($directory);
}

function reflection_resolve_master_store(?string $configuredStorage, string $defaultStorage, string $fallbackDirectory): array
{
    if (is_string($configuredStorage) && $configuredStorage !== '') {
        return [
            'storage_path' => $configuredStorage,
            'storage_warning' => null,
        ];
    }

    $defaultDirectory = dirname($defaultStorage);
    if (reflection_directory_can_store($defaultDirectory)) {
        return [
            'storage_path' => $defaultStorage,
            'storage_warning' => null,
        ];
    }

    if (reflection_directory_can_store($fallbackDirectory)) {
        return [
            'storage_path' => $fallbackDirectory . DIRECTORY_SEPARATOR . 'farm_store.json',
            'storage_warning' => sprintf(
                'The default farm store directory is not writable: %s. Using a temporary writable store instead. For persistent storage, make that directory writable by the web server user or set REFLECTION_MASTER_STORE to a writable JSON file path.',
                $defaultDirectory,
            ),
        ];
    }

    return [
        'storage_path' => $defaultStorage,
        'storage_warning' => sprintf(
            'The default farm store directory is not writable: %s, and the temporary fallback directory is not writable: %s.',
            $defaultDirectory,
            $fallbackDirectory,
        ),
    ];
}

function reflection_default_runtime_settings(): array
{
    return [
        'enforce_version' => true,
        'failure_strategy' => 'mark_failed',
        'max_retries' => 0,
        'stale_job_strategy' => 'requeue_to_end',
        'stale_max_retries' => 1,
        'crash_loop_protection_enabled' => true,
        'crash_loop_lost_attempts' => 2,
        'crash_loop_distinct_workers' => 1,
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
        'shutdown_debug_mode' => false,
        'auto_wake_for_queued_jobs' => true,
        'automation_run_due_on_worker_checkin' => true,
        'automation_checkin_cooldown_seconds' => 60,
        'wake_dispatch_mode' => 'worker_relay',
        'auto_wake_cooldown_seconds' => 300,
        'auto_wake_max_targets_per_run' => 20,
        'wake_broadcast_address' => '255.255.255.255',
        'wake_udp_port' => 9,
        'job_archive_keep_lines' => 5000,
        'worker_temp_max_age_hours' => 24,
        'quarantine_keep_days' => 14,
        'job_history_keep_completed' => 500,
        'event_log_keep_lines' => 1000,
        'file_history_keep_paths' => 500,
        'file_history_keep_entries_per_path' => 10,
    ];
}

function reflection_default_allowed_tasks(): array
{
    return [
        'dummy_task' => 'Placeholder pipeline test task.',
        'render_frame' => 'Render a frame with the configured worker renderer.',
        'h265_encode' => 'Transcode video files to H.265/HEVC MP4 with FFmpeg.',
        'compress_archive' => 'Compress a file or directory into a .zip archive.',
        'invert_image' => 'Invert an image while preserving alpha transparency when possible.',
        'noop' => 'Built-in worker connectivity check.',
        'status' => 'Built-in worker health snapshot.',
        'reload_tasks' => 'Ask a worker to reload its local task registry.',
        'shutdown' => 'Ask a worker to power off after reporting success.',
        'update_worker' => 'Ask a worker to download the latest code, report success, and reboot the farm computer.',
        'wake_farm' => 'Ask a worker to send Wake-on-LAN packets to configured farm computers.',
        'storage_test' => 'Ask a worker to verify read/write/rename/delete access to a configured storage server.',
    ];
}


function reflection_builtin_task_specs(): array
{
    $hidden = static function (string $description): array {
        return [
            'description' => $description,
            'source' => ['mode' => 'none', 'label' => 'Source', 'help' => 'Not used by this control task.'],
            'delivery' => ['mode' => 'none', 'label' => 'Delivery', 'help' => 'Not used by this control task.'],
            'output' => ['kind' => 'none'],
        ];
    };

    return [
        'noop' => $hidden('Built-in worker connectivity check.'),
        'reload_tasks' => $hidden('Ask a worker to reload its local task registry.'),
        'shutdown' => $hidden('Ask a worker to power off after reporting success.'),
        'wake_farm' => $hidden('Ask a worker to send Wake-on-LAN packets to configured farm computers.'),
        'storage_test' => $hidden('Ask a worker to verify storage server access.'),
        'update_worker' => [
            'description' => 'Ask a worker to update itself and reboot.',
            'source' => ['mode' => 'none', 'label' => 'Source', 'help' => 'The master supplies the target version automatically.'],
            'delivery' => ['mode' => 'none', 'label' => 'Delivery', 'help' => 'Not used by this control task.'],
            'output' => ['kind' => 'none'],
        ],
        'status' => [
            'description' => 'Built-in worker health snapshot.',
            'source' => ['mode' => 'none', 'label' => 'Source', 'help' => 'Not used by this control task.'],
            'delivery' => ['mode' => 'optional', 'label' => 'Optional status file', 'help' => 'Optional local path where the worker can write a JSON status snapshot.'],
            'output' => ['kind' => 'optional_file', 'extension' => '.json'],
        ],
    ];
}

function reflection_extract_task_spec_json(string $taskFile): ?array
{
    if (!is_file($taskFile) || !is_readable($taskFile)) {
        return null;
    }

    $source = (string) file_get_contents($taskFile);
    if ($source === '') {
        return null;
    }

    if (preg_match('/TASK_SPEC_JSON\s*=\s*r?([\'\"]{3})(.*?)\1/s', $source, $matches) !== 1) {
        return null;
    }

    $decoded = json_decode($matches[2], true);
    return is_array($decoded) ? $decoded : null;
}

function reflection_normalize_task_spec(string $name, array $spec, ?string $fallbackDescription = null): array
{
    $source = is_array($spec['source'] ?? null) ? $spec['source'] : [];
    $delivery = is_array($spec['delivery'] ?? null) ? $spec['delivery'] : [];
    $output = is_array($spec['output'] ?? null) ? $spec['output'] : [];

    $sourceMode = strtolower((string) ($source['mode'] ?? 'required'));
    if (!in_array($sourceMode, ['required', 'optional', 'none'], true)) {
        $sourceMode = 'required';
    }

    $deliveryMode = strtolower((string) ($delivery['mode'] ?? 'optional'));
    if (!in_array($deliveryMode, ['required', 'optional', 'auto', 'none'], true)) {
        $deliveryMode = 'optional';
    }

    $extension = trim((string) ($delivery['extension'] ?? ($output['extension'] ?? '')));
    if ($extension !== '' && $extension !== 'source' && $extension[0] !== '.') {
        $extension = '.' . $extension;
    }

    $normalized = [
        'name' => (string) ($spec['name'] ?? $name),
        'description' => (string) ($spec['description'] ?? $fallbackDescription ?? ''),
        'source' => [
            'mode' => $sourceMode,
            'label' => (string) ($source['label'] ?? 'Source path or URI'),
            'help' => (string) ($source['help'] ?? ''),
        ],
        'delivery' => [
            'mode' => $deliveryMode,
            'label' => (string) ($delivery['label'] ?? 'Delivery path or URI'),
            'help' => (string) ($delivery['help'] ?? ''),
            'template' => trim((string) ($delivery['template'] ?? '')),
            'extension' => $extension,
        ],
        'output' => $output,
    ];

    if ($normalized['delivery']['mode'] === 'auto' && $normalized['delivery']['template'] === '') {
        $normalized['delivery']['mode'] = 'optional';
    }

    return $normalized;
}

function reflection_discover_task_specs(string $tasksDirectory, array $allowedTasks = []): array
{
    $specs = reflection_builtin_task_specs();

    if (is_dir($tasksDirectory)) {
        foreach (glob($tasksDirectory . DIRECTORY_SEPARATOR . '*.py') ?: [] as $taskFile) {
            $spec = reflection_extract_task_spec_json($taskFile);
            if ($spec === null) {
                continue;
            }

            $name = trim((string) ($spec['name'] ?? basename($taskFile, '.py')));
            if ($name === '') {
                continue;
            }

            $fallbackDescription = isset($allowedTasks[$name]) ? (string) $allowedTasks[$name] : null;
            $specs[$name] = reflection_normalize_task_spec($name, $spec, $fallbackDescription);
        }
    }

    foreach ($allowedTasks as $name => $description) {
        $taskName = (string) $name;
        if (!isset($specs[$taskName])) {
            $specs[$taskName] = reflection_normalize_task_spec($taskName, [
                'name' => $taskName,
                'description' => (string) $description,
            ], (string) $description);
        }
    }

    ksort($specs);
    return $specs;
}

function reflection_default_farm_settings(): array
{
    return [
        'farm_id' => 'default',
        'farm_name' => 'Reflection Farm',
        'transfer_server' => [
            'scheme' => 'ftp',
            'host' => '',
            'port' => 21,
            'root' => '',
        ],
        'transfer_auth' => [
            'scheme' => 'ftp',
            'host' => '',
            'port' => 21,
            'username' => '',
            'password' => '',
        ],
        'storage_path' => __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json',
        'required_version' => null,
        'stale_after_seconds' => 15 * 60,
        'runtime_defaults' => reflection_default_runtime_settings(),
        'allowed_tasks' => reflection_default_allowed_tasks(),
    ];
}

function reflection_load_farm_settings(?string $settingsPath = null): array
{
    $defaults = reflection_default_farm_settings();
    $path = $settingsPath ?? (__DIR__ . DIRECTORY_SEPARATOR . 'farm_settings.php');
    $settings = $defaults;

    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $settings = array_replace_recursive($settings, $loaded);
        }
    }

    $localPath = __DIR__ . DIRECTORY_SEPARATOR . 'farm_settings.local.php';
    if ($settingsPath === null && is_file($localPath)) {
        $loadedLocal = require $localPath;
        if (is_array($loadedLocal)) {
            $settings = array_replace_recursive($settings, $loadedLocal);
        }
    }

    return $settings;
}

function reflection_env_string(string $name): ?string
{
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : null;
}

function reflection_transfer_auth_config(array $settings): array
{
    $configured = is_array($settings['transfer_auth'] ?? null) ? $settings['transfer_auth'] : [];
    $host = reflection_env_string('REFLECTION_FTP_HOST') ?? (string) ($configured['host'] ?? '');
    $username = reflection_env_string('REFLECTION_FTP_USERNAME') ?? (string) ($configured['username'] ?? '');
    $password = reflection_env_string('REFLECTION_FTP_PASSWORD') ?? (string) ($configured['password'] ?? '');
    $scheme = strtolower(reflection_env_string('REFLECTION_FTP_SCHEME') ?? (string) ($configured['scheme'] ?? 'ftp'));
    $port = (int) (reflection_env_string('REFLECTION_FTP_PORT') ?? ($configured['port'] ?? 21));

    if (!in_array($scheme, ['ftp', 'ftps'], true)) {
        $scheme = 'ftp';
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port > 0 ? $port : ($scheme === 'ftps' ? 990 : 21),
        'username' => $username,
        'password' => $password,
    ];
}


function reflection_transfer_server_config(array $settings): array
{
    $configuredServer = is_array($settings['transfer_server'] ?? null) ? $settings['transfer_server'] : [];
    $configuredAuth = is_array($settings['transfer_auth'] ?? null) ? $settings['transfer_auth'] : [];

    $scheme = strtolower(
        reflection_env_string('REFLECTION_TRANSFER_SCHEME')
        ?? reflection_env_string('REFLECTION_FTP_SCHEME')
        ?? (string) ($configuredServer['scheme'] ?? ($configuredAuth['scheme'] ?? 'ftp'))
    );
    if (!in_array($scheme, ['ftp', 'ftps', 'sftp'], true)) {
        $scheme = 'ftp';
    }

    $host = reflection_env_string('REFLECTION_TRANSFER_HOST')
        ?? reflection_env_string('REFLECTION_FTP_HOST')
        ?? (string) ($configuredServer['host'] ?? ($configuredAuth['host'] ?? ''));

    $port = (int) (
        reflection_env_string('REFLECTION_TRANSFER_PORT')
        ?? reflection_env_string('REFLECTION_FTP_PORT')
        ?? ($configuredServer['port'] ?? ($configuredAuth['port'] ?? ($scheme === 'sftp' ? 22 : ($scheme === 'ftps' ? 990 : 21))))
    );

    $root = reflection_env_string('REFLECTION_TRANSFER_ROOT')
        ?? reflection_env_string('REFLECTION_FTP_ROOT')
        ?? (string) ($configuredServer['root'] ?? ($configuredAuth['root'] ?? ''));

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port > 0 ? $port : ($scheme === 'sftp' ? 22 : ($scheme === 'ftps' ? 990 : 21)),
        'root' => $root,
    ];
}

function reflection_master_config(?array $farmSettings = null): array
{
    $repoRoot = __DIR__;
    $settings = $farmSettings ?? reflection_load_farm_settings();
    $fallbackDirectory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'reflection-farm-'
        . substr(hash('sha256', __DIR__ . '|' . (string) ($settings['farm_id'] ?? 'default')), 0, 12);
    $detectedVersion = reflection_read_deployed_version($repoRoot) ?? 'unknown';
    $requiredVersion = reflection_env_string('REFLECTION_REQUIRED_VERSION')
        ?? ($settings['required_version'] ?: $detectedVersion);
    $configuredStorage = reflection_env_string('REFLECTION_MASTER_STORE');
    $defaultStorage = (string) (
        $settings['storage_path']
        ?? (__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json')
    );
    $storeConfig = reflection_resolve_master_store(
        $configuredStorage,
        $defaultStorage,
        $fallbackDirectory,
    );

    $allowedTasks = is_array($settings['allowed_tasks'] ?? null) ? $settings['allowed_tasks'] : [];
    $taskSpecs = reflection_discover_task_specs($repoRoot . DIRECTORY_SEPARATOR . 'cluster' . DIRECTORY_SEPARATOR . 'tasks', $allowedTasks);

    return [
        'farm_id' => (string) ($settings['farm_id'] ?? 'default'),
        'farm_name' => (string) ($settings['farm_name'] ?? 'Reflection Farm'),
        'transfer_auth' => reflection_transfer_auth_config($settings),
        'transfer_server' => reflection_transfer_server_config($settings),
        'storage_path' => $storeConfig['storage_path'],
        'storage_warning' => $storeConfig['storage_warning'],
        'required_version' => $requiredVersion !== false && $requiredVersion !== ''
            ? $requiredVersion
            : $detectedVersion,
        'runtime_defaults' => is_array($settings['runtime_defaults'] ?? null) ? $settings['runtime_defaults'] : [],
        'allowed_tasks' => $allowedTasks,
        'task_specs' => $taskSpecs,
        'stale_after_seconds' => (int) ($settings['stale_after_seconds'] ?? (15 * 60)),
    ];
}

function reflection_farm_store(array $config): FarmStore
{
    return new FarmStore($config['storage_path'], $config['runtime_defaults'] ?? []);
}
