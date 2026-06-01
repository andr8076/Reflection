<?php

declare(strict_types=1);

require_once __DIR__ . '/FarmDefaults.php';

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
        'farm_name' => 'Reflection Farm',
        'worker_access_token' => '',
        'api_token' => '',
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
        'runtime_defaults' => FarmDefaults::runtimeSettings(),
        'allowed_tasks' => FarmDefaults::allowedTasks(),
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

function reflection_worker_access_token_config(array $settings): string
{
    return reflection_env_string('REFLECTION_WORKER_ACCESS_TOKEN')
        ?? reflection_env_string('REFLECTION_API_TOKEN')
        ?? (string) ($settings['worker_access_token'] ?? ($settings['api_token'] ?? ''));
}

function reflection_api_token_config(array $settings): string
{
    return reflection_worker_access_token_config($settings);
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
        'farm_name' => (string) ($settings['farm_name'] ?? 'Reflection Farm'),
        'worker_access_token' => reflection_worker_access_token_config($settings),
        'api_token' => reflection_worker_access_token_config($settings),
        'transfer_auth' => reflection_transfer_auth_config($settings),
        'transfer_server' => reflection_transfer_server_config($settings),
        'storage_path' => $storeConfig['storage_path'],
        'storage_warning' => $storeConfig['storage_warning'],
        'required_version' => $requiredVersion !== false && $requiredVersion !== ''
            ? $requiredVersion
            : reflection_git_commit_id($repoRoot),
        'runtime_defaults' => is_array($settings['runtime_defaults'] ?? null) ? $settings['runtime_defaults'] : [],
        'allowed_tasks' => is_array($settings['allowed_tasks'] ?? null) ? $settings['allowed_tasks'] : [],
        'stale_after_seconds' => (int) ($settings['stale_after_seconds'] ?? (15 * 60)),
    ];
}

function reflection_farm_store(array $config): FarmStore
{
    return new FarmStore($config['storage_path'], $config['runtime_defaults'] ?? []);
}
