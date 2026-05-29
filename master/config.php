<?php

declare(strict_types=1);

function reflection_string_starts_with(string $value, string $prefix): bool
{
    return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0;
}

function reflection_string_contains(string $value, string $needle): bool
{
    return $needle === '' || strpos($value, $needle) !== false;
}

function reflection_git_commit_id(string $repoRoot): ?string
{
    $headPath = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'HEAD';
    if (!is_file($headPath)) {
        return null;
    }

    $head = trim((string) file_get_contents($headPath));
    if (!reflection_string_starts_with($head, 'ref:')) {
        return $head !== '' ? $head : null;
    }

    $refName = trim(substr($head, 4));
    $refPath = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $refName);
    if (is_file($refPath)) {
        $commit = trim((string) file_get_contents($refPath));
        return $commit !== '' ? $commit : null;
    }

    $packedRefsPath = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'packed-refs';
    if (!is_file($packedRefsPath)) {
        return null;
    }

    foreach (file($packedRefsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (reflection_string_starts_with($line, '#') || reflection_string_starts_with($line, '^')) {
            continue;
        }

        [$commit, $packedRefName] = array_pad(explode(' ', $line, 2), 2, '');
        if ($packedRefName === $refName && $commit !== '') {
            return $commit;
        }
    }

    return null;
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

function reflection_default_farm_settings(): array
{
    return [
        'farm_id' => 'default',
        'farm_name' => 'Default Reflection Farm',
        'default_login' => [
            'username' => 'reflection',
            'password' => 'reflection',
        ],
        'auth' => [
            'enabled' => true,
            'realm' => 'Reflection Farm Master',
        ],
        'storage_path' => __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json',
        'required_version' => null,
        'stale_after_seconds' => 15 * 60,
        'runtime_defaults' => [
            'enforce_version' => true,
            'failure_strategy' => 'mark_failed',
            'max_retries' => 0,
            'ess_soc_percent' => 100,
            'ess_soc_url' => 'http://192.168.1.245:8076',
            'ess_min_soc_percent' => 20,
            'ess_shutdown_below_minimum' => true,
        ],
        'allowed_tasks' => [
            'dummy_task' => 'Placeholder pipeline test task.',
            'render_frame' => 'Render a frame with the configured worker renderer.',
            'h265_encode' => 'Transcode video files to H.265/HEVC MP4 with FFmpeg.',
            'noop' => 'Built-in worker connectivity check.',
            'status' => 'Built-in worker health snapshot.',
            'reload_tasks' => 'Ask a worker to reload its local task registry.',
            'shutdown' => 'Ask a worker to stop after reporting success.',
            'wake_farm' => 'Ask a worker to send Wake-on-LAN packets to configured farm computers.',
        ],
    ];
}

function reflection_load_farm_settings(?string $settingsPath = null): array
{
    $defaults = reflection_default_farm_settings();
    $path = $settingsPath ?? (__DIR__ . DIRECTORY_SEPARATOR . 'farm_settings.php');
    if (!is_file($path)) {
        return $defaults;
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $loaded);
}

function reflection_env_string(string $name): ?string
{
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : null;
}

function reflection_master_config(?array $farmSettings = null): array
{
    $repoRoot = dirname(__DIR__);
    $settings = $farmSettings ?? reflection_load_farm_settings();
    $fallbackDirectory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'reflection-farm-'
        . substr(hash('sha256', __DIR__ . '|' . (string) ($settings['farm_id'] ?? 'default')), 0, 12);
    $requiredVersion = reflection_env_string('REFLECTION_REQUIRED_VERSION')
        ?? ($settings['required_version'] ?: reflection_git_commit_id($repoRoot));
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

    return [
        'farm_id' => (string) ($settings['farm_id'] ?? 'default'),
        'farm_name' => (string) ($settings['farm_name'] ?? 'Default Reflection Farm'),
        'default_login' => is_array($settings['default_login'] ?? null) ? $settings['default_login'] : [],
        'auth' => is_array($settings['auth'] ?? null) ? $settings['auth'] : [],
        'storage_path' => $storeConfig['storage_path'],
        'storage_warning' => $storeConfig['storage_warning'],
        'required_version' => $requiredVersion !== false && $requiredVersion !== ''
            ? $requiredVersion
            : reflection_git_commit_id($repoRoot),
        'allowed_tasks' => [
            'dummy_task' => 'Placeholder pipeline test task.',
            'render_frame' => 'Render a frame with the configured worker renderer.',
            'compress_archive' => 'Compress a file or directory into a small .tar.xz archive with hardware-aware limits.',
            'invert_image' => 'Invert an image while preserving alpha transparency when possible.',
            'noop' => 'Built-in worker connectivity check.',
            'status' => 'Built-in worker health snapshot.',
            'reload_tasks' => 'Ask a worker to reload its local task registry.',
            'shutdown' => 'Ask a worker to stop after reporting success.',
            'wake_farm' => 'Ask a worker to send Wake-on-LAN packets to configured farm computers.',
        ],
        'stale_after_seconds' => 15 * 60,
    ];
}

function reflection_farm_store(array $config): FarmStore
{
    return new FarmStore($config['storage_path'], $config['runtime_defaults'] ?? []);
}

function reflection_require_master_login(array $config): void
{
    if (defined('REFLECTION_TESTING') || PHP_SAPI === 'cli') {
        return;
    }

    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    if (empty($auth['enabled'])) {
        return;
    }

    $credentials = is_array($config['default_login'] ?? null) ? $config['default_login'] : [];
    $expectedUsername = (string) ($credentials['username'] ?? '');
    $expectedPassword = (string) ($credentials['password'] ?? '');
    if ($expectedUsername === '' || $expectedPassword === '') {
        return;
    }

    $username = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if (hash_equals($expectedUsername, $username) && hash_equals($expectedPassword, $password)) {
        return;
    }

    $realm = preg_replace('/[^\x20-\x7E]/', '', (string) ($auth['realm'] ?? 'Reflection Farm Master'));
    header('WWW-Authenticate: Basic realm="' . str_replace('"', '', $realm) . '"');
    http_response_code(401);
    echo 'Authentication required.' . PHP_EOL;
    exit;
}
