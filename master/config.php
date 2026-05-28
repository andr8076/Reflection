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

function reflection_master_config(): array
{
    $repoRoot = dirname(__DIR__);
    $defaultStorage = $repoRoot . DIRECTORY_SEPARATOR . 'master' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'farm_store.json';
    $requiredVersion = getenv('REFLECTION_REQUIRED_VERSION');

    return [
        'storage_path' => getenv('REFLECTION_MASTER_STORE') ?: $defaultStorage,
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
