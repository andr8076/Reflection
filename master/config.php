<?php

declare(strict_types=1);

function reflection_git_commit_id(string $repoRoot): ?string
{
    $headPath = $repoRoot . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'HEAD';
    if (!is_file($headPath)) {
        return null;
    }

    $head = trim((string) file_get_contents($headPath));
    if (!str_starts_with($head, 'ref:')) {
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
        if (str_starts_with($line, '#') || str_starts_with($line, '^')) {
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

function reflection_master_config(): array
{
    $repoRoot = dirname(__DIR__);
    $defaultStorage = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json';
    $fallbackDirectory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'reflection-farm-'
        . substr(hash('sha256', __DIR__), 0, 12);
    $requiredVersion = getenv('REFLECTION_REQUIRED_VERSION');
    $configuredStorage = getenv('REFLECTION_MASTER_STORE');
    $storeConfig = reflection_resolve_master_store(
        $configuredStorage !== false ? $configuredStorage : null,
        $defaultStorage,
        $fallbackDirectory,
    );

    return [
        'storage_path' => $storeConfig['storage_path'],
        'storage_warning' => $storeConfig['storage_warning'],
        'required_version' => $requiredVersion !== false && $requiredVersion !== ''
            ? $requiredVersion
            : reflection_git_commit_id($repoRoot),
        'allowed_tasks' => [
            'dummy_task' => 'Placeholder pipeline test task.',
            'render_frame' => 'Render a frame with the configured worker renderer.',
            'noop' => 'Built-in worker connectivity check.',
            'status' => 'Built-in worker health snapshot.',
            'reload_tasks' => 'Ask a worker to reload its local task registry.',
            'shutdown' => 'Ask a worker to stop after reporting success.',
        ],
        'allowed_source_roots' => ['incoming', 'uploads', 'frames', 'projects'],
        'allowed_delivery_roots' => ['outputs', 'renders', 'reports'],
        'stale_after_seconds' => 15 * 60,
    ];
}
